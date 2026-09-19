import { hashHex, hmacHex, safeEqual } from "@/lib/server/crypto";
import { applyProviderWebhook } from "@/lib/server/orders";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import { notifyOrderFulfillmentSuccessByProviderRef } from "@/lib/server/transaction-notifications";

export const dynamic = "force-dynamic";

type RuntimeEnv = { DIGIFLAZZ_WEBHOOK_SECRET?: string };
type Payload = {
  data?: { ref_id?: string; status?: string; message?: string; sn?: string };
  sed?: string;
  hook_id?: number | string;
  hook?: Record<string, unknown>;
};

function mapStatus(normalizedStatus: string) {
  if (normalizedStatus === "sukses") return "success" as const;
  if (normalizedStatus === "gagal") return "failed" as const;
  return "processing" as const;
}

export async function POST(request: Request) {
  const secret = getRuntimeEnv<RuntimeEnv>().DIGIFLAZZ_WEBHOOK_SECRET?.trim();
  if (!secret) return Response.json({ error: "Secret webhook DigiFlazz belum dikonfigurasi." }, { status: 503 });

  const rawBody = await request.text();
  const expected = `sha1=${hmacHex("sha1", secret, rawBody)}`;
  if (!safeEqual(request.headers.get("x-hub-signature"), expected)) {
    return Response.json({ error: "Signature webhook tidak valid." }, { status: 401 });
  }

  let payload: Payload;
  try {
    payload = JSON.parse(rawBody) as Payload;
  } catch {
    return Response.json({ error: "Payload webhook tidak valid." }, { status: 400 });
  }

  const event = request.headers.get("x-digiflazz-event")?.trim().toLowerCase();
  const isPing = event === "ping" || (!payload.data && payload.hook_id != null && payload.hook != null);
  if (isPing) {
    return Response.json({ ok: true, event: "ping" });
  }

  const data = payload.data;
  if (!data?.ref_id) return Response.json({ error: "Ref ID DigiFlazz tidak ada." }, { status: 400 });
  const normalizedStatus = data.status?.trim().toLowerCase() ?? "";
  const status = mapStatus(normalizedStatus);
  // hook_id identifies the webhook configuration and is reused across orders.
  // Build idempotency from the same normalized transaction semantics used for state mapping.
  const semanticEvent = JSON.stringify({
    refId: data.ref_id,
    status: normalizedStatus,
    message: data.message?.trim() ?? "",
    serialNumber: data.sn?.trim() ?? "",
  });
  const eventId = `digiflazz-event-${hashHex("sha256", semanticEvent)}`;

  await applyProviderWebhook({
    providerCode: "digiflazz",
    providerRefId: data.ref_id,
    eventId,
    result: {
      externalId: data.ref_id,
      status,
      message: data.message || `Status DigiFlazz: ${data.status ?? "pending"}`,
      serialNumber: data.sn || null,
      raw: payload,
    },
  });
  if (status === "success") {
    await notifyOrderFulfillmentSuccessByProviderRef("digiflazz", data.ref_id).catch(
      (error) => console.error("Notifikasi pesanan selesai DigiFlazz gagal:", error),
    );
  }
  return Response.json({ ok: true });
}
