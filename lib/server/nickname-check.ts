import { getNicknamePolicy } from "@/lib/nickname-policy";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

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
  data?: {
    username?: unknown;
    nickname?: unknown;
    region?: unknown;
    country?: unknown;
  };
};

export type NicknameVerification = {
  supported: boolean;
  nickname: string | null;
  country: string | null;
};

export class NicknameValidationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "NicknameValidationError";
  }
}

export class NicknameServiceError extends Error {
  constructor(message = "Verifikasi akun sedang tidak tersedia. Coba lagi beberapa saat.") {
    super(message);
    this.name = "NicknameServiceError";
  }
}

function nonEmptyString(values: unknown[]) {
  return values.find(
    (value): value is string =>
      typeof value === "string" && value.trim().length > 0,
  )?.trim();
}

function validateTarget(productSlug: string, userId: string, server?: string) {
  const cleanUserId = userId.trim();
  const cleanServer = server?.trim();
  if (cleanUserId.length < 2) {
    throw new NicknameValidationError("ID akun belum valid.");
  }
  const policy = getNicknamePolicy(productSlug);
  if (policy.needsServer && (!cleanServer || !/^\d+$/.test(cleanServer))) {
    throw new NicknameValidationError("Server / Zone ID wajib diisi dengan angka.");
  }
  return { policy, userId: cleanUserId, server: cleanServer };
}

export async function verifyNicknameForCheckout(input: {
  productSlug: string;
  userId: string;
  server?: string | null;
}): Promise<NicknameVerification> {
  const target = validateTarget(
    input.productSlug,
    input.userId,
    input.server ?? undefined,
  );
  if (!target.policy.supported) {
    return { supported: false, nickname: null, country: null };
  }

  const runtime = getRuntimeEnv<RuntimeEnv>();
  const apiKey = runtime.MELOSTORE_API_KEY?.trim();
  const secretKey = runtime.MELOSTORE_SECRET_KEY?.trim();
  if (apiKey && secretKey && runtime.MELOSTORE_API_URL?.trim()) {
    return lookupMelostore({
      runtime,
      apiKey,
      secretKey,
      game: input.productSlug,
      userId: target.userId,
      server: target.server,
    });
  }

  if (target.policy.fallbackEndpoint && runtime.NICKNAME_API_URL?.trim()) {
    return lookupFallback({
      runtime,
      endpoint: target.policy.fallbackEndpoint,
      userId: target.userId,
      server: target.server,
    });
  }

  throw new NicknameServiceError(
    "Verifikasi akun untuk game ini belum siap. Checkout sementara tidak dapat dilanjutkan.",
  );
}

async function lookupMelostore(input: {
  runtime: RuntimeEnv;
  apiKey: string;
  secretKey: string;
  game: string;
  userId: string;
  server?: string;
}): Promise<NicknameVerification> {
  const endpoint = `${input.runtime.MELOSTORE_API_URL!.trim().replace(/\/$/, "")}/api/v1/h2h/check-nickname`;
  const body: Record<string, string> = {
    game_code: input.game,
    customer_target: input.userId,
  };
  if (input.server) body.customer_target_zone = input.server;

  let upstream: Response;
  try {
    upstream = await fetch(endpoint, {
      method: "POST",
      headers: {
        accept: "application/json",
        "content-type": "application/json",
        "X-API-Key": input.apiKey,
        "X-Secret-Key": input.secretKey,
      },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(8_000),
    });
  } catch {
    throw new NicknameServiceError();
  }

  let data: MelostoreResponse;
  try {
    data = (await upstream.json()) as MelostoreResponse;
  } catch {
    throw new NicknameServiceError();
  }

  if (!upstream.ok || data.success === false) {
    if ([400, 404, 422].includes(upstream.status)) {
      throw new NicknameValidationError("ID atau Server tidak ditemukan.");
    }
    throw new NicknameServiceError();
  }

  const nickname = nonEmptyString([data.data?.username, data.data?.nickname]);
  if (!nickname) {
    throw new NicknameValidationError("Nickname tidak ditemukan.");
  }
  const country = nonEmptyString([data.data?.region, data.data?.country]) ?? null;
  return { supported: true, nickname, country };
}

async function lookupFallback(input: {
  runtime: RuntimeEnv;
  endpoint: string;
  userId: string;
  server?: string;
}): Promise<NicknameVerification> {
  const baseUrl = input.runtime.NICKNAME_API_URL!.trim().replace(/\/$/, "");
  const url = new URL(`${baseUrl}/${input.endpoint}`);
  url.searchParams.set("id", input.userId);
  if (input.server) url.searchParams.set("server", input.server);
  url.searchParams.set("decode", "false");

  const headers = new Headers({ accept: "application/json" });
  if (input.runtime.NICKNAME_API_KEY?.trim()) {
    headers.set("authorization", `Bearer ${input.runtime.NICKNAME_API_KEY.trim()}`);
  }

  let upstream: Response;
  try {
    upstream = await fetch(url, {
      headers,
      signal: AbortSignal.timeout(8_000),
    });
  } catch {
    throw new NicknameServiceError();
  }
  if (!upstream.ok) {
    if ([400, 404, 422].includes(upstream.status)) {
      throw new NicknameValidationError("ID atau Server tidak ditemukan.");
    }
    throw new NicknameServiceError();
  }

  let data: ProviderResponse;
  try {
    data = (await upstream.json()) as ProviderResponse;
  } catch {
    throw new NicknameServiceError();
  }
  if (data.success === false) {
    throw new NicknameValidationError("ID atau Server tidak ditemukan.");
  }

  const rawNickname = nonEmptyString([
    data.name,
    data.nickname,
    data.username,
    data.data?.name,
    data.data?.nickname,
    data.data?.username,
  ]);
  if (!rawNickname) throw new NicknameValidationError("Nickname tidak ditemukan.");

  let nickname = rawNickname;
  try {
    nickname = decodeURIComponent(rawNickname);
  } catch {
    // Nilai upstream sudah berupa teks biasa.
  }
  const country =
    typeof data.country === "string" && data.country.trim()
      ? data.country.trim()
      : null;
  return { supported: true, nickname, country };
}
