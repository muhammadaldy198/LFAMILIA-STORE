import { z } from "zod";
import { customerSessionCookie, loginCustomer } from "@/lib/server/customer-auth";

const schema = z.object({
  email: z.string().trim().email().max(150),
  password: z.string().min(8).max(72),
});

export async function POST(request: Request) {
  try {
    const input = schema.parse(await request.json());
    const session = await loginCustomer(input.email, input.password);
    return Response.json({ customer: session.customer }, { headers: { "Set-Cookie": customerSessionCookie(session.token, session.expiresAt) } });
  } catch (error) {
    const message = error instanceof z.ZodError ? "Email atau password tidak valid." : error instanceof Error ? error.message : "Login gagal.";
    return Response.json({ error: message }, { status: 400 });
  }
}
