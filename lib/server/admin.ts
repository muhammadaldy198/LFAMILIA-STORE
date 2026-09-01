import { getPasswordAdminSession, type PasswordAdminSession } from "@/lib/server/admin-auth";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export type AdminRole = "owner" | "staff";

export type AdminSession = PasswordAdminSession;

type RuntimeEnv = {
  OWNER_EMAIL?: string;
};

function normalizeEmail(value: string | null | undefined) {
  return value?.trim().toLowerCase() || null;
}

export function getAccessEmail(request: Request) {
  const cloudflareEmail = normalizeEmail(request.headers.get("cf-access-authenticated-user-email"));
  if (cloudflareEmail) return cloudflareEmail;
  return null;
}

export function getOwnerEmail() {
  return normalizeEmail(getRuntimeEnv<RuntimeEnv>().OWNER_EMAIL) ?? "muhammadaldy198@gmail.com";
}

export async function getAdminSession(request: Request): Promise<AdminSession | null> {
  return getPasswordAdminSession(request);
}

export async function requireAdminSession(request: Request, minimumRole: AdminRole = "staff") {
  const session = await getAdminSession(request);
  if (!session) return Response.json({ error: "Akses admin tidak ditemukan atau sudah dinonaktifkan." }, { status: 401 });
  if (!["GET", "HEAD", "OPTIONS"].includes(request.method)) {
    const origin = request.headers.get("origin");
    if (origin && origin !== new URL(request.url).origin) return Response.json({ error: "Permintaan admin ditolak." }, { status: 403 });
  }
  if (minimumRole === "owner" && session.role !== "owner") {
    return Response.json({ error: "Tindakan ini hanya dapat dilakukan oleh Pemilik." }, { status: 403 });
  }
  return session;
}
