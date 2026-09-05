import { getD1 } from "@/db";

const ADMIN_CREDENTIAL_PREFIX = "__lfadmin__:";
const ADMIN_COOKIE_NAME = "lfamilia_admin_session";
const ADMIN_SESSION_HOURS = 12;
const PASSWORD_ITERATIONS = 100_000;

export type PasswordAdminSession = {
  id: number;
  email: string;
  name: string;
  role: "owner" | "staff";
};

type CredentialRow = {
  admin_id: number;
  username: string;
  admin_name: string;
  role: "owner" | "staff";
  admin_active: number;
  credential_id: string;
  password_hash: string;
  password_salt: string;
  credential_active: number;
};

type SessionRow = {
  admin_id: number;
  username: string;
  admin_name: string;
  role: "owner" | "staff";
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

async function sha256(value: string) {
  return bytesToHex(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value))));
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

function cookieValue(request: Request) {
  const cookie = request.headers.get("cookie") ?? "";
  for (const part of cookie.split(";")) {
    const [name, ...value] = part.trim().split("=");
    if (name === ADMIN_COOKIE_NAME) return decodeURIComponent(value.join("="));
  }
  return null;
}

async function createAdminSession(credentialId: string) {
  const token = randomToken();
  const tokenHash = await sha256(token);
  const expiresAt = new Date(Date.now() + ADMIN_SESSION_HOURS * 3_600_000).toISOString();
  const db = getD1();
  await db.prepare("DELETE FROM customer_sessions WHERE expires_at <= CURRENT_TIMESTAMP").run();
  await db.prepare("INSERT INTO customer_sessions (id, customer_id, token_hash, expires_at) VALUES (?, ?, ?, ?)")
    .bind(crypto.randomUUID(), credentialId, tokenHash, expiresAt).run();
  return { token, expiresAt };
}

export function adminSessionCookie(token: string, expiresAt: string) {
  return `${ADMIN_COOKIE_NAME}=${encodeURIComponent(token)}; Path=/; HttpOnly; Secure; SameSite=Strict; Expires=${new Date(expiresAt).toUTCString()}`;
}

export function clearAdminSessionCookie() {
  return `${ADMIN_COOKIE_NAME}=; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=0`;
}

export async function loginAdmin(usernameInput: string, password: string, expectedRole?: "owner" | "staff") {
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
  if (expectedRole && row.role !== expectedRole) {
    throw new Error(expectedRole === "owner" ? "Akun ini bukan akun Admin/Pemilik." : "Akun ini bukan akun Staff.");
  }

  await getD1().prepare("UPDATE customer_users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
    .bind(row.credential_id).run();
  const session = await createAdminSession(row.credential_id);
  return {
    admin: { id: row.admin_id, email: row.username, name: row.admin_name, role: row.role } satisfies PasswordAdminSession,
    ...session,
  };
}

export async function getPasswordAdminSession(request: Request): Promise<PasswordAdminSession | null> {
  const token = cookieValue(request);
  if (!token) return null;
  const row = await getD1().prepare(
    `SELECT a.id AS admin_id, a.email AS username, a.name AS admin_name, a.role
     FROM customer_sessions s
     JOIN customer_users c ON c.id = s.customer_id
     JOIN admin_users a ON c.email = (? || lower(a.email))
     WHERE s.token_hash = ? AND s.expires_at > CURRENT_TIMESTAMP
       AND c.is_active = 1 AND a.is_active = 1
     LIMIT 1`,
  ).bind(ADMIN_CREDENTIAL_PREFIX, await sha256(token)).first<SessionRow>();
  return row ? { id: row.admin_id, email: row.username, name: row.admin_name, role: row.role } : null;
}

export async function deleteAdminSession(request: Request) {
  const token = cookieValue(request);
  if (!token) return;
  await getD1().prepare("DELETE FROM customer_sessions WHERE token_hash = ?").bind(await sha256(token)).run();
}

export async function getOwnerCredentialState() {
  const row = await getD1().prepare(
    `SELECT a.id, a.email AS username, a.name
     FROM admin_users a
     JOIN customer_users c ON c.email = (? || lower(a.email))
     WHERE a.role = 'owner' AND a.is_active = 1 AND c.is_active = 1
     ORDER BY a.id ASC LIMIT 1`,
  ).bind(ADMIN_CREDENTIAL_PREFIX).first<{ id: number; username: string; name: string }>();
  return { configured: Boolean(row), username: row?.username ?? null, name: row?.name ?? "Pemilik LFAMILIA" };
}

export async function configurePrimaryOwner(input: { accessEmail: string; username: string; name: string; password: string }) {
  const db = getD1();
  const username = normalizeAdminId(input.username);
  let owner = await db.prepare("SELECT id, email FROM admin_users WHERE role = 'owner' ORDER BY id ASC LIMIT 1")
    .first<{ id: number; email: string }>();
  if (!owner) {
    owner = await db.prepare("INSERT INTO admin_users (email, name, role, is_active) VALUES (?, ?, 'owner', 1) RETURNING id, email")
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
    await db.prepare("UPDATE admin_users SET email = ?, name = ?, role = 'owner', is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(username, input.name.trim(), owner.id).run();
  } catch (error) {
    if (!existingCredential) await deleteAdminCredential(username).catch(() => undefined);
    throw error;
  }
  const session = await createAdminSession(credentialId);
  return { admin: { id: owner.id, email: username, name: input.name.trim(), role: "owner" as const }, ...session };
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
