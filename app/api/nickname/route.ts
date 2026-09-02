import { z } from "zod";
import { getRuntimeEnv, requireRuntimeValue } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

const fallbackGames = {
  "mobile-legends": { endpoint: "ml" },
  "free-fire": { endpoint: "ff" },
  "genshin-impact": { endpoint: "gi" },
  valorant: { endpoint: "valo" },
} as const;

const requestSchema = z.object({
  game: z.string().trim().min(2).max(80).regex(/^[a-z0-9-]+$/, "Kode game tidak valid."),
  userId: z.string().trim().min(2).max(80),
  server: z.string().trim().min(1).max(40).optional(),
});

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
  error?:
    | unknown
    | {
        code?: unknown;
        message?: unknown;
        category?: unknown;
      };
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

    if (
      input.game === "mobile-legends" &&
      (!input.server || !/^\d+$/.test(input.server))
    ) {
      return Response.json(
        { error: "Server / Zone ID wajib diisi dengan angka." },
        { status: 400 },
      );
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
        userId: input.userId,
        server: input.server,
      });
    }

    const fallback = fallbackGames[input.game as keyof typeof fallbackGames];
    if (!fallback) {
      return Response.json(
        { error: "Kredensial Melostore belum tersedia untuk memeriksa game ini." },
        { status: 503 },
      );
    }

    return await lookupFallbackProvider({
      runtime,
      game: input.game,
      endpoint: fallback.endpoint,
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
  userId,
  server,
}: {
  runtime: RuntimeEnv;
  apiKey: string;
  secretKey: string;
  game: string;
  userId: string;
  server?: string;
}) {
  const baseUrl = requireRuntimeValue(runtime.MELOSTORE_API_URL, "MELOSTORE_API_URL").replace(/\/$/, "");
  const endpoint = `${baseUrl}/api/v1/h2h/check-nickname`;

  const body: {
    game_code: string;
    customer_target: string;
    customer_target_zone?: string;
  } = {
    game_code: game,
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

  const errorMessage =
    data.error &&
    typeof data.error === "object" &&
    "message" in data.error &&
    typeof data.error.message === "string"
      ? data.error.message.trim()
      : "";
  const providerMessage =
    typeof data.message === "string" && data.message.trim()
      ? data.message.trim()
      : errorMessage;

  if (!upstream.ok || data.success === false) {
    if (upstream.status === 401 || upstream.status === 403) {
      throw new Error(
        "API Key atau Secret Key Melostore tidak valid atau akses H2H ditolak.",
      );
    }

    return Response.json(
      { error: providerMessage || "ID atau Server tidak ditemukan di Melostore." },
      {
        status:
          upstream.status === 400 ||
          upstream.status === 404 ||
          upstream.status === 422
            ? 404
            : 502,
      },
    );
  }

  const rawName = [data.data?.username, data.data?.nickname].find(
    (value): value is string =>
      typeof value === "string" && value.trim().length > 0,
  );

  if (!rawName) {
    return Response.json(
      { error: "Nickname tidak ditemukan pada jawaban Melostore." },
      { status: 404 },
    );
  }

  const country =
    [data.data?.region, data.data?.country]
      .find(
        (value): value is string =>
          typeof value === "string" && value.trim().length > 0,
      )
      ?.trim() ?? null;

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
  game: string;
  endpoint: string;
  userId: string;
  server?: string;
}) {
  const baseUrl = requireRuntimeValue(runtime.NICKNAME_API_URL, "NICKNAME_API_URL").replace(/\/$/, "");
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
