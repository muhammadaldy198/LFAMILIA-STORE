import { getD1 } from "@/db";
import { ensureKokinpayNicknameGameCodeBackfill } from "@/lib/server/nickname-config";
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
    country?: unknown;
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
const KOKINPAY_GAME_NICKNAME_PATH = "/v1/check-nickname";
const KOKINPAY_MLBB_REGION_PATH = "/v1/check-region";
const MLBB_GAME_CODE = "mobile-legends";

function nonEmptyString(values: unknown[]) {
  return values.find(
    (value): value is string => typeof value === "string" && value.trim().length > 0,
  )?.trim();
}

function validateUserId(userId: string) {
  const value = userId.trim();
  if (value.length < 2) throw new NicknameValidationError("ID akun belum valid.");
  return value;
}

function throwKokinpayError(status: number): never {
  if (status === 401 || status === 403) {
    throw new NicknameServiceError(
      "Layanan verifikasi akun belum terautentikasi dengan benar.",
    );
  }
  if (status === 400 || status === 404) {
    throw new NicknameValidationError(
      "ID, Server, atau kode game tidak valid.",
    );
  }
  throw new NicknameServiceError();
}

async function postKokinpay(
  apiKey: string,
  path: typeof KOKINPAY_GAME_NICKNAME_PATH | typeof KOKINPAY_MLBB_REGION_PATH,
  body: Record<string, string>,
) {
  let upstream: Response;
  try {
    upstream = await fetch(`${KOKINPAY_API_ORIGIN}${path}`, {
      method: "POST",
      headers: {
        accept: "application/json",
        "content-type": "application/json",
      },
      body: JSON.stringify({ api_key: apiKey, ...body }),
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
  if (!upstream.ok || data.status !== true) throwKokinpayError(upstream.status);
  return data;
}

/**
 * Calls KokinPay's active game nickname API. Mobile Legends is special:
 * it must pass both /v1/check-nickname and /v1/check-region before checkout may continue.
 * The API key is server-only and is never exposed to browsers.
 */
export async function lookupKokinpayNickname(input: {
  apiKey: string;
  gameCode: string;
  userId: string;
  server?: string | null;
}): Promise<NicknameVerification> {
  const gameCode = input.gameCode.trim();
  const userId = validateUserId(input.userId);
  const server = input.server?.trim() || undefined;

  if (gameCode === MLBB_GAME_CODE && !server) {
    throw new NicknameValidationError("Server / Zone ID wajib diisi untuk Mobile Legends.");
  }

  const nicknameRequest = postKokinpay(input.apiKey, KOKINPAY_GAME_NICKNAME_PATH, {
    id: userId,
    game_code: gameCode,
    ...(server ? { server } : {}),
  });

  if (gameCode === MLBB_GAME_CODE) {
    const [nicknameData, regionData] = await Promise.all([
      nicknameRequest,
      postKokinpay(input.apiKey, KOKINPAY_MLBB_REGION_PATH, {
        id: userId,
        server: server!,
      }),
    ]);
    const nickname = nonEmptyString([nicknameData.data?.nickname, nicknameData.data?.username]);
    if (!nickname) {
      throw new NicknameServiceError(
        "Layanan pengecekan tidak mengembalikan nickname Mobile Legends.",
      );
    }
    const region = nonEmptyString([regionData.data?.region, regionData.data?.country]);
    if (!region) {
      throw new NicknameServiceError(
        "Layanan pengecekan tidak mengembalikan region Mobile Legends.",
      );
    }
    return { supported: true, nickname, country: region };
  }

  const data = await nicknameRequest;
  const nickname = nonEmptyString([data.data?.nickname, data.data?.username]);
  if (!nickname) {
    throw new NicknameServiceError(
      "Layanan pengecekan tidak mengembalikan nickname. Coba lagi beberapa saat.",
    );
  }
  return {
    supported: true,
    nickname,
    country: nonEmptyString([data.data?.region, data.data?.country]) ?? null,
  };
}

export async function verifyNicknameForCheckout(input: {
  productSlug: string;
  userId: string;
  server?: string | null;
}): Promise<NicknameVerification> {
  await ensureKokinpayNicknameGameCodeBackfill();
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
