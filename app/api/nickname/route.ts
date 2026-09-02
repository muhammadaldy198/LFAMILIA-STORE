import { z } from "zod";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

const games = {
  "mobile-legends": { endpoint: "ml", needsServer: true, numeric: true, melostoreCode: "mobile-legends" },
  "free-fire": { endpoint: "ff", needsServer: false, numeric: true, melostoreCode: "free-fire" },
  "genshin-impact": { endpoint: "gi", needsServer: false, numeric: true, melostoreCode: "genshin-impact" },
  valorant: { endpoint: "valo", needsServer: false, numeric: false, melostoreCode: "valorant" },
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
  MELOSTORE_API_KEY?: string;
  MELOSTORE_SECRET_KEY?: string;
  MELOSTORE_API_URL?: string;
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

type MelostoreResponse = {
  success?: boolean;
  message?: unknown;
  error?: unknown;
  data?: {
    game_code?: unknown;
    customer_target?: unknown;
    customer_target_zone?: unknown;
    username?: unknown;
    nickname?: unknown;
    region?: unknown;
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
    const apiKey = runtime.MELOSTORE_API_KEY?.trim();
    const secretKey = runtime.MELOSTORE_SECRET_KEY?.trim();

    if (apiKey && secretKey) {
      return await lookupMelostore({
        runtime,
        apiKey,
        secretKey,
        game: input.game,
        gameCode: game.melostoreCode,
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

async function lookupMelostore({
  runtime,
  apiKey,
  secretKey,
  game,
  gameCode,
  userId,
  server,
}: {
  runtime: RuntimeEnv;
  apiKey: string;
  secretKey: string;
  game: GameSlug;
  gameCode: string;
  userId: string;
  server?: string;
}) {
  const baseUrl = (runtime.MELOSTORE_API_URL?.trim() || "https://api.melostore.id").replace(/\/$/, "");
  const endpoint = `${baseUrl}/api/v1/h2h/check-nickname`;

  const body: {
    game_code: string;
    customer_target: string;
    customer_target_zone?: string;
  } = {
    game_code: gameCode,
    customer_target: userId,
  };
  if (server) body.customer_target_zone = server;

  const upstream = await fetch(endpoint, {
    method: "POST",
    headers: {
      accept: "application/json",
      "content-type": "application/json",
      "X-API-Key": apiKey,
      "X-Secret-Key": secretKey,
    },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(8_000),
  });

  let data: MelostoreResponse;
  try {
    data = (await upstream.json()) as MelostoreResponse;
  } catch {
    throw new Error("Melostore mengembalikan respons yang tidak valid.");
  }

  const providerMessage = [data.message, data.error].find(
    (value): value is string => typeof value === "string" && value.trim().length > 0,
  )?.trim();

  if (!upstream.ok || data.success === false) {
    if (upstream.status === 401 || upstream.status === 403) {
      throw new Error("API Key atau Secret Key Melostore tidak valid atau akses H2H ditolak.");
    }

    return Response.json(
      { error: providerMessage || "ID atau Server tidak ditemukan di Melostore." },
      { status: upstream.status === 404 || upstream.status === 422 ? 404 : 502 },
    );
  }

  const rawName = [data.data?.username, data.data?.nickname].find(
    (value): value is string => typeof value === "string" && value.trim().length > 0,
  );

  if (!rawName) {
    return Response.json(
      { error: "Nickname tidak ditemukan pada jawaban Melostore." },
      { status: 404 },
    );
  }

  const country = [data.data?.region, data.data?.country].find(
    (value): value is string => typeof value === "string" && value.trim().length > 0,
  )?.trim() ?? null;

  return Response.json({
    nickname: rawName.trim(),
    country,
    game,
    userId,
    server: server ?? null,
    provider: "melostore",
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
