import { z } from "zod";
import {
  NicknameServiceError,
  NicknameValidationError,
  verifyNicknameForCheckout,
} from "@/lib/server/nickname-check";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

export const dynamic = "force-dynamic";

const noStoreHeaders = { "Cache-Control": "no-store" };
const requestSchema = z.object({
  game: z.string().trim().min(2).max(80).regex(/^[a-z0-9-]+$/, "Kode game tidak valid."),
  userId: z.string().trim().min(2).max(80),
  server: z.string().trim().min(1).max(40).optional(),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "nickname-lookup", 30, 600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak pengecekan nickname. Coba lagi beberapa menit." },
      {
        status: 429,
        headers: { ...noStoreHeaders, "Retry-After": String(rate.retryAfter) },
      },
    );
  }

  try {
    const input = requestSchema.parse(await request.json());
    const result = await verifyNicknameForCheckout({
      productSlug: input.game,
      userId: input.userId,
      server: input.server,
    });
    return Response.json(
      {
        supported: result.supported,
        nickname: result.nickname,
        country: result.country,
      },
      { headers: noStoreHeaders },
    );
  } catch (error) {
    if (error instanceof z.ZodError) {
      return Response.json(
        { error: error.issues[0]?.message ?? "Permintaan pengecekan tidak valid." },
        { status: 400, headers: noStoreHeaders },
      );
    }
    if (error instanceof NicknameValidationError) {
      return Response.json({ error: error.message }, { status: 404, headers: noStoreHeaders });
    }
    if (error instanceof NicknameServiceError) {
      return Response.json({ error: error.message }, { status: 503, headers: noStoreHeaders });
    }
    return Response.json(
      { error: "Layanan verifikasi akun sedang bermasalah." },
      { status: 502, headers: noStoreHeaders },
    );
  }
}
