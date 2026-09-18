import { z } from "zod";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { verifyWhatsappOtpChallenge } from "@/lib/server/whatsapp-otp";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

const schema = z.object({
  challengeId: z.string().uuid(),
  code: z.string().regex(/^[0-9]{6}$/),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;

  const customer = await requireCustomerSession(request, { allowUnverifiedPhone: true });
  if (customer instanceof Response) return customer;

  const rate = await allowRequest(request, `customer-whatsapp-otp-verify:${customer.id}`, 20, 3600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak percobaan OTP. Coba lagi nanti." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter), "Cache-Control": "no-store" } },
    );
  }

  try {
    const input = schema.parse(await request.json());
    const result = await verifyWhatsappOtpChallenge(customer.id, input.challengeId, input.code);
    return Response.json({ ok: true, ...result }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? "Kode OTP tidak valid."
      : error instanceof Error
        ? error.message
        : "Verifikasi OTP gagal.";
    return Response.json({ error: message }, { status: 400, headers: { "Cache-Control": "no-store" } });
  }
}
