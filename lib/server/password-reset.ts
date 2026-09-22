import { getD1 } from "@/db";
import { createCustomerPasswordCredentials } from "@/lib/server/customer-auth";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

const RESET_TTL_MS = 15 * 60 * 1000;

type ResetEmailRuntime = {
  RESEND_API_KEY?: string;
  RESEND_FROM_EMAIL?: string;
  RESEND_API_URL?: string;
  PUBLIC_BASE_URL?: string;
};

type ResetRequest = {
  token: string;
  tokenHash: string;
  customerId: string;
  email: string;
  name: string;
  expiresAt: string;
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

function escapeHtml(value: string) {
  return value.replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  })[character] || character);
}

function resetEmailConfig() {
  const runtime = getRuntimeEnv<ResetEmailRuntime>();
  const apiKey = runtime.RESEND_API_KEY?.trim() ?? "";
  const from = runtime.RESEND_FROM_EMAIL?.trim() ?? "";
  const apiUrl = runtime.RESEND_API_URL?.trim() ?? "";
  return { runtime, apiKey, from, apiUrl };
}

export function passwordResetEmailConfigured() {
  const { apiKey, from, apiUrl } = resetEmailConfig();
  return Boolean(apiKey && from && apiUrl);
}

export async function createPasswordResetRequest(emailInput: string): Promise<ResetRequest | null> {
  const db = getD1();
  const email = normalizeEmail(emailInput);
  await db.prepare(
    "DELETE FROM customer_password_reset_tokens WHERE consumed_at IS NOT NULL OR expires_at <= CURRENT_TIMESTAMP",
  ).run().catch(() => undefined);
  const customer = await db.prepare(
    "SELECT id, email, name FROM customer_users WHERE email = ? AND is_active = 1 LIMIT 1",
  ).bind(email).first<{ id: string; email: string; name: string }>();

  if (!customer) {
    await sha256(email);
    return null;
  }

  const token = randomToken();
  const tokenHash = await sha256(token);
  const expiresAt = new Date(Date.now() + RESET_TTL_MS).toISOString();

  await db.batch([
    db.prepare("DELETE FROM customer_password_reset_tokens WHERE customer_id = ?").bind(customer.id),
    db.prepare(
      `INSERT INTO customer_password_reset_tokens
       (id, customer_id, token_hash, expires_at)
       VALUES (?, ?, ?, ?)`,
    ).bind(crypto.randomUUID(), customer.id, tokenHash, expiresAt),
  ]);

  return {
    token,
    tokenHash,
    customerId: customer.id,
    email: customer.email,
    name: customer.name,
    expiresAt,
  };
}

export async function revokePasswordResetRequest(tokenHash: string) {
  await getD1().prepare("DELETE FROM customer_password_reset_tokens WHERE token_hash = ?")
    .bind(tokenHash).run();
}

function configuredBaseUrl(requestUrl: string) {
  const { runtime } = resetEmailConfig();
  const candidate = runtime.PUBLIC_BASE_URL?.trim();
  if (candidate) {
    try {
      return new URL(candidate).origin;
    } catch {
      // Fall back to the request origin below.
    }
  }
  return new URL(requestUrl).origin;
}

export async function sendPasswordResetEmail(reset: ResetRequest, requestUrl: string) {
  const { apiKey, from, apiUrl } = resetEmailConfig();
  if (!apiKey || !from || !apiUrl) throw new Error("Layanan email pemulihan belum dikonfigurasi.");

  const resetUrl = new URL("/reset-password", configuredBaseUrl(requestUrl));
  resetUrl.searchParams.set("token", reset.token);

  const response = await fetch(apiUrl, {
    method: "POST",
    headers: {
      authorization: `Bearer ${apiKey}`,
      "content-type": "application/json",
      "idempotency-key": `lfamilia-password-reset-${reset.tokenHash.slice(0, 32)}`,
    },
    body: JSON.stringify({
      from,
      to: [reset.email],
      subject: "Reset password LFAMILIA STORE",
      html: `<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515">
        <h1>LFAMILIA STORE</h1>
        <p>Halo ${escapeHtml(reset.name || "Pelanggan")},</p>
        <p>Kami menerima permintaan untuk mengatur ulang password akun LFAMILIA kamu.</p>
        <p><a href="${escapeHtml(resetUrl.toString())}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700">Atur ulang password</a></p>
        <p>Link ini berlaku selama 15 menit dan hanya dapat digunakan satu kali.</p>
        <p>Jika kamu tidak meminta perubahan ini, abaikan email ini.</p>
      </div>`,
    }),
    signal: AbortSignal.timeout(12_000),
  });

  const payload = await response.json().catch(() => ({})) as { message?: string };
  if (!response.ok) throw new Error(payload.message || "Resend menolak email pemulihan.");
}

export async function consumePasswordResetToken(token: string, password: string) {
  const normalizedToken = token.trim();
  if (!/^[A-Za-z0-9_-]{32,128}$/.test(normalizedToken)) {
    throw new Error("Link reset password tidak valid atau sudah kedaluwarsa.");
  }

  const tokenHash = await sha256(normalizedToken);
  const { passwordHash, saltHex } = await createCustomerPasswordCredentials(password);
  const db = getD1();

  const results = await db.batch([
    db.prepare(
      `UPDATE customer_users
       SET password_hash = ?, password_salt = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = (
         SELECT customer_id
         FROM customer_password_reset_tokens
         WHERE token_hash = ?
           AND consumed_at IS NULL
           AND expires_at > CURRENT_TIMESTAMP
         LIMIT 1
       )
       AND is_active = 1`,
    ).bind(passwordHash, saltHex, tokenHash),
    db.prepare(
      `UPDATE customer_password_reset_tokens
       SET consumed_at = CURRENT_TIMESTAMP
       WHERE token_hash = ?
         AND consumed_at IS NULL
         AND expires_at > CURRENT_TIMESTAMP
         AND EXISTS (
           SELECT 1 FROM customer_users
           WHERE customer_users.id = customer_password_reset_tokens.customer_id
             AND customer_users.is_active = 1
         )`,
    ).bind(tokenHash),
    db.prepare(
      `DELETE FROM customer_sessions
       WHERE customer_id = (
         SELECT customer_id FROM customer_password_reset_tokens
         WHERE token_hash = ? LIMIT 1
       )`,
    ).bind(tokenHash),
  ]);

  const passwordChanged = Number(results[0]?.meta.changes ?? 0) === 1;
  const tokenConsumed = Number(results[1]?.meta.changes ?? 0) === 1;
  if (!passwordChanged || !tokenConsumed) {
    throw new Error("Link reset password tidak valid atau sudah kedaluwarsa.");
  }

  await db.prepare(
    `DELETE FROM customer_password_reset_tokens
     WHERE customer_id = (
       SELECT customer_id FROM customer_password_reset_tokens
       WHERE token_hash = ? LIMIT 1
     )
     AND token_hash <> ?`,
  ).bind(tokenHash, tokenHash).run();

  return { ok: true };
}
