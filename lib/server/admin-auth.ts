import { getD1 } from "@/db";

const ADMIN_CREDENTIAL_PREFIX = "__lfadmin__:";
export const PANEL_COOKIE_NAME = "lfamilia_panel_session";
const ADMIN_SESSION_HOURS = 12;
const PASSWORD_ITERATIONS = 100_000;

export type AdminRole = "super_admin" | "admin" | "staff";
export type AdminLoginArea = "backoffice" | "staff";

export type PasswordAdminSession = {
  id: number;
  email: string;
  name: string;
  role: AdminRole;
};

type CredentialRow = {
  admin_id: number;
  username: string;
  admin_name: string;
  role: AdminRole;
  admin_active: number;
  credential_id: string;
  password_hash: string;
  password_salt: string;
  credential_active: number;
};

type SessionSigningRow = {
  admin_id: number;
  username: string;
  admin_name: string;
  role: AdminRole;
  admin_active: number;
  credential_id: string;
  credential_active: number;
  password_hash: string;
};

type SessionTokenPayload = {
  u: string;
  r: AdminRole;
  e: number;
  n: string;
};

export function normalizeAdminId(value: string) {
  return value.trim().toLowerCase();
}

export function isValidAdminId(value: string) {
  return /^[a-z0-9._-]{3,32}$/.test(normalizeAdminId(value));
}

function credentialEmail(username: string) {
  return `${ADMIN_CREDENTIAL_PREFIX}${normalizeAdminId(username)}`;
}

function bytesToHex(bytes: Uint8Array) {
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function randomToken(bytes = 32) {
  const value = crypto.getRandomValues(new Uint8Array(bytes));
  return btoa(String.fromCharCode(...value)).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/g, "");
}

function base64UrlEncode(value: string) {
  return btoa(value).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/g, "");
}

function base64UrlDecode(value: string) {
  const normalized = value.replaceAll("-", "+").replaceAll("_", "/");
  const padded = normalized + "=".repeat((4 - (normalized.length % 4)) % 4);
  return atob(padded);
}

async function hmacHex(secret: string, value: string) {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  const signature = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(value));
  return bytesToHex(new Uint8Array(signature));
}

async function passwordDigest(password: string, saltHex: string) {
  const salt = new Uint8Array(saltHex.match(/.{1,2}/g)?.map((part) => Number.parseInt(part, 16)) ?? []);
  const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(password), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", hash: "SHA-256", salt, iterations: PASSWORD_ITERATIONS }, key, 256);
  return bytesToHex(new Uint8Array(bits));
}

function constantTimeEqual(left: string, right: string) {
  if (left.length !== right.length) return false;
  let mismatch = 0;
  for (let index = 0; index < left.length; index += 1) mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index);
  return mismatch === 0;
}

async function passwordRecord(password: string) {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const passwordSalt = bytesToHex(salt);
  return { passwordSalt, passwordHash: await passwordDigest(password, passwordSalt) };
}

function cookieValue(request: Request, cookieName: string) {
  const cookie = request.headers.get("cookie") ?? "";
  for (const part of cookie.split(";")) {
    const [name, ...value] = part.trim().split("=");
    if (name === cookieName) return decodeURIComponent(value.join("="));
  }
  return null;
}

async function createAdminSession(credentialId: string) {
  const row = await getD1().prepare(
    `SELECT a.id AS admin_id, a.email AS username, a.name AS admin_name, a.role,
      a.is_active AS admin_active, c.id AS credential_id, c.is_active AS credential_active,
      c.password_hash
     FROM customer_users c
     JOIN admin_users a ON c.email = (? || lower(a.email))
     WHERE c.id = ?
     LIMIT 1`,
  ).bind(ADMIN_CREDENTIAL_PREFIX, credentialId).first<SessionSigningRow>();

  if (!row || !row.admin_active || !row.credential_active) {
    throw new Error("Akun panel tidak aktif.");
  }

  const expiresAt = new Date(Date.now() + ADMIN_SESSION_HOURS * 3_600_000);
  const payload: SessionTokenPayload = {
    u: normalizeAdminId(row.username),
    r: row.role,
    e: expiresAt.getTime(),
    n: randomToken(12),
  };
  const encodedPayload = base64UrlEncode(JSON.stringify(payload));
  const signature = await hmacHex(row.password_hash, encodedPayload);
  return { token: `${encodedPayload}.${signature}`, expiresAt: expiresAt.toISOString() };
}

export function panelSessionCookie(token: string, expiresAt: string) {
  return `${PANEL_COOKIE_NAME}=${encodeURIComponent(token)}; Path=/; HttpOnly; Secure; SameSite=Lax; Expires=${new Date(expiresAt).toUTCString()}`;
}

export function clearPanelSessionCookie() {
  return `${PANEL_COOKIE_NAME}=; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=0`;
}

// Compatibility exports for older routes. Admin and Staff now share one role-bearing panel session cookie.
export const adminSessionCookie = panelSessionCookie;
export const staffSessionCookie = panelSessionCookie;
export const clearAdminSessionCookie = clearPanelSessionCookie;
export const clearStaffSessionCookie = clearPanelSessionCookie;

function isRoleAllowedInArea(role: AdminRole, area: AdminLoginArea) {
  return area === "backoffice" ? role === "super_admin" || role === "admin" : role === "staff";
}

export async function loginAdmin(usernameInput: string, password: string, expectedRole?: AdminLoginArea) {
  const username = normalizeAdminId(usernameInput);
  const row = await getD1().prepare(
    `SELECT a.id AS admin_id, a.email AS username, a.name AS admin_name, a.role,
      a.is_active AS admin_active, c.id AS credential_id, c.password_hash,
      c.password_salt, c.is_active AS credential_active
     FROM admin_users a
     JOIN customer_users c ON c.email = ?
     WHERE lower(a.email) = ?
     LIMIT 1`,
  ).bind(credentialEmail(username), username).first<CredentialRow>();

  const digest = await passwordDigest(password, row?.password_salt ?? "00000000000000000000000000000000");
  if (!row || !row.admin_active || !row.credential_active || !constantTimeEqual(digest, row.password_hash)) {
    throw new Error("ID atau password salah.");
  }
  if (expectedRole && !isRoleAllowedInArea(row.role, expectedRole)) {
    throw new Error(expectedRole === "backoffice" ? "Akun ini bukan akun Super Admin atau Admin." : "Akun ini bukan akun Staff.");
  }

  await getD1().prepare("UPDATE customer_users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
    .bind(row.credential_id).run();
  const session = await createAdminSession(row.credential_id);
  return {
    admin: { id: row.admin_id, email: row.username, name: row.admin_name, role: row.role } satisfies PasswordAdminSession,
    ...session,
  };
}

export async function getPanelSessionFromToken(token: string | null | undefined): Promise<PasswordAdminSession | null> {
  if (!token) return null;

  const [encodedPayload, signature, extra] = token.split(".");
  if (!encodedPayload || !signature || extra) return null;

  let payload: SessionTokenPayload;
  try {
    payload = JSON.parse(base64UrlDecode(encodedPayload)) as SessionTokenPayload;
  } catch {
    return null;
  }

  if (!payload.u || !["super_admin", "admin", "staff"].includes(payload.r) || !Number.isFinite(payload.e) || payload.e <= Date.now()) {
    return null;
  }

  const row = await getD1().prepare(
    `SELECT a.id AS admin_id, a.email AS username, a.name AS admin_name, a.role,
      a.is_active AS admin_active, c.id AS credential_id, c.is_active AS credential_active,
      c.password_hash
     FROM admin_users a
     JOIN customer_users c ON c.email = ?
     WHERE lower(a.email) = ?
     LIMIT 1`,
  ).bind(credentialEmail(payload.u), normalizeAdminId(payload.u)).first<SessionSigningRow>();

  if (!row || !row.admin_active || !row.credential_active || row.role !== payload.r) return null;

  const expectedSignature = await hmacHex(row.password_hash, encodedPayload);
  if (!constantTimeEqual(signature, expectedSignature)) return null;

  return {
    id: row.admin_id,
    email: row.username,
    name: row.admin_name,
    role: row.role,
  };
}

export async function getRolePanelSession(request: Request, expectedRole: AdminRole) {
  const session = await getPanelSessionFromToken(cookieValue(request, PANEL_COOKIE_NAME));
  return session?.role === expectedRole ? session : null;
}

export async function getPasswordAdminSession(request: Request): Promise<PasswordAdminSession | null> {
  return getPanelSessionFromToken(cookieValue(request, PANEL_COOKIE_NAME));
}

export async function deleteRolePanelSession(_request: Request, _role: AdminRole) {
  void _request;
  void _role;
  // Stateless session: logout invalidates it by clearing PANEL_COOKIE_NAME.
}

export async function deleteAdminSession(_request: Request) {
  void _request;
  // Stateless session: logout invalidates it by clearing PANEL_COOKIE_NAME.
}

export async function getOwnerCredentialState() {
  const row = await getD1().prepare(
    `SELECT a.id, a.email AS username, a.name
     FROM admin_users a
     JOIN customer_users c ON c.email = (? || lower(a.email))
     WHERE a.role = 'super_admin' AND a.is_active = 1 AND c.is_active = 1
     ORDER BY a.id ASC LIMIT 1`,
  ).bind(ADMIN_CREDENTIAL_PREFIX).first<{ id: number; username: string; name: string }>();
  return { configured: Boolean(row), username: row?.username ?? null, name: row?.name ?? "Pemilik LFAMILIA" };
}

export async function configurePrimaryOwner(input: { accessEmail: string; username: string; name: string; password: string }) {
  const db = getD1();
  const username = normalizeAdminId(input.username);
  let owner = await db.prepare("SELECT id, email FROM admin_users WHERE role = 'super_admin' ORDER BY id ASC LIMIT 1")
    .first<{ id: number; email: string }>();
  if (!owner) {
    owner = await db.prepare("INSERT INTO admin_users (email, name, role, is_active) VALUES (?, ?, 'super_admin', 1) RETURNING id, email")
      .bind(input.accessEmail.trim().toLowerCase(), input.name.trim()).first<{ id: number; email: string }>();
  }
  if (!owner) throw new Error("Akun Pemilik gagal disiapkan.");
  const collision = await db.prepare("SELECT id FROM admin_users WHERE lower(email) = ? AND id <> ? LIMIT 1")
    .bind(username, owner.id).first<{ id: number }>();
  if (collision) throw new Error("ID admin sudah digunakan.");

  const oldUsername = owner.email;
  const existingCredential = await db.prepare("SELECT id FROM customer_users WHERE email = ? LIMIT 1")
    .bind(credentialEmail(oldUsername)).first<{ id: string }>();
  let credentialId = existingCredential?.id;
  if (credentialId) {
    await updateAdminCredential(oldUsername, { username, name: input.name, password: input.password, isActive: true });
  } else {
    credentialId = await createAdminCredential({ username, name: input.name, password: input.password, isActive: true });
  }
  try {
    await db.prepare("UPDATE admin_users SET email = ?, name = ?, role = 'super_admin', is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(username, input.name.trim(), owner.id).run();
  } catch (error) {
    if (!existingCredential) await deleteAdminCredential(username).catch(() => undefined);
    throw error;
  }
  const session = await createAdminSession(credentialId);
  return { admin: { id: owner.id, email: username, name: input.name.trim(), role: "super_admin" as const }, ...session };
}

export async function createAdminCredential(input: { username: string; name: string; password: string; isActive: boolean }) {
  const username = normalizeAdminId(input.username);
  const existing = await getD1().prepare("SELECT id FROM customer_users WHERE email = ? LIMIT 1")
    .bind(credentialEmail(username)).first<{ id: string }>();
  if (existing) throw new Error("ID admin sudah digunakan.");
  const credentialId = crypto.randomUUID();
  const passwordData = await passwordRecord(input.password);
  await getD1().prepare(
    `INSERT INTO customer_users (id, email, name, phone, password_hash, password_salt, is_active)
     VALUES (?, ?, ?, 'admin', ?, ?, ?)`,
  ).bind(credentialId, credentialEmail(username), input.name.trim(), passwordData.passwordHash, passwordData.passwordSalt, input.isActive ? 1 : 0).run();
  return credentialId;
}

export async function updateAdminCredential(oldUsername: string, input: { username: string; name: string; password?: string; isActive: boolean }) {
  const db = getD1();
  const row = await db.prepare("SELECT id FROM customer_users WHERE email = ? LIMIT 1")
    .bind(credentialEmail(oldUsername)).first<{ id: string }>();
  if (!row) {
    if (!input.password) throw new Error("Isi password untuk mengaktifkan akun admin ini.");
    return createAdminCredential({ ...input, password: input.password });
  }
  if (input.password) {
    const passwordData = await passwordRecord(input.password);
    await db.prepare(
      `UPDATE customer_users SET email = ?, name = ?, password_hash = ?, password_salt = ?,
       is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    ).bind(credentialEmail(input.username), input.name.trim(), passwordData.passwordHash, passwordData.passwordSalt, input.isActive ? 1 : 0, row.id).run();
    await db.prepare("DELETE FROM customer_sessions WHERE customer_id = ?").bind(row.id).run();
  } else {
    await db.prepare("UPDATE customer_users SET email = ?, name = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(credentialEmail(input.username), input.name.trim(), input.isActive ? 1 : 0, row.id).run();
    if (!input.isActive) await db.prepare("DELETE FROM customer_sessions WHERE customer_id = ?").bind(row.id).run();
  }
  return row.id;
}

export async function deleteAdminCredential(username: string) {
  await getD1().prepare("DELETE FROM customer_users WHERE email = ?").bind(credentialEmail(username)).run();
}
