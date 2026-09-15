import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

const querySchema = z.object({
  game: z.string().trim().min(2).max(80).regex(/^[a-z0-9-]+$/),
  userId: z.string().trim().min(2).max(80),
  server: z.string().trim().min(1).max(40).optional(),
});

type RuntimeEnv = {
  MELOSTORE_API_KEY?: string;
  MELOSTORE_SECRET_KEY?: string;
  MELOSTORE_API_URL?: string;
};

type JsonRecord = Record<string, unknown>;

const OFFICIAL_ORIGIN = "https://api.melostore.id";

function apiOrigin(value?: string) {
  const configured = value?.trim();
  if (!configured) return OFFICIAL_ORIGIN;
  try {
    const parsed = new URL(configured);
    if (parsed.protocol !== "https:" || parsed.hostname.toLowerCase() !== "api.melostore.id") {
      return OFFICIAL_ORIGIN;
    }
    return parsed.origin;
  } catch {
    return OFFICIAL_ORIGIN;
  }
}

async function readJson(response: Response): Promise<JsonRecord> {
  try {
    const value = await response.json();
    return value && typeof value === "object" && !Array.isArray(value)
      ? value as JsonRecord
      : {};
  } catch {
    return {};
  }
}

function record(value: unknown): JsonRecord {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value as JsonRecord
    : {};
}

function text(value: unknown) {
  return typeof value === "string" && value.trim() ? value.trim() : null;
}

function numberValue(value: unknown) {
  if (typeof value === "number" && Number.isFinite(value)) return value;
  if (typeof value === "string" && /^\d+$/.test(value.trim())) return Number(value.trim());
  return null;
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;

  const url = new URL(request.url);
  const parsed = querySchema.safeParse({
    game: url.searchParams.get("game") ?? "",
    userId: url.searchParams.get("userId") ?? "",
    server: url.searchParams.get("server") || undefined,
  });
  if (!parsed.success) {
    return Response.json(
      { error: parsed.error.issues[0]?.message || "Parameter diagnostik tidak valid." },
      { status: 400, headers: { "Cache-Control": "no-store" } },
    );
  }

  const runtime = getRuntimeEnv<RuntimeEnv>();
  const apiKey = runtime.MELOSTORE_API_KEY?.trim();
  const secretKey = runtime.MELOSTORE_SECRET_KEY?.trim();
  if (!apiKey || !secretKey) {
    return Response.json(
      { error: "Kredensial Melostore belum tersedia di runtime production." },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }

  const origin = apiOrigin(runtime.MELOSTORE_API_URL);
  const headers = {
    accept: "application/json",
    "content-type": "application/json",
    "X-API-Key": apiKey,
    "X-Secret-Key": secretKey,
  };

  let profileResponse: Response;
  let lookupResponse: Response;
  try {
    [profileResponse, lookupResponse] = await Promise.all([
      fetch(`${origin}/api/v1/h2h/profile`, {
        headers,
        signal: AbortSignal.timeout(8_000),
      }),
      fetch(`${origin}/api/v1/h2h/check-nickname`, {
        method: "POST",
        headers,
        body: JSON.stringify({
          game_code: parsed.data.game,
          customer_target: parsed.data.userId,
          ...(parsed.data.server ? { customer_target_zone: parsed.data.server } : {}),
        }),
        signal: AbortSignal.timeout(8_000),
      }),
    ]);
  } catch (error) {
    return Response.json(
      {
        error: "Request diagnostik ke Melostore gagal sebelum menerima respons HTTP.",
        detail: error instanceof Error ? error.message : "unknown_error",
      },
      { status: 502, headers: { "Cache-Control": "no-store" } },
    );
  }

  const [profileBody, lookupBody] = await Promise.all([
    readJson(profileResponse),
    readJson(lookupResponse),
  ]);

  const profileData = record(profileBody.data);
  const tier = record(profileData.tier);
  const lookupData = record(lookupBody.data);
  const lookupError = record(lookupBody.error);

  return Response.json(
    {
      profile: {
        httpStatus: profileResponse.status,
        success: profileResponse.ok && profileBody.success !== false,
        sandboxMode: typeof profileData.is_sandbox_mode === "boolean" ? profileData.is_sandbox_mode : null,
        tierCode: text(tier.code),
        tierName: text(tier.name),
        rateLimit: numberValue(tier.rate_limit),
        message: text(profileBody.message),
      },
      lookup: {
        httpStatus: lookupResponse.status,
        success: lookupResponse.ok && lookupBody.success !== false,
        errorCode: numberValue(lookupError.code),
        errorCategory: text(lookupError.category),
        message: text(lookupError.message) ?? text(lookupBody.message),
        nickname: text(lookupData.username) ?? text(lookupData.nickname),
        region: text(lookupData.region) ?? text(lookupData.country),
      },
      request: {
        game: parsed.data.game,
        userId: parsed.data.userId,
        server: parsed.data.server ?? null,
      },
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
