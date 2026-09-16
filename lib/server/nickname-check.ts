import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

type RuntimeEnv = {
  KOKINPAY_API_KEY?: string;
};

type KokinpayResponse = {
  status?: unknown;
  message?: unknown;
  data?: {
    nickname?: unknown;
    username?: unknown;
    region?: unknown;
  };
};

type ProductNicknameConfig = {
  nickname_game_code: string | null;
  needs_server: number;
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

const KOKINPAY_API_ORIGIN = "https://api.kokinpay.com";

function nonEmptyString(values: unknown[]) {
  return values.find(
    (value): value is string => typeof value === "string" && value.trim().length > 0,
  )?.trim();
}

function errorMessage(data: KokinpayResponse) {
  return nonEmptyString([data.message]);
}

function validateUserId(userId: string) {
  const value = userId.trim();
  if (value.length < 2) throw new NicknameValidationError("ID akun belum valid.");
  return value;
}

function throwKokinpayError(status: number, data: KokinpayResponse): never {
  const message = errorMessage(data);
  if (status === 400 || status === 404 || data.status === false) {
    throw new NicknameValidationError(
      message || "ID, Server, atau kode game tidak valid.",
    );
  }
  if (status === 401 || status === 403) {
    throw new NicknameServiceError("Layanan verifikasi akun belum terautentikasi dengan benar.");
  }
  throw new NicknameServiceError(message || undefined);
}

/** Calls Kokinpay's documented game nickname endpoint. Never expose api_key to browsers. */
export async function lookupKokinpayNickname(input: {
  apiKey: string;
  gameCode: string;
  userId: string;
  server?: string | null;
}): Promise<NicknameVerification> {
  const body: Record<string, string> = {
    api_key: input.apiKey,
    id: validateUserId(input.userId),
    game_code: input.gameCode.trim(),
  };
  if (input.server?.trim()) body.server = input.server.trim();

  let upstream: Response;
  try {
    upstream = await fetch(`${KOKINPAY_API_ORIGIN}/check-nickname`, {
      method: "POST",
      headers: {
        accept: "application/json",
        "content-type": "application/json",
      },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(8_000),
    });
  } catch {
    throw new NicknameServiceError();
  }

  let data: KokinpayResponse;
  try {
    data = (await upstream.json()) as KokinpayResponse;
  } catch {
    throw new NicknameServiceError();
  }
  if (!upstream.ok || data.status !== true) throwKokinpayError(upstream.status, data);

  const nickname = nonEmptyString([data.data?.nickname, data.data?.username]);
  if (!nickname) {
    throw new NicknameServiceError(
      "Layanan pengecekan tidak mengembalikan nickname. Coba lagi beberapa saat.",
    );
  }
  return {
    supported: true,
    nickname,
    country: nonEmptyString([data.data?.region]) ?? null,
  };
}

export async function verifyNicknameForCheckout(input: {
  productSlug: string;
  userId: string;
  server?: string | null;
}): Promise<NicknameVerification> {
  await ensureLegacyDatabaseColumns();
  const product = await getD1()
    .prepare("SELECT nickname_game_code, needs_server FROM products WHERE slug = ? AND is_active = 1 LIMIT 1")
    .bind(input.productSlug.trim())
    .first<ProductNicknameConfig>();

  const gameCode = product?.nickname_game_code?.trim();
  if (!gameCode) return { supported: false, nickname: null, country: null };

  const server = input.server?.trim() || undefined;
  if (product?.needs_server && !server) {
    throw new NicknameValidationError("Server / Zone ID wajib diisi.");
  }

  const apiKey = getRuntimeEnv<RuntimeEnv>().KOKINPAY_API_KEY?.trim();
  if (!apiKey) {
    throw new NicknameServiceError(
      "Verifikasi akun belum terhubung dengan benar. Checkout sementara tidak dapat dilanjutkan.",
    );
  }

  return lookupKokinpayNickname({
    apiKey,
    gameCode,
    userId: input.userId,
    server,
  });
}
