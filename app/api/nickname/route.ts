import { createHash } from "node:crypto";
import { z } from "zod";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

const games = {
  "mobile-legends": { endpoint: "ml", needsServer: true, numeric: true },
  "free-fire": { endpoint: "ff", needsServer: false, numeric: true },
  "genshin-impact": { endpoint: "gi", needsServer: false, numeric: true },
  valorant: { endpoint: "valo", needsServer: false, numeric: false },
} as const;

const requestSchema = z.object({
  game: z.enum(["mobile-legends", "free-fire", "genshin-impact", "valorant"]),
  userId: z.string().trim().min(4).max(32),
  server: z.string().trim().min(1).max(20).optional(),
});

type GameSlug = keyof typeof games;

type RuntimeEnv = {
  NICKNAME_API_URL?: string;
  NICKNAME_API_KEY?: string;
  JAGOANITEM_API_ID?: string;
  JAGOANITEM_API_KEY?: string;
  JAGOANITEM_API_URL?: string;
  JAGOANITEM_GAME_CODE_MOBILE_LEGENDS?: string;
  JAGOANITEM_GAME_CODE_FREE_FIRE?: string;
  JAGOANITEM_GAME_CODE_GENSHIN_IMPACT?: string;
  JAGOANITEM_GAME_CODE_VALORANT?: string;
};

type ProviderResponse = {
  success?: boolean;
  name?: unknown;
  nickname?: unknown;
  username?: unknown;
  country?: unknown;
  message?: unknown;
  data?: { name?: unknown; nickname?: unknown; username?: unknown };
};

type JagoanItemResponse = {
  status?: boolean;
  result?: boolean;
  msg?: unknown;
  message?: unknown;
  data?: {
    nickname?: unknown;
    name?: unknown;
    username?: unknown;
    country?: unknown;
  };
};

export async function POST(request: Request) {
  try {
    const input = requestSchema.parse(await request.json());
    const game = games[input.game];

    if (game.numeric && !/^\d+$/.test(input.userId)) {
      return Response.json({ error: "User ID harus berupa angka." }, { status: 400 });
    }
    if (game.needsServer && (!input.server || !/^\d+$/.test(input.server))) {
      return Response.json({ error: "Server / Zone ID wajib diisi dengan angka." }, { status: 400 });
    }

    const runtime = getRuntimeEnv<RuntimeEnv>();
    const apiId = runtime.JAGOANITEM_API_ID?.trim();
    const apiKey = runtime.JAGOANITEM_API_KEY?.trim();
    const gameCode = getJagoanItemGameCode(runtime, input.game);

    if (apiId && apiKey && gameCode) {
      return await lookupJagoanItem({
        runtime,
        apiId,
        apiKey,
        gameCode,
        game: input.game,
        userId: input.userId,
        server: input.server,
      });
    }

    return await lookupFallbackProvider({
      runtime,
      game: input.game,
      endpoint: game.endpoint,
      userId: input.userId,
      server: input.server,
    });
  } catch (error) {
    if (error instanceof z.ZodError) {
      return Response.json(
        { error: error.issues[0]?.message ?? "Permintaan pengecekan tidak valid." },
        { status: 400 },
      );
    }

    return Response.json(
      {
        error:
          error instanceof Error
            ? error.message
            : "Layanan verifikasi nickname sedang bermasalah.",
      },
      { status: 502 },
    );
  }
}

async function lookupJagoanItem({
  runtime,
  apiId,
  apiKey,
  gameCode,
  game,
  userId,
  server,
}: {
  runtime: RuntimeEnv;
  apiId: string;
  apiKey: string;
  gameCode: string;
  game: GameSlug;
  userId: string;
  server?: string;
}) {
  const endpoint =
    runtime.JAGOANITEM_API_URL?.trim() ||
    "https://jagoanitem.com/api/get-everything";
  const signature = createHash("md5")
    .update(`${apiId}${apiKey}`)
    .digest("hex");

  const body = new URLSearchParams({
    api_id: apiId,
    api_key: apiKey,
    signature,
    type: "nickname",
    target_id: userId,
    game_code: gameCode,
  });
  if (server) body.set("target_server", server);

  const upstream = await fetch(endpoint, {
    method: "POST",
    headers: {
      accept: "application/json",
      "content-type": "application/x-www-form-urlencoded;charset=UTF-8",
    },
    body,
    signal: AbortSignal.timeout(8_000),
  });

  let data: JagoanItemResponse;
  try {
    data = (await upstream.json()) as JagoanItemResponse;
  } catch {
    throw new Error("JagoanItem mengembalikan respons yang tidak valid.");
  }

  const providerMessage = [data.msg, data.message].find(
    (value): value is string =>
      typeof value === "string" && value.trim().length > 0,
  )?.trim();

  if (!upstream.ok || data.status === false || data.result === false) {
    const lowerMessage = providerMessage?.toLowerCase() || "";

    if (lowerMessage.includes("whitelist")) {
      throw new Error(
        "JagoanItem menolak IP Cloudflare Worker karena belum masuk whitelist. Endpoint perlu dilewatkan melalui VPS relay dengan IP statis.",
      );
    }
    if (lowerMessage.includes("signature")) {
      throw new Error(
        "Signature JagoanItem tidak valid. Periksa API ID dan API Key.",
      );
    }
    if (lowerMessage.includes("api id") || lowerMessage.includes("api key")) {
      throw new Error("API ID atau API Key JagoanItem tidak valid.");
    }

    return Response.json(
      { error: providerMessage || "ID atau Server tidak ditemukan di JagoanItem." },
      { status: upstream.status === 404 ? 404 : 502 },
    );
  }

  const rawName = [
    data.data?.nickname,
    data.data?.name,
    data.data?.username,
  ].find(
    (value): value is string =>
      typeof value === "string" && value.trim().length > 0,
  );

  if (!rawName) {
    return Response.json(
      { error: "Nickname tidak ditemukan pada jawaban JagoanItem." },
      { status: 404 },
    );
  }

  const country =
    typeof data.data?.country === "string" && data.data.country.trim()
      ? data.data.country.trim()
      : null;

  return Response.json({
    nickname: rawName.trim(),
    country,
    game,
    userId,
    server: server ?? null,
    provider: "jagoanitem",
  });
}

async function lookupFallbackProvider({
  runtime,
  game,
  endpoint,
  userId,
  server,
}: {
  runtime: RuntimeEnv;
  game: GameSlug;
  endpoint: string;
  userId: string;
  server?: string;
}) {
  const baseUrl = (
    runtime.NICKNAME_API_URL?.trim() ||
    "https://api.isan.eu.org/nickname"
  ).replace(/\/$/, "");
  const url = new URL(`${baseUrl}/${endpoint}`);
  url.searchParams.set("id", userId);
  if (server) url.searchParams.set("server", server);
  url.searchParams.set("decode", "false");

  const headers = new Headers({ accept: "application/json" });
  if (runtime.NICKNAME_API_KEY) {
    headers.set("authorization", `Bearer ${runtime.NICKNAME_API_KEY}`);
  }

  const upstream = await fetch(url, {
    headers,
    signal: AbortSignal.timeout(8_000),
  });

  if (!upstream.ok) {
    return Response.json(
      { error: "Akun tidak ditemukan atau layanan verifikasi sedang sibuk." },
      { status: upstream.status === 404 ? 404 : 502 },
    );
  }

  const data = (await upstream.json()) as ProviderResponse;
  if (data.success === false) {
    return Response.json(
      {
        error:
          typeof data.message === "string"
            ? data.message
            : "ID atau Server tidak ditemukan.",
      },
      { status: 404 },
    );
  }

  const rawName = [
    data.name,
    data.nickname,
    data.username,
    data.data?.name,
    data.data?.nickname,
    data.data?.username,
  ].find(
    (value): value is string =>
      typeof value === "string" && value.trim().length > 0,
  );

  if (!rawName) {
    return Response.json(
      { error: "Nickname tidak ditemukan pada jawaban penyedia." },
      { status: 404 },
    );
  }

  let nickname = rawName.trim();
  try {
    nickname = decodeURIComponent(nickname);
  } catch {
    // keep provider value
  }

  const country =
    typeof data.country === "string" && data.country.trim()
      ? data.country.trim()
      : null;

  return Response.json({
    nickname,
    country,
    game,
    userId,
    server: server ?? null,
    provider: "fallback",
  });
}

function getJagoanItemGameCode(runtime: RuntimeEnv, game: GameSlug) {
  switch (game) {
    case "mobile-legends":
      return runtime.JAGOANITEM_GAME_CODE_MOBILE_LEGENDS?.trim() || "";
    case "free-fire":
      return runtime.JAGOANITEM_GAME_CODE_FREE_FIRE?.trim() || "";
    case "genshin-impact":
      return runtime.JAGOANITEM_GAME_CODE_GENSHIN_IMPACT?.trim() || "";
    case "valorant":
      return runtime.JAGOANITEM_GAME_CODE_VALORANT?.trim() || "";
  }
}
