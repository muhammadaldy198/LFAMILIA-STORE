import { hashHex, hmacHex, safeEqual } from "@/lib/server/crypto";
import { applyProviderWebhook } from "@/lib/server/orders";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

type RuntimeEnv = { DIGIFLAZZ_WEBHOOK_SECRET?: string };
type Payload = { data?: { ref_id?: string; status?: string; message?: string; sn?: string } };

function mapStatus(status?: string) {
  if (status?.toLowerCase() === "sukses") return "success" as const;
  if (status?.toLowerCase() === "gagal") return "failed" as const;
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
  const data = payload.data;
  if (!data?.ref_id) return Response.json({ error: "Ref ID DigiFlazz tidak ada." }, { status: 400 });
  await applyProviderWebhook({
    providerCode: "digiflazz",
    providerRefId: data.ref_id,
    eventId: `digiflazz-${hashHex("sha256", rawBody)}`,
    result: {
      externalId: data.ref_id,
      status: mapStatus(data.status),
      message: data.message || `Status DigiFlazz: ${data.status ?? "pending"}`,
      serialNumber: data.sn || null,
      raw: payload,
    },
  });
  return Response.json({ ok: true });
}
