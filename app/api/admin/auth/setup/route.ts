import { z } from "zod";
import { adminSessionCookie, configurePrimaryOwner, getOwnerCredentialState, isValidAdminId } from "@/lib/server/admin-auth";
import { getAccessEmail, getOwnerEmail } from "@/lib/server/admin";

const schema = z.object({
  username: z.string().trim().min(3, "ID admin minimal 3 karakter.").max(32).refine(isValidAdminId, "ID hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus."),
  name: z.string().trim().min(2, "Nama minimal 2 karakter.").max(80),
  password: z.string().min(10, "Password minimal 10 karakter.").max(72),
});

function authorizeOwner(request: Request) {
  const accessEmail = getAccessEmail(request);
  return accessEmail && accessEmail === getOwnerEmail() ? accessEmail : null;
}

export async function GET(request: Request) {
  if (!authorizeOwner(request)) return Response.json({ error: "Gunakan email Pemilik melalui Cloudflare Access." }, { status: 403 });
  const state = await getOwnerCredentialState();
  return Response.json(state, { headers: { "Cache-Control": "no-store" } });
}

export async function POST(request: Request) {
  const accessEmail = authorizeOwner(request);
  if (!accessEmail) return Response.json({ error: "Setup hanya dapat dilakukan oleh email Pemilik." }, { status: 403 });
  try {
    const input = schema.parse(await request.json());
    const session = await configurePrimaryOwner({ accessEmail, ...input });
    return Response.json({ admin: session.admin }, {
      headers: {
        "Cache-Control": "no-store",
        "Set-Cookie": adminSessionCookie(session.token, session.expiresAt),
      },
    });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Akun Pemilik gagal disiapkan.";
    return Response.json({ error: message }, { status: 400, headers: { "Cache-Control": "no-store" } });
  }
}
