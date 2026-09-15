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

  const headersFor = (key: string) => ({
    accept: "application/json",
    "content-type": "application/json",
    "X-API-Key": key,
    "X-Secret-Key": secretKey,
  });
  const lookup = (key: string, payload: Record<string, string>) => fetch("https://api.melostore.id/api/v1/h2h/check-nickname", {
    method: "POST",
    headers: headersFor(key),
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(8000),
  });

  const target = { game_code: "mobile-legends", customer_target: "309412350", customer_target_zone: "9615" };
  const docsMl = { game_code: "mobile-legends", customer_target: "47486147", customer_target_zone: "2076" };
  const docsMlExact = { ...docsMl, server_code: "S1", sku_code: "ml-id-ft150" };
  const docsFf = { game_code: "free-fire", customer_target: "510380815" };

  const requests: Array<Promise<Response>> = [
    fetch("https://api.melostore.id/api/v1/h2h/profile", { headers: headersFor(apiKey), signal: AbortSignal.timeout(8000) }),
    lookup(apiKey, target),
    lookup(apiKey, { ...target, server_code: "S1" }),
    lookup(apiKey, docsMl),
    lookup(apiKey, docsMlExact),
    lookup(apiKey, docsFf),
  ];
  if (nicknameApiKey) {
    requests.push(lookup(nicknameApiKey, target));
    requests.push(lookup(nicknameApiKey, docsMl));
    requests.push(lookup(nicknameApiKey, docsFf));
  }

  const responses = await Promise.all(requests);
  const bodies = await Promise.all(responses.map(readJson));

  return Response.json({
    configured: true,
    nicknameApiKeyConfigured: Boolean(nicknameApiKey),
    profile: summarize(responses[0], bodies[0]),
    primaryKey: {
      targetMinimal: summarize(responses[1], bodies[1]),
      targetWithServerCode: summarize(responses[2], bodies[2]),
      docsMlMinimal: summarize(responses[3], bodies[3]),
      docsMlExact: summarize(responses[4], bodies[4]),
      docsFreeFire: summarize(responses[5], bodies[5]),
    },
    dedicatedNicknameKey: nicknameApiKey ? {
      targetMinimal: summarize(responses[6], bodies[6]),
      docsMlMinimal: summarize(responses[7], bodies[7]),
      docsFreeFire: summarize(responses[8], bodies[8]),
    } : null,
  }, { headers: { "Cache-Control": "no-store" } });
}
