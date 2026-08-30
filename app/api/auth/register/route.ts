import { z } from "zod";
import { customerSessionCookie, registerCustomer } from "@/lib/server/customer-auth";

const schema = z.object({
  name: z.string().trim().min(2, "Nama minimal 2 karakter.").max(80),
  email: z.string().trim().email("Email tidak valid.").max(150),
  phone: z.string().trim().regex(/^\+?[0-9]{8,16}$/, "Nomor WhatsApp tidak valid."),
  password: z.string().min(8, "Password minimal 8 karakter.").max(72),
});

export async function POST(request: Request) {
  try {
    const input = schema.parse(await request.json());
    const session = await registerCustomer(input);
    return Response.json({ customer: session.customer }, { status: 201, headers: { "Set-Cookie": customerSessionCookie(session.token, session.expiresAt) } });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Pendaftaran gagal.";
    return Response.json({ error: message }, { status: 400 });
  }
}
