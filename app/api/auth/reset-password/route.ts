import { z } from "zod";
import { consumePasswordResetToken } from "@/lib/server/password-reset";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { verifyTurnstile } from "@/lib/server/turnstile";

const schema = z.object({
  token: z.string().trim().min(32).max(128),
  password: z.string().min(8, "Password minimal 8 karakter.").max(72),
  turnstileToken: z.string().max(2048).optional(),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;

  const rate = await allowRequest(request, "customer-reset-password", 8, 3600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak percobaan reset password. Coba lagi nanti." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter), "Cache-Control": "no-store" } },
    );
  }

  try {
    const input = schema.parse(await request.json());
    if (!await verifyTurnstile(request, input.turnstileToken)) {
      return Response.json({ error: "Verifikasi keamanan gagal. Coba lagi." }, { status: 403 });
    }
    await consumePasswordResetToken(input.token, input.password);
    return Response.json(
      { ok: true, message: "Password berhasil diperbarui. Silakan masuk kembali." },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    const message = error instanceof z.ZodError
      ? (error.issues[0]?.message || "Data reset password tidak valid.")
      : error instanceof Error
        ? error.message
        : "Reset password gagal.";
    return Response.json({ error: message }, { status: 400, headers: { "Cache-Control": "no-store" } });
  }
}
