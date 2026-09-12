import { getPasswordAdminSession, type AdminRole as StoredAdminRole, type PasswordAdminSession } from "@/lib/server/admin-auth";
import { getRuntimeEnv, requireRuntimeValue } from "@/lib/server/runtime-env";
import { rejectCrossOriginMutation } from "@/lib/server/security";

export type AdminRole = StoredAdminRole;
export type AdminAccessLevel = "super_admin" | "admin" | "staff" | "owner";

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

function hasAdminAccess(role: AdminRole, minimumRole: AdminAccessLevel) {
  if (minimumRole === "owner" || minimumRole === "super_admin") return role === "super_admin";
  if (minimumRole === "admin") return role === "super_admin" || role === "admin";
  return true;
}

export async function requireAdminSession(request: Request, minimumRole: AdminAccessLevel = "staff") {
  const session = await getAdminSession(request);
  if (!session) return Response.json({ error: "Akses admin tidak ditemukan atau sudah dinonaktifkan." }, { status: 401 });
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  if (!hasAdminAccess(session.role, minimumRole)) {
    return Response.json({ error: minimumRole === "staff" ? "Tindakan ini membutuhkan akses Staff atau lebih tinggi." : minimumRole === "admin" ? "Tindakan ini membutuhkan akses Admin atau lebih tinggi." : "Tindakan ini hanya dapat dilakukan oleh Super Admin." }, { status: 403 });
  }
  return session;
}
