import { z } from "zod";
import { customerSessionCookie, loginOrRegisterGoogleCustomer } from "@/lib/server/customer-auth";
import { verifyGoogleIdentityCredential } from "@/lib/server/google-oauth";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

const inputSchema = z.object({
  credential: z.string().min(100).max(12_000),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;

  const rate = await allowRequest(request, "customer-google-identity", 30, 3600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak percobaan login Google. Coba lagi nanti." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter), "Cache-Control": "no-store" } },
    );
  }

  try {
    const input = inputSchema.parse(await request.json());
    const identity = await verifyGoogleIdentityCredential(input.credential);
    const session = await loginOrRegisterGoogleCustomer(identity);
    return Response.json(
      { ok: true, customer: session.customer },
      {
        headers: {
          "Set-Cookie": customerSessionCookie(session.token, session.expiresAt),
          "Cache-Control": "no-store",
        },
      },
    );
  } catch (error) {
    const message = error instanceof z.ZodError
      ? "Credential Google tidak valid."
      : error instanceof Error
        ? error.message
        : "Login Google gagal.";
    return Response.json({ error: message }, { status: 400, headers: { "Cache-Control": "no-store" } });
  }
}
