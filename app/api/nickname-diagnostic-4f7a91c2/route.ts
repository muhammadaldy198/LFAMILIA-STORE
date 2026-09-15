import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

type RuntimeEnv = {
  MELOSTORE_API_KEY?: string;
  MELOSTORE_SECRET_KEY?: string;
};

type JsonRecord = Record<string, unknown>;

function record(value: unknown): JsonRecord {
  return value && typeof value === "object" && !Array.isArray(value) ? value as JsonRecord : {};
}

async function readJson(response: Response): Promise<JsonRecord> {
  try {
    return record(await response.json());
  } catch {
    return {};
  }
}

export async function GET() {
  const runtime = getRuntimeEnv<RuntimeEnv>();
  const apiKey = runtime.MELOSTORE_API_KEY?.trim();
  const secretKey = runtime.MELOSTORE_SECRET_KEY?.trim();
  if (!apiKey || !secretKey) {
    return Response.json({ configured: false }, { status: 503, headers: { "Cache-Control": "no-store" } });
  }

  const headers = {
    accept: "application/json",
    "content-type": "application/json",
    "X-API-Key": apiKey,
    "X-Secret-Key": secretKey,
  };

  const [profileResponse, lookupResponse] = await Promise.all([
    fetch("https://api.melostore.id/api/v1/h2h/profile", { headers, signal: AbortSignal.timeout(8000) }),
    fetch("https://api.melostore.id/api/v1/h2h/check-nickname", {
      method: "POST",
      headers,
      body: JSON.stringify({
        game_code: "mobile-legends",
        customer_target: "309412350",
        customer_target_zone: "9615",
      }),
      signal: AbortSignal.timeout(8000),
    }),
  ]);

  const [profileBody, lookupBody] = await Promise.all([readJson(profileResponse), readJson(lookupResponse)]);
  const profileData = record(profileBody.data);
  const lookupData = record(lookupBody.data);
  const lookupError = record(lookupBody.error);

  return Response.json({
    configured: true,
    profile: {
      httpStatus: profileResponse.status,
      success: profileResponse.ok && profileBody.success !== false,
      sandboxMode: typeof profileData.is_sandbox_mode === "boolean" ? profileData.is_sandbox_mode : null,
    },
    lookup: {
      httpStatus: lookupResponse.status,
      success: lookupResponse.ok && lookupBody.success !== false,
      errorCode: lookupError.code ?? null,
      errorCategory: lookupError.category ?? null,
      message: lookupError.message ?? lookupBody.message ?? null,
      nickname: lookupData.username ?? lookupData.nickname ?? null,
      region: lookupData.region ?? lookupData.country ?? null,
    },
  }, { headers: { "Cache-Control": "no-store" } });
}
