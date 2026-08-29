import { getD1 } from "@/db";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export type AdminRole = "owner" | "staff";

export type AdminSession = {
  id: number | null;
  email: string;
  name: string;
  role: AdminRole;
};

type RuntimeEnv = {
  OWNER_EMAIL?: string;
  ALLOW_DEV_ADMIN_HEADER?: string;
};

type AdminRow = {
  id: number;
  email: string;
  name: string;
  role: AdminRole;
  is_active: number;
};

function normalizeEmail(value: string | null | undefined) {
  return value?.trim().toLowerCase() || null;
}

export function getAccessEmail(request: Request) {
  const env = getRuntimeEnv<RuntimeEnv>();
  const cloudflareEmail = normalizeEmail(request.headers.get("cf-access-authenticated-user-email"));
  if (cloudflareEmail) return cloudflareEmail;
  if (env.ALLOW_DEV_ADMIN_HEADER === "true") {
    return normalizeEmail(request.headers.get("x-lfamilia-admin-email"));
  }
  return null;
}

export async function getAdminSession(request: Request): Promise<AdminSession | null> {
  const email = getAccessEmail(request);
  if (!email) return null;
  const ownerEmail = normalizeEmail(getRuntimeEnv<RuntimeEnv>().OWNER_EMAIL) ?? "muhammadaldy198@gmail.com";

  try {
    const row = await getD1().prepare(
      "SELECT id, email, name, role, is_active FROM admin_users WHERE lower(email) = ? LIMIT 1",
    ).bind(email).first<AdminRow>();
    if (row?.is_active) return { id: row.id, email: row.email, name: row.name, role: row.role };
  } catch {
    if (email === ownerEmail) return { id: null, email, name: "Pemilik LFAMILIA", role: "owner" };
  }

  if (email === ownerEmail) return { id: null, email, name: "Pemilik LFAMILIA", role: "owner" };
  return null;
}

export async function requireAdminSession(request: Request, minimumRole: AdminRole = "staff") {
  const session = await getAdminSession(request);
  if (!session) return Response.json({ error: "Akses admin tidak ditemukan atau sudah dinonaktifkan." }, { status: 401 });
  if (minimumRole === "owner" && session.role !== "owner") {
    return Response.json({ error: "Tindakan ini hanya dapat dilakukan oleh Pemilik." }, { status: 403 });
  }
  return session;
}
