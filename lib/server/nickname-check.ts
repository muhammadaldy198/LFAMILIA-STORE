import { getNicknamePolicy } from "@/lib/nickname-policy";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import {
  isProviderRelayConfigured,
  providerRelayRequest,
} from "@/lib/server/provider-relay";

type RuntimeEnv = {
  MELOSTORE_API_KEY?: string;
  MELOSTORE_SECRET_KEY?: string;
  MELOSTORE_API_URL?: string;
};

type MelostoreResponse = {
  success?: boolean;
  message?: unknown;
  error?: {
    code?: unknown;
    message?: unknown;
    category?: unknown;
  };
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

const OFFICIAL_MELOSTORE_API_ORIGIN = "https://api.melostore.id";

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

function melostoreApiBase(configured?: string) {
  const value = configured?.trim();
  if (!value) return OFFICIAL_MELOSTORE_API_ORIGIN;

  try {
    const parsed = new URL(value);
    if (parsed.protocol !== "https:" || parsed.hostname.toLowerCase() !== "api.melostore.id") {
      return OFFICIAL_MELOSTORE_API_ORIGIN;
    }
    return value;
  } catch {
    return OFFICIAL_MELOSTORE_API_ORIGIN;
  }
}

function melostoreNicknameEndpoint(baseUrl: string) {
  const base = baseUrl.trim().replace(/\/+$/, "");
  if (/\/api\/v1\/h2h\/check-nickname$/i.test(base)) return base;
  if (/\/api\/v1\/h2h$/i.test(base)) return `${base}/check-nickname`;
  return `${base}/api/v1/h2h/check-nickname`;
}

function melostoreErrorInfo(data: MelostoreResponse) {
  const category = typeof data.error?.category === "string"
    ? data.error.category.trim().toLowerCase()
    : "";
  const rawCode = data.error?.code;
  const code = typeof rawCode === "number"
    ? rawCode
    : typeof rawCode === "string" && /^\d+$/.test(rawCode.trim())
      ? Number(rawCode.trim())
      : null;
  const message = nonEmptyString([data.error?.message, data.message]);
  return { category, code, message };
}

function throwMelostoreError(status: number, data: MelostoreResponse): never {
  const { category, code, message } = melostoreErrorInfo(data);

  if (category === "not_found" || code === 4001) {
    throw new NicknameValidationError("ID atau Server tidak ditemukan.");
  }

  if (category === "validation" || code === 4006) {
    throw new NicknameValidationError(
      message || "Format ID atau Server tidak valid untuk game ini.",
    );
  }

  if (category === "restricted" || code === 4002) {
    throw new NicknameValidationError(
      "Akun ditemukan, tetapi tidak memenuhi syarat layanan untuk pengecekan ini.",
    );
  }

  if (
    ["unavailable", "limit_reached", "server", "unknown", "maintenance"].includes(category) ||
    [4003, 4004, 4007, 4008, 4009].includes(code ?? -1)
  ) {
    throw new NicknameServiceError(
      category === "maintenance"
        ? "Verifikasi nickname untuk game ini sedang maintenance. Coba lagi nanti."
        : "Verifikasi akun sedang tidak tersedia dari layanan pengecekan. Coba lagi beberapa saat.",
    );
  }

  if (status === 401 || status === 403) {
    throw new NicknameServiceError(
      "Layanan verifikasi akun belum terautentikasi dengan benar.",
    );
  }

  if (status === 404 && !category && code === null) {
    throw new NicknameServiceError(
      "Layanan verifikasi akun belum terhubung dengan benar. Coba lagi beberapa saat.",
    );
  }

  if (status === 400 || status === 422) {
    throw new NicknameValidationError(
      message || "Data akun belum dapat diverifikasi. Periksa kembali ID dan Server.",
    );
  }

  throw new NicknameServiceError();
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
  if (!apiKey || !secretKey) {
    throw new NicknameServiceError(
      "Verifikasi akun belum terhubung dengan benar. Checkout sementara tidak dapat dilanjutkan.",
    );
  }

  if (!isProviderRelayConfigured("melostore")) {
    throw new NicknameServiceError(
      "Relay IP statis untuk verifikasi akun belum dikonfigurasi. Checkout sementara tidak dapat dilanjutkan.",
    );
  }

  return lookupMelostore({
    apiBase: melostoreApiBase(runtime.MELOSTORE_API_URL),
    apiKey,
    secretKey,
    game: input.productSlug,
    userId: target.userId,
    server: target.server,
  });
}

async function lookupMelostore(input: {
  apiBase: string;
  apiKey: string;
  secretKey: string;
  game: string;
  userId: string;
  server?: string;
}): Promise<NicknameVerification> {
  const endpoint = melostoreNicknameEndpoint(input.apiBase);
  const body: Record<string, string> = {
    game_code: input.game,
    customer_target: input.userId,
  };
  if (input.server) body.customer_target_zone = input.server;

  const headers = {
    accept: "application/json",
    "content-type": "application/json",
    "X-API-Key": input.apiKey,
    "X-Secret-Key": input.secretKey,
  };
  const relayed = providerRelayRequest(endpoint, headers, {
    provider: "melostore",
    environment: "production",
  });

  let upstream: Response;
  try {
    upstream = await fetch(relayed.url, {
      method: "POST",
      headers: relayed.headers,
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
    throwMelostoreError(upstream.status, data);
  }

  const nickname = nonEmptyString([data.data?.username, data.data?.nickname]);
  if (!nickname) {
    throw new NicknameServiceError(
      "Layanan pengecekan tidak mengembalikan nickname. Coba lagi beberapa saat.",
    );
  }
  const country = nonEmptyString([data.data?.region, data.data?.country]) ?? null;
  return { supported: true, nickname, country };
}
