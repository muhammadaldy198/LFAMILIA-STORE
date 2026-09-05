import { z } from "zod";
import { adminSessionCookie, isValidAdminId, loginAdmin, staffSessionCookie } from "@/lib/server/admin-auth";
import { allowRequest } from "@/lib/server/security";

const schema = z.object({
  username: z.string().trim().min(3).max(32).refine(isValidAdminId),
  password: z.string().min(10).max(72),
  role: z.enum(["owner", "staff"]).optional(),
});

export async function POST(request: Request) {
  const rate = await allowRequest(request, "admin-login", 5, 900);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak percobaan masuk. Coba lagi 15 menit." }, { status: 429, headers: { "Cache-Control": "no-store", "Retry-After": String(rate.retryAfter) } });
  try {
    const input = schema.parse(await request.json());
    const session = await loginAdmin(input.username, input.password, input.role);
    return Response.json(
      { admin: session.admin },
      {
        headers: {
          "Cache-Control": "no-store",
          "Set-Cookie": session.admin.role === "staff"
            ? staffSessionCookie(session.token, session.expiresAt)
            : adminSessionCookie(session.token, session.expiresAt),
        },
      },
    );
  } catch (error) {
    await new Promise((resolve) => setTimeout(resolve, 600));
    const message = error instanceof z.ZodError ? "ID atau password tidak valid." : error instanceof Error ? error.message : "Login panel gagal.";
    return Response.json({ error: message }, { status: error instanceof z.ZodError ? 400 : 401, headers: { "Cache-Control": "no-store" } });
  }
}
