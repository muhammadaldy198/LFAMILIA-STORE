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
  try { return record(await response.json()); } catch { return {}; }
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

  const lookup = (payload: Record<string, string>) => fetch("https://api.melostore.id/api/v1/h2h/check-nickname", {
    method: "POST",
    headers,
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(8000),
  });

  const [targetMinimalResponse, targetServerCodeResponse, docsExactResponse] = await Promise.all([
    lookup({ game_code: "mobile-legends", customer_target: "309412350", customer_target_zone: "9615" }),
    lookup({ game_code: "mobile-legends", customer_target: "309412350", customer_target_zone: "9615", server_code: "S1" }),
    lookup({ game_code: "mobile-legends", customer_target: "47486147", customer_target_zone: "2076", server_code: "S1", sku_code: "ml-id-ft150" }),
  ]);

  const [targetMinimalBody, targetServerCodeBody, docsExactBody] = await Promise.all([
    readJson(targetMinimalResponse), readJson(targetServerCodeResponse), readJson(docsExactResponse),
  ]);

  return Response.json({
    configured: true,
    targetMinimal: summarize(targetMinimalResponse, targetMinimalBody),
    targetWithServerCode: summarize(targetServerCodeResponse, targetServerCodeBody),
    docsExact: summarize(docsExactResponse, docsExactBody),
  }, { headers: { "Cache-Control": "no-store" } });
}
