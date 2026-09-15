import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

type RuntimeEnv = {
  MELOSTORE_API_KEY?: string;
  MELOSTORE_SECRET_KEY?: string;
  NICKNAME_API_KEY?: string;
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
  const nicknameApiKey = runtime.NICKNAME_API_KEY?.trim();
  if (!apiKey || !secretKey) {
    return Response.json({ configured: false, nicknameApiKeyConfigured: Boolean(nicknameApiKey) }, { status: 503, headers: { "Cache-Control": "no-store" } });
  }

  const lookup = (key: string, payload: Record<string, string>) => fetch("https://api.melostore.id/api/v1/h2h/check-nickname", {
    method: "POST",
    headers: {
      accept: "application/json",
      "content-type": "application/json",
      "X-API-Key": key,
      "X-Secret-Key": secretKey,
    },
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(8000),
  });

  const target = { game_code: "mobile-legends", customer_target: "309412350", customer_target_zone: "9615" };
  const docsExact = { game_code: "mobile-legends", customer_target: "47486147", customer_target_zone: "2076", server_code: "S1", sku_code: "ml-id-ft150" };

  const requests: Array<Promise<Response>> = [
    lookup(apiKey, target),
    lookup(apiKey, { ...target, server_code: "S1" }),
    lookup(apiKey, docsExact),
  ];
  if (nicknameApiKey) {
    requests.push(lookup(nicknameApiKey, target));
    requests.push(lookup(nicknameApiKey, docsExact));
  }

  const responses = await Promise.all(requests);
  const bodies = await Promise.all(responses.map(readJson));

  return Response.json({
    configured: true,
    nicknameApiKeyConfigured: Boolean(nicknameApiKey),
    primaryKey: {
      targetMinimal: summarize(responses[0], bodies[0]),
      targetWithServerCode: summarize(responses[1], bodies[1]),
      docsExact: summarize(responses[2], bodies[2]),
    },
    dedicatedNicknameKey: nicknameApiKey ? {
      targetMinimal: summarize(responses[3], bodies[3]),
      docsExact: summarize(responses[4], bodies[4]),
    } : null,
  }, { headers: { "Cache-Control": "no-store" } });
}
