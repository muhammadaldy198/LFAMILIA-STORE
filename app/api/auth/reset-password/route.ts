import { z } from "zod";
import { resetPassword } from "@/lib/server/password-reset";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

const schema = z.object({
  token: z.string().min(32).max(256),
  password: z.string().min(8, "Password minimal 8 karakter.").max(72),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "customer-reset-password", 8, 3600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak percobaan. Coba lagi nanti." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter), "Cache-Control": "no-store" } });

  try {
    const input = schema.parse(await request.json());
    await resetPassword(input.token, input.password);
    return Response.json({ message: "Password berhasil diperbarui. Silakan masuk kembali." }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    const message = error instanceof z.ZodError ? (error.issues[0]?.message || "Data reset password tidak valid.") : error instanceof Error ? error.message : "Reset password gagal.";
    return Response.json({ error: message }, { status: 400, headers: { "Cache-Control": "no-store" } });
  }
}
