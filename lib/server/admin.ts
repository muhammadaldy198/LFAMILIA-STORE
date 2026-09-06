import { getPasswordAdminSession, type PasswordAdminSession } from "@/lib/server/admin-auth";
import { getRuntimeEnv, requireRuntimeValue } from "@/lib/server/runtime-env";

export type AdminRole = "owner" | "staff";

export type AdminSession = PasswordAdminSession;

type RuntimeEnv = {
  OWNER_EMAIL?: string;
};

function normalizeEmail(value: string | null | undefined) {
  return value?.trim().toLowerCase() || null;
}

export function getAccessEmail(request: Request) {
  // Trust only the internal header injected by worker/index.ts after the
  // Cloudflare Access gate succeeds. The Worker strips any client-supplied
  // value before setting this header.
  return normalizeEmail(request.headers.get("x-lfamilia-admin-email"));
}

export function getOwnerEmail() {
  return requireRuntimeValue(getRuntimeEnv<RuntimeEnv>().OWNER_EMAIL, "OWNER_EMAIL").toLowerCase();
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
