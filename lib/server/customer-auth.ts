import { getD1 } from "@/db";
import { normalizeWhatsappPhone } from "@/lib/phone";

const COOKIE_NAME = "lfamilia_session";
const SESSION_DAYS = 30;
const PASSWORD_ITERATIONS = 100_000;

export type CustomerSession = {
  id: string;
  email: string;
  name: string;
  phone: string;
  phoneVerified: boolean;
  balance: number;
  leaderboardOptIn: boolean;
};

type CustomerRow = {
  id: string;
  email: string;
  name: string;
  phone: string;
  phone_verified_at: string | null;
  password_hash: string;
  password_salt: string;
  balance: number;
  leaderboard_opt_in: number;
  is_active: number;
};

function normalizeEmail(value: string) {
  return value.trim().toLowerCase();
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

function cookieValue(request: Request) {
  const cookie = request.headers.get("cookie") ?? "";
  for (const part of cookie.split(";")) {
    const [name, ...value] = part.trim().split("=");
    if (name === COOKIE_NAME) return decodeURIComponent(value.join("="));
  }
  return null;
}

function publicCustomer(row: CustomerRow): CustomerSession {
  return {
    id: row.id,
    email: row.email,
    name: row.name,
    phone: row.phone,
    phoneVerified: Boolean(row.phone_verified_at),
    balance: Number(row.balance || 0),
    leaderboardOptIn: Boolean(row.leaderboard_opt_in),
  };
}

export async function registerCustomer(input: { email: string; name: string; phone: string; password: string }) {
  const db = getD1();
  const email = normalizeEmail(input.email);
  const existing = await db.prepare("SELECT id FROM customer_users WHERE email = ? LIMIT 1").bind(email).first<{ id: string }>();
  if (existing) throw new Error("Email sudah terdaftar. Silakan masuk.");
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const saltHex = bytesToHex(salt);
  const passwordHash = await passwordDigest(input.password, saltHex);
  const id = crypto.randomUUID();
  const phone = normalizeWhatsappPhone(input.phone);
  await db.prepare(
    `INSERT INTO customer_users (id, email, name, phone, password_hash, password_salt)
     VALUES (?, ?, ?, ?, ?, ?)`,
  ).bind(id, email, input.name.trim(), phone, passwordHash, saltHex).run();
  return createCustomerSession(id);
}

let oauthSchemaPromise: Promise<void> | null = null;

async function ensureCustomerOauthTable() {
  if (!oauthSchemaPromise) {
    const db = getD1();
    oauthSchemaPromise = (async () => {
      await db.prepare(`CREATE TABLE IF NOT EXISTS customer_oauth_accounts (
        id TEXT PRIMARY KEY NOT NULL,
        customer_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        provider_subject TEXT NOT NULL,
        provider_email TEXT,
        avatar_url TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
      )`).run();
      await db.prepare(`CREATE UNIQUE INDEX IF NOT EXISTS customer_oauth_provider_subject_unique
        ON customer_oauth_accounts (provider, provider_subject)`).run();
      await db.prepare(`CREATE UNIQUE INDEX IF NOT EXISTS customer_oauth_provider_customer_unique
        ON customer_oauth_accounts (provider, customer_id)`).run();
    })().catch((error) => {
      oauthSchemaPromise = null;
      throw error;
    });
  }
  return oauthSchemaPromise;
}

export async function loginOrRegisterGoogleCustomer(input: {
  subject: string;
  email: string;
  name: string;
  picture?: string;
}) {
  await ensureCustomerOauthTable();
  const db = getD1();
  const email = normalizeEmail(input.email);
  const linked = await db.prepare(`SELECT u.id
    FROM customer_oauth_accounts oauth
    JOIN customer_users u ON u.id = oauth.customer_id
    WHERE oauth.provider = ? AND oauth.provider_subject = ? AND u.is_active = 1
    LIMIT 1`)
    .bind("google", input.subject)
    .first<{ id: string }>();
  if (linked?.id) {
    await db.prepare(`UPDATE customer_oauth_accounts
      SET provider_email = ?, avatar_url = ?, updated_at = CURRENT_TIMESTAMP
      WHERE provider = ? AND provider_subject = ?`)
      .bind(email, input.picture?.trim() || null, "google", input.subject).run();
    await db.prepare("UPDATE customer_users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(linked.id).run();
    return createCustomerSession(linked.id);
  }

  const existing = await db.prepare("SELECT id, is_active FROM customer_users WHERE email = ? LIMIT 1")
    .bind(email)
    .first<{ id: string; is_active: number }>();
  if (existing && !existing.is_active) throw new Error("Akun pelanggan sedang dinonaktifkan.");

  const customerId = existing?.id ?? crypto.randomUUID();
  const oauthInsert = db.prepare(`INSERT INTO customer_oauth_accounts
    (id, customer_id, provider, provider_subject, provider_email, avatar_url)
    VALUES (?, ?, ?, ?, ?, ?)`)
    .bind(crypto.randomUUID(), customerId, "google", input.subject, email, input.picture?.trim() || null);

  if (!existing) {
    const saltHex = bytesToHex(crypto.getRandomValues(new Uint8Array(16)));
    const disabledPasswordHash = bytesToHex(crypto.getRandomValues(new Uint8Array(32)));
    const customerInsert = db.prepare(`INSERT INTO customer_users
      (id, email, name, phone, password_hash, password_salt, last_login_at)
      VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)`)
      .bind(customerId, email, input.name.trim() || "Pelanggan", "", disabledPasswordHash, saltHex);
    // D1 batch is transactional: a failed OAuth link cannot leave an orphan customer.
    await db.batch([customerInsert, oauthInsert]);
  } else {
    await oauthInsert.run();
    await db.prepare("UPDATE customer_users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(customerId).run();
  }
  return createCustomerSession(customerId);
}
export async function loginCustomer(emailInput: string, password: string) {
  const db = getD1();
  const row = await db.prepare(
    `SELECT id, email, name, phone, phone_verified_at, password_hash, password_salt, balance, leaderboard_opt_in, is_active
     FROM customer_users WHERE email = ? LIMIT 1`,
  ).bind(normalizeEmail(emailInput)).first<CustomerRow>();
  if (!row || !row.is_active) throw new Error("Email atau password salah.");
  const digest = await passwordDigest(password, row.password_salt);
  if (!constantTimeEqual(digest, row.password_hash)) throw new Error("Email atau password salah.");
  await db.prepare("UPDATE customer_users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?").bind(row.id).run();
  return createCustomerSession(row.id);
}

export async function createCustomerSession(customerId: string) {
  const db = getD1();
  await db.prepare("DELETE FROM customer_sessions WHERE expires_at <= CURRENT_TIMESTAMP").run().catch(() => undefined);
  const oldSessions = await db.prepare(
    "SELECT id FROM customer_sessions WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50",
  ).bind(customerId).all<{ id: string }>();
  const staleIds = oldSessions.results.slice(4).map((row) => row.id);
  if (staleIds.length) {
    await db.batch(staleIds.map((id) => db.prepare("DELETE FROM customer_sessions WHERE id = ?").bind(id))).catch(() => undefined);
  }
  const token = randomToken();
  const tokenHash = await sha256(token);
  const expiresAt = new Date(Date.now() + SESSION_DAYS * 86_400_000).toISOString();
  await db.prepare("INSERT INTO customer_sessions (id, customer_id, token_hash, expires_at) VALUES (?, ?, ?, ?)")
    .bind(crypto.randomUUID(), customerId, tokenHash, expiresAt).run();
  const row = await db.prepare(
    `SELECT id, email, name, phone, phone_verified_at, password_hash, password_salt, balance, leaderboard_opt_in, is_active
     FROM customer_users WHERE id = ? AND is_active = 1 LIMIT 1`,
  ).bind(customerId).first<CustomerRow>();
  if (!row) throw new Error("Akun pelanggan tidak ditemukan.");
  return { customer: publicCustomer(row), token, expiresAt };
}

export function customerSessionCookie(token: string, expiresAt: string) {
  return `${COOKIE_NAME}=${encodeURIComponent(token)}; Path=/; HttpOnly; Secure; SameSite=Lax; Expires=${new Date(expiresAt).toUTCString()}`;
}

export function clearCustomerSessionCookie() {
  return `${COOKIE_NAME}=; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=0`;
}

export async function getCustomerSession(request: Request): Promise<CustomerSession | null> {
  const token = cookieValue(request);
  if (!token) return null;
  const tokenHash = await sha256(token);
  const row = await getD1().prepare(
    `SELECT u.id, u.email, u.name, u.phone, u.phone_verified_at, u.password_hash, u.password_salt,
      u.balance, u.leaderboard_opt_in, u.is_active
     FROM customer_sessions s
     JOIN customer_users u ON u.id = s.customer_id
     WHERE s.token_hash = ? AND s.expires_at > CURRENT_TIMESTAMP AND u.is_active = 1
     LIMIT 1`,
  ).bind(tokenHash).first<CustomerRow>();
  return row ? publicCustomer(row) : null;
}

export async function requireCustomerSession(
  request: Request,
  options: { allowUnverifiedPhone?: boolean } = {},
) {
  const customer = await getCustomerSession(request);
  if (!customer) {
    return Response.json(
      { error: "Silakan masuk ke akun terlebih dahulu." },
      { status: 401, headers: { "Cache-Control": "no-store" } },
    );
  }
  if (!options.allowUnverifiedPhone && !customer.phoneVerified) {
    return Response.json(
      { error: "Verifikasi nomor WhatsApp terlebih dahulu.", requiresPhoneVerification: true },
      { status: 403, headers: { "Cache-Control": "no-store" } },
    );
  }
  return customer;
}

export async function deleteCustomerSession(request: Request) {
  const token = cookieValue(request);
  if (!token) return;
  await getD1().prepare("DELETE FROM customer_sessions WHERE token_hash = ?").bind(await sha256(token)).run();
}
