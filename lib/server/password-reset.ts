import { getD1 } from "@/db";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import { createPasswordRecord } from "@/lib/server/customer-auth";

type RuntimeEnv = {
  PUBLIC_BASE_URL?: string;
  RESEND_API_KEY?: string;
  RESEND_FROM_EMAIL?: string;
  RESEND_API_URL?: string;
};

const RESET_MINUTES = 15;

function bytesToHex(bytes: Uint8Array) {
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
}

async function sha256(value: string) {
  return bytesToHex(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value))));
}

function randomToken(bytes = 32) {
  const value = crypto.getRandomValues(new Uint8Array(bytes));
  return btoa(String.fromCharCode(...value)).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/g, "");
}

function baseUrl(env: RuntimeEnv) {
  try {
    return new URL(env.PUBLIC_BASE_URL || "https://lfamiliastore.my.id").origin;
  } catch {
    return "https://lfamiliastore.my.id";
  }
}

export async function requestCustomerPasswordReset(email: string) {
  const db = getD1();
  const normalizedEmail = email.trim().toLowerCase();
  const customer = await db.prepare(
    "SELECT id, name, email FROM customer_users WHERE email = ? AND is_active = 1 LIMIT 1",
  ).bind(normalizedEmail).first<{ id: string; name: string; email: string }>();

  // Deliberately return the same outcome for unknown emails to prevent account enumeration.
  if (!customer) return;

  const env = getRuntimeEnv<RuntimeEnv>();
  if (!env.RESEND_API_KEY?.trim() || !env.RESEND_FROM_EMAIL?.trim() || !env.RESEND_API_URL?.trim()) {
    throw new Error("Email reset password belum dikonfigurasi.");
  }

  const token = randomToken();
  const tokenHash = await sha256(token);
  const id = crypto.randomUUID();
  await db.batch([
    db.prepare("DELETE FROM customer_password_reset_tokens WHERE customer_id = ? OR expires_at <= CURRENT_TIMESTAMP").bind(customer.id),
    db.prepare(
      "INSERT INTO customer_password_reset_tokens (id, customer_id, token_hash, expires_at) VALUES (?, ?, ?, datetime('now', '+15 minutes'))",
    ).bind(id, customer.id, tokenHash),
  ]);

  const resetUrl = `${baseUrl(env)}/reset-password?token=${encodeURIComponent(token)}`;
  const response = await fetch(env.RESEND_API_URL.trim(), {
    method: "POST",
    headers: {
      authorization: `Bearer ${env.RESEND_API_KEY.trim()}`,
      "content-type": "application/json",
      "idempotency-key": `lfamilia-password-reset-${id}`,
    },
    body: JSON.stringify({
      from: env.RESEND_FROM_EMAIL.trim(),
      to: [customer.email],
      subject: "Reset password LFAMILIA STORE",
      html: `<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515"><h1>LFAMILIA STORE</h1><p>Halo ${customer.name.replace(/[&<>"']/g, "")},</p><p>Kami menerima permintaan untuk mengatur ulang password akun Anda.</p><p><a href="${resetUrl}" style="display:inline-block;padding:12px 18px;background:#151515;color:#fff;text-decoration:none;border-radius:8px">Reset password</a></p><p>Link ini berlaku selama ${RESET_MINUTES} menit dan hanya dapat digunakan satu kali.</p><p>Jika Anda tidak meminta reset password, abaikan email ini.</p></div>`,
    }),
    signal: AbortSignal.timeout(12_000),
  });
  if (!response.ok) {
    await db.prepare("DELETE FROM customer_password_reset_tokens WHERE id = ?").bind(id).run();
    throw new Error("Email reset password gagal dikirim.");
  }
}

export async function resetCustomerPassword(token: string, password: string) {
  const db = getD1();
  const tokenHash = await sha256(token);
  const row = await db.prepare(
    `SELECT id, customer_id FROM customer_password_reset_tokens
     WHERE token_hash = ? AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP LIMIT 1`,
  ).bind(tokenHash).first<{ id: string; customer_id: string }>();
  if (!row) throw new Error("Link reset password tidak valid atau sudah kedaluwarsa.");

  const record = await createPasswordRecord(password);
  await db.batch([
    db.prepare(
      "UPDATE customer_users SET password_hash = ?, password_salt = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND is_active = 1",
    ).bind(record.passwordHash, record.passwordSalt, row.customer_id),
    db.prepare(
      "UPDATE customer_password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ? AND used_at IS NULL",
    ).bind(row.id),
    db.prepare("DELETE FROM customer_sessions WHERE customer_id = ?").bind(row.customer_id),
  ]);
}
