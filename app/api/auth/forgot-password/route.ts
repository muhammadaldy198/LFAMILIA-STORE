import { z } from "zod";
import { requestCustomerPasswordReset } from "@/lib/server/password-reset";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { verifyTurnstile } from "@/lib/server/turnstile";

const schema = z.object({
  email: z.string().trim().email().max(150),
  turnstileToken: z.string().max(2048).optional(),
});

const GENERIC_MESSAGE = "Jika email terdaftar, link reset password telah dikirim.";

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "customer-forgot-password", 5, 900);
  if (!rate.allowed) return Response.json({ message: GENERIC_MESSAGE }, { headers: { "Cache-Control": "no-store" } });

  try {
    const input = schema.parse(await request.json());
    if (!await verifyTurnstile(request, input.turnstileToken)) {
      return Response.json({ error: "Verifikasi keamanan gagal. Coba lagi." }, { status: 403, headers: { "Cache-Control": "no-store" } });
    }
    await requestCustomerPasswordReset(input.email);
    return Response.json({ message: GENERIC_MESSAGE }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    if (error instanceof z.ZodError) {
      return Response.json({ error: "Email tidak valid." }, { status: 400, headers: { "Cache-Control": "no-store" } });
    }
    console.error("Password reset request failed:", error);
    return Response.json({ message: GENERIC_MESSAGE }, { headers: { "Cache-Control": "no-store" } });
  }
}
