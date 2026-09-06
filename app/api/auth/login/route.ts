import { z } from "zod";
import { customerSessionCookie, loginCustomer } from "@/lib/server/customer-auth";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { verifyTurnstile } from "@/lib/server/turnstile";

const schema = z.object({
  email: z.string().trim().email().max(150),
  password: z.string().min(8).max(72),
  turnstileToken: z.string().max(2048).optional(),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "customer-login", 8);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak percobaan. Coba lagi beberapa menit." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  try {
    const input = schema.parse(await request.json());
    if (!await verifyTurnstile(request, input.turnstileToken)) {
      return Response.json({ error: "Verifikasi keamanan gagal. Coba lagi." }, { status: 403 });
    }
    const session = await loginCustomer(input.email, input.password);
    return Response.json({ customer: session.customer }, { headers: { "Set-Cookie": customerSessionCookie(session.token, session.expiresAt) } });
  } catch (error) {
    const message = error instanceof z.ZodError ? "Email atau password tidak valid." : error instanceof Error ? error.message : "Login gagal.";
    return Response.json({ error: message }, { status: 400 });
  }
}
