import { z } from "zod";
import { adminSessionCookie, isValidAdminId, loginAdmin } from "@/lib/server/admin-auth";
import { allowRequest } from "@/lib/server/security";

const schema = z.object({
  username: z.string().trim().min(3).max(32).refine(isValidAdminId),
  password: z.string().min(10).max(72),
});

function backToLogin(request: Request, message: string) {
  const url = new URL("/admin/panel/login", request.url);
  url.searchParams.set("error", message);
  return Response.redirect(url, 303);
}

export async function POST(request: Request) {
  const rate = await allowRequest(request, "owner-login", 5, 900);
  if (!rate.allowed) return backToLogin(request, "Terlalu banyak percobaan masuk. Coba lagi 15 menit.");

  try {
    const form = await request.formData();
    const input = schema.parse({
      username: String(form.get("username") ?? ""),
      password: String(form.get("password") ?? ""),
    });
    const session = await loginAdmin(input.username, input.password, "owner");

    const headers = new Headers();
    headers.set("Location", new URL("/admin/panel", request.url).toString());
    headers.set("Cache-Control", "no-store");
    headers.set("Set-Cookie", adminSessionCookie(session.token, session.expiresAt));
    return new Response(null, { status: 303, headers });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? "ID Admin atau password tidak valid."
        : error instanceof Error
          ? error.message
          : "Login Admin gagal.";
    return backToLogin(request, message);
  }
}
