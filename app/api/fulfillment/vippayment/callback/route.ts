import { hashHex, safeEqual } from "@/lib/server/crypto";
import { applyProviderWebhook } from "@/lib/server/orders";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import { notifyOrderFulfillmentSuccessByProviderRef } from "@/lib/server/transaction-notifications";

export const dynamic = "force-dynamic";

type RuntimeEnv = { VIPPAYMENT_API_ID?: string; VIPPAYMENT_API_KEY?: string };
type Payload = { data?: { trxid?: string; status?: string; note?: string } };

function mapStatus(status?: string) {
  if (status?.toLowerCase() === "success") return "success" as const;
  if (status?.toLowerCase() === "error") return "failed" as const;
  return "processing" as const;
}

export async function POST(request: Request) {
  const runtime = getRuntimeEnv<RuntimeEnv>();
  const apiId = runtime.VIPPAYMENT_API_ID?.trim();
  const apiKey = runtime.VIPPAYMENT_API_KEY?.trim();
  if (!apiId || !apiKey) return Response.json({ error: "Secret VIPayment belum dikonfigurasi." }, { status: 503 });
  const rawBody = await request.text();
  const expected = hashHex("md5", `${apiId}${apiKey}`);
  if (!safeEqual(request.headers.get("x-client-signature"), expected)) {
    return Response.json({ error: "Signature webhook tidak valid." }, { status: 401 });
  }
  let payload: Payload;
  try {
    payload = JSON.parse(rawBody) as Payload;
  } catch {
    return Response.json({ error: "Payload webhook tidak valid." }, { status: 400 });
  }
  const data = payload.data;
  if (!data?.trxid) return Response.json({ error: "Transaction ID VIPayment tidak ada." }, { status: 400 });
  const status = mapStatus(data.status);
  await applyProviderWebhook({
    providerCode: "vippayment",
    providerRefId: data.trxid,
    eventId: `vippayment-${hashHex("sha256", rawBody)}`,
    result: {
      externalId: data.trxid,
      status,
      message: data.note || `Status VIPayment: ${data.status ?? "waiting"}`,
      serialNumber: null,
      raw: payload,
    },
  });
  if (status === "success") {
    await notifyOrderFulfillmentSuccessByProviderRef("vippayment", data.trxid).catch(
      (error) => console.error("Notifikasi pesanan selesai VIPayment gagal:", error),
    );
  }
  return Response.json({ ok: true });
}
