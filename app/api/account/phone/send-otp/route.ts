import { z } from "zod";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { createWhatsappOtpChallenge } from "@/lib/server/whatsapp-otp";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

const schema = z.object({
  phone: z.string().trim().min(8).max(24),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;

  const customer = await requireCustomerSession(request, { allowUnverifiedPhone: true });
  if (customer instanceof Response) return customer;

  const rate = await allowRequest(request, `customer-whatsapp-otp-send:${customer.id}`, 5, 3600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak permintaan OTP. Coba lagi nanti." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter), "Cache-Control": "no-store" } },
    );
  }

  try {
    const input = schema.parse(await request.json());
    const challenge = await createWhatsappOtpChallenge(customer.id, input.phone);
    return Response.json(challenge, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? "Nomor WhatsApp tidak valid."
      : error instanceof Error
        ? error.message
        : "OTP WhatsApp gagal dikirim.";
    const status = /belum dikonfigurasi|HTTP\s\d+/i.test(message) ? 503 : 400;
    return Response.json({ error: message }, { status, headers: { "Cache-Control": "no-store" } });
  }
}
