import { z } from "zod";
import {
  createPasswordResetRequest,
  passwordResetEmailConfigured,
  revokePasswordResetRequest,
  sendPasswordResetEmail,
} from "@/lib/server/password-reset";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { verifyTurnstile } from "@/lib/server/turnstile";

const schema = z.object({
  email: z.string().trim().email().max(150),
  turnstileToken: z.string().max(2048).optional(),
});

const genericMessage = "Jika email tersebut terdaftar, link reset password telah dikirim.";

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;

  const rate = await allowRequest(request, "customer-forgot-password", 4, 3600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak permintaan reset password. Coba lagi nanti." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter), "Cache-Control": "no-store" } },
    );
  }

  try {
    const input = schema.parse(await request.json());
    if (!await verifyTurnstile(request, input.turnstileToken)) {
      return Response.json({ error: "Verifikasi keamanan gagal. Coba lagi." }, { status: 403 });
    }

    if (!passwordResetEmailConfigured()) {
      return Response.json(
        { error: "Layanan pemulihan akun sedang tidak tersedia. Coba lagi nanti." },
        { status: 503, headers: { "Cache-Control": "no-store" } },
      );
    }

    const reset = await createPasswordResetRequest(input.email);
    if (reset) {
      try {
        await sendPasswordResetEmail(reset, request.url);
      } catch {
        await revokePasswordResetRequest(reset.tokenHash).catch(() => undefined);
        return Response.json(
          { error: "Email reset password belum dapat dikirim. Coba lagi beberapa saat." },
          { status: 503, headers: { "Cache-Control": "no-store" } },
        );
      }
    }

    return Response.json({ ok: true, message: genericMessage }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    if (error instanceof z.ZodError) {
      return Response.json({ error: "Masukkan alamat email yang valid." }, { status: 400 });
    }
    return Response.json(
      { error: "Permintaan reset password belum dapat diproses." },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
}
