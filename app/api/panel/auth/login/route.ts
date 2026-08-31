import { z } from "zod";
import { adminSessionCookie, isValidAdminId, loginAdmin } from "@/lib/server/admin-auth";

const schema = z.object({
  username: z.string().trim().min(3).max(32).refine(isValidAdminId),
  password: z.string().min(10).max(72),
});

export async function POST(request: Request) {
  try {
    const input = schema.parse(await request.json());
    const session = await loginAdmin(input.username, input.password);
    return Response.json({ admin: session.admin }, {
      headers: {
        "Cache-Control": "no-store",
        "Set-Cookie": adminSessionCookie(session.token, session.expiresAt),
      },
    });
  } catch (error) {
    await new Promise((resolve) => setTimeout(resolve, 600));
    const message = error instanceof z.ZodError ? "ID admin atau password tidak valid." : error instanceof Error ? error.message : "Login admin gagal.";
    return Response.json({ error: message }, { status: error instanceof z.ZodError ? 400 : 401, headers: { "Cache-Control": "no-store" } });
  }
}
