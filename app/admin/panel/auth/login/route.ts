import { z } from "zod";
import { isValidAdminId, loginAdmin, panelSessionCookie } from "@/lib/server/admin-auth";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

const schema = z.object({
  username: z.string().trim().min(3).max(32).refine(isValidAdminId),
  password: z.string().min(10).max(72),
});

function backToLogin(request: Request, message: string) {
  const url = new URL("/admin/panel/login", request.url);
  url.searchParams.set("error", message);
  return Response.redirect(url, 303);
}

function loginCompleteResponse(token: string, expiresAt: string) {
  const target = "/admin/panel";
  const body = `<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="1;url=${target}">
  <title>Masuk LFAMILIA</title>
  <style>
    html,body{margin:0;min-height:100%;background:#07090f;color:#fff;font-family:system-ui,sans-serif}
    body{display:grid;min-height:100vh;place-items:center}
    main{text-align:center;padding:24px}
    strong{display:block;color:#b9ff35;font-size:18px}
    p{color:#ffffff80;font-size:12px}
  </style>
</head>
<body>
  <main><strong>Login berhasil</strong><p>Membuka panel…</p></main>
  <script>window.location.replace(${JSON.stringify(target)});</script>
</body>
</html>`;

  return new Response(body, {
    status: 200,
    headers: {
      "Content-Type": "text/html; charset=utf-8",
      "Cache-Control": "no-store, no-cache, must-revalidate",
      "Set-Cookie": panelSessionCookie(token, expiresAt),
    },
  });
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "backoffice-login", 5, 900);
  if (!rate.allowed) return backToLogin(request, "Terlalu banyak percobaan masuk. Coba lagi 15 menit.");

  try {
    const form = await request.formData();
    const input = schema.parse({
      username: String(form.get("username") ?? ""),
      password: String(form.get("password") ?? ""),
    });

    const session = await loginAdmin(input.username, input.password, "backoffice");
    return loginCompleteResponse(session.token, session.expiresAt);
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
