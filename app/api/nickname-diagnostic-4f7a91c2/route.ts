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

function summarize(response: Response, body: JsonRecord) {
  const data = record(body.data);
  const error = record(body.error);
  return {
    httpStatus: response.status,
    success: response.ok && body.success !== false,
    errorCode: error.code ?? null,
    errorCategory: error.category ?? null,
    message: error.message ?? body.message ?? null,
    nickname: data.username ?? data.nickname ?? null,
    region: data.region ?? data.country ?? null,
  };
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

  const lookup = (customer_target: string, customer_target_zone: string) =>
    fetch("https://api.melostore.id/api/v1/h2h/check-nickname", {
      method: "POST",
      headers,
      body: JSON.stringify({
        game_code: "mobile-legends",
        customer_target,
        customer_target_zone,
      }),
      signal: AbortSignal.timeout(8000),
    });

  const [profileResponse, targetResponse, docsExampleResponse] = await Promise.all([
    fetch("https://api.melostore.id/api/v1/h2h/profile", { headers, signal: AbortSignal.timeout(8000) }),
    lookup("309412350", "9615"),
    lookup("47486147", "2076"),
  ]);

  const [profileBody, targetBody, docsExampleBody] = await Promise.all([
    readJson(profileResponse),
    readJson(targetResponse),
    readJson(docsExampleResponse),
  ]);
  const profileData = record(profileBody.data);

  return Response.json({
    configured: true,
    profile: {
      httpStatus: profileResponse.status,
      success: profileResponse.ok && profileBody.success !== false,
      sandboxMode: typeof profileData.is_sandbox_mode === "boolean" ? profileData.is_sandbox_mode : null,
    },
    target: summarize(targetResponse, targetBody),
    docsExample: summarize(docsExampleResponse, docsExampleBody),
  }, { headers: { "Cache-Control": "no-store" } });
}
