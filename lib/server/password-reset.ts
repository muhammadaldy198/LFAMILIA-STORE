import { getD1 } from "@/db";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

const RESET_TTL_MINUTES = 15;
const PASSWORD_ITERATIONS = 100_000;

type ResendEnv = {
  RESEND_API_KEY?: string;
  RESEND_FROM_EMAIL?: string;
  RESEND_API_URL?: string;
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

function escapeHtml(value: string) {
  return value.replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
  })[character] || character);
}

async function sendResetEmail(input: { email: string; name: string; resetUrl: string; idempotencyKey: string }) {
  const config = getRuntimeEnv<ResendEnv>();
  const apiKey = config.RESEND_API_KEY?.trim();
  const from = config.RESEND_FROM_EMAIL?.trim();
  const apiUrl = config.RESEND_API_URL?.trim();
  if (!apiKey || !from || !apiUrl) throw new Error("Layanan email reset password belum dikonfigurasi.");

  const response = await fetch(apiUrl, {
    method: "POST",
    headers: {
      authorization: `Bearer ${apiKey}`,
      "content-type": "application/json",
      "idempotency-key": input.idempotencyKey,
    },
    body: JSON.stringify({
      from,
      to: [input.email],
      subject: "Reset password LFAMILIA STORE",
      html: `<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515"><h1>LFAMILIA STORE</h1><p>Halo ${escapeHtml(input.name)},</p><p>Kami menerima permintaan untuk mengganti password akun Anda.</p><p><a href="${escapeHtml(input.resetUrl)}" style="display:inline-block;padding:12px 18px;background:#151515;color:#fff;text-decoration:none;border-radius:8px">Buat password baru</a></p><p>Link ini berlaku selama ${RESET_TTL_MINUTES} menit dan hanya dapat digunakan satu kali.</p><p>Jika Anda tidak meminta reset password, abaikan email ini.</p></div>`,
    }),
    signal: AbortSignal.timeout(12_000),
  });
  const payload = await response.json().catch(() => ({})) as { message?: string };
  if (!response.ok) throw new Error(payload.message || "Email reset password gagal dikirim.");
}

export async function requestPasswordReset(emailInput: string, origin: string) {
  const db = getD1();
  const email = normalizeEmail(emailInput);
  const customer = await db.prepare(
    "SELECT id, email, name, is_active FROM customer_users WHERE email = ? LIMIT 1",
  ).bind(email).first<{ id: string; email: string; name: string; is_active: number }>();

  // Deliberately return the same result for unknown/inactive accounts to prevent account enumeration.
  if (!customer || !customer.is_active) return;

  await db.prepare(
    "UPDATE customer_password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND used_at IS NULL",
  ).bind(customer.id).run();

  const token = randomToken();
  const tokenHash = await sha256(token);
  const id = crypto.randomUUID();
  const expiresAt = new Date(Date.now() + RESET_TTL_MINUTES * 60_000).toISOString();
  await db.prepare(
    "INSERT INTO customer_password_reset_tokens (id, customer_id, token_hash, expires_at) VALUES (?, ?, ?, ?)",
  ).bind(id, customer.id, tokenHash, expiresAt).run();

  const resetUrl = new URL("/reset-password", origin);
  resetUrl.searchParams.set("token", token);
  try {
    await sendResetEmail({
      email: customer.email,
      name: customer.name,
      resetUrl: resetUrl.toString(),
      idempotencyKey: `lfamilia-password-reset-${id}`,
    });
  } catch (error) {
    await db.prepare("DELETE FROM customer_password_reset_tokens WHERE id = ?").bind(id).run().catch(() => undefined);
    throw error;
  }
}

export async function resetPassword(token: string, password: string) {
  const db = getD1();
  const tokenHash = await sha256(token);
  const record = await db.prepare(
    `SELECT id, customer_id FROM customer_password_reset_tokens
     WHERE token_hash = ? AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP LIMIT 1`,
  ).bind(tokenHash).first<{ id: string; customer_id: string }>();
  if (!record) throw new Error("Link reset password tidak valid atau sudah kedaluwarsa.");

  const salt = crypto.getRandomValues(new Uint8Array(16));
  const saltHex = bytesToHex(salt);
  const passwordHash = await passwordDigest(password, saltHex);

  await db.batch([
    db.prepare(
      "UPDATE customer_users SET password_hash = ?, password_salt = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
    ).bind(passwordHash, saltHex, record.customer_id),
    db.prepare(
      "UPDATE customer_password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND used_at IS NULL",
    ).bind(record.customer_id),
    db.prepare("DELETE FROM customer_sessions WHERE customer_id = ?").bind(record.customer_id),
  ]);
}
