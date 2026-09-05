import { z } from "zod";
import { clearAdminSessionCookie, isValidAdminId, loginAdmin, staffSessionCookie } from "@/lib/server/admin-auth";
import { allowRequest } from "@/lib/server/security";

const schema = z.object({
  username: z.string().trim().min(3).max(32).refine(isValidAdminId),
  password: z.string().min(10).max(72),
});

export async function POST(request: Request) {
  const rate = await allowRequest(request, "staff-login", 5, 900);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak percobaan masuk. Coba lagi 15 menit." },
      { status: 429, headers: { "Cache-Control": "no-store", "Retry-After": String(rate.retryAfter) } },
    );
  }

  try {
    const input = schema.parse(await request.json());
    const session = await loginAdmin(input.username, input.password, "staff");
    const headers = new Headers({ "Cache-Control": "no-store" });
    headers.append("Set-Cookie", staffSessionCookie(session.token, session.expiresAt));
    headers.append("Set-Cookie", clearAdminSessionCookie());
    return Response.json({ staff: session.admin }, { headers });
  } catch (error) {
    await new Promise((resolve) => setTimeout(resolve, 600));
    const message =
      error instanceof z.ZodError
        ? "ID Staff atau password tidak valid."
        : error instanceof Error
          ? error.message
          : "Login Staff gagal.";
    return Response.json({ error: message }, { status: error instanceof z.ZodError ? 400 : 401, headers: { "Cache-Control": "no-store" } });
  }
}
