import { getD1 } from "@/db";
import { maskPhone, normalizeWhatsappPhone } from "@/lib/phone";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

type WhatsappOtpRuntime = {
  WHATSAPP_GRAPH_API_URL?: string;
  WHATSAPP_ACCESS_TOKEN?: string;
  WHATSAPP_PHONE_NUMBER_ID?: string;
  WHATSAPP_OTP_TEMPLATE_NAME?: string;
  WHATSAPP_OTP_TEMPLATE_LANGUAGE?: string;
  WHATSAPP_OTP_BUTTON_SUBTYPE?: string;
};

type ChallengeRow = {
  id: string;
  customer_id: string;
  phone: string;
  otp_hash: string;
  otp_salt: string;
  expires_at: string;
  attempt_count: number;
  sent_at: string;
  consumed_at: string | null;
};

const OTP_TTL_MS = 5 * 60 * 1000;
const OTP_COOLDOWN_SECONDS = 60;
const OTP_MAX_ATTEMPTS = 5;
const OTP_PBKDF2_ITERATIONS = 120_000;

function bytesToHex(bytes: Uint8Array) {
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function hexToBytes(value: string) {
  return new Uint8Array(value.match(/.{1,2}/g)?.map((part) => Number.parseInt(part, 16)) ?? []);
}

async function otpDigest(code: string, saltHex: string) {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(code),
    "PBKDF2",
    false,
    ["deriveBits"],
  );
  const bits = await crypto.subtle.deriveBits(
    {
      name: "PBKDF2",
      hash: "SHA-256",
      salt: hexToBytes(saltHex),
      iterations: OTP_PBKDF2_ITERATIONS,
    },
    key,
    256,
  );
  return bytesToHex(new Uint8Array(bits));
}

function constantTimeEqual(left: string, right: string) {
  if (left.length !== right.length) return false;
  let mismatch = 0;
  for (let index = 0; index < left.length; index += 1) {
    mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index);
  }
  return mismatch === 0;
}

function randomOtp() {
  const range = 1_000_000;
  const limit = Math.floor(0x1_0000_0000 / range) * range;
  const value = new Uint32Array(1);
  do crypto.getRandomValues(value);
  while (value[0] >= limit);
  return String(value[0] % range).padStart(6, "0");
}

function whatsappRuntime() {
  return getRuntimeEnv<WhatsappOtpRuntime>();
}

function requiredWhatsappConfig() {
  const env = whatsappRuntime();
  const apiUrl = env.WHATSAPP_GRAPH_API_URL?.trim().replace(/\/+$/, "");
  const accessToken = env.WHATSAPP_ACCESS_TOKEN?.trim();
  const phoneNumberId = env.WHATSAPP_PHONE_NUMBER_ID?.trim();
  const templateName = env.WHATSAPP_OTP_TEMPLATE_NAME?.trim();
  const templateLanguage = env.WHATSAPP_OTP_TEMPLATE_LANGUAGE?.trim() || "id";

  if (!apiUrl || !accessToken || !phoneNumberId || !templateName) {
    throw new Error("WhatsApp OTP belum dikonfigurasi oleh admin.");
  }
  let parsed: URL;
  try {
    parsed = new URL(apiUrl);
  } catch {
    throw new Error("Graph API URL WhatsApp tidak valid.");
  }
  if (parsed.protocol !== "https:") throw new Error("Graph API URL WhatsApp harus memakai HTTPS.");

  const buttonSubtype = env.WHATSAPP_OTP_BUTTON_SUBTYPE?.trim().toLowerCase();
  return {
    apiUrl,
    accessToken,
    phoneNumberId,
    templateName,
    templateLanguage,
    buttonSubtype: buttonSubtype === "url" || buttonSubtype === "quick_reply" ? buttonSubtype : "",
  };
}

async function sendWhatsappOtp(phone: string, code: string) {
  const config = requiredWhatsappConfig();
  const components: Array<Record<string, unknown>> = [
    {
      type: "body",
      parameters: [{ type: "text", text: code }],
    },
  ];
  if (config.buttonSubtype) {
    components.push({
      type: "button",
      sub_type: config.buttonSubtype,
      index: "0",
      parameters: [{ type: "text", text: code }],
    });
  }

  const response = await fetch(
    `${config.apiUrl}/${encodeURIComponent(config.phoneNumberId)}/messages`,
    {
      method: "POST",
      headers: {
        authorization: `Bearer ${config.accessToken}`,
        "content-type": "application/json",
      },
      body: JSON.stringify({
        messaging_product: "whatsapp",
        to: phone.replace(/^\+/, ""),
        type: "template",
        template: {
          name: config.templateName,
          language: { code: config.templateLanguage },
          components,
        },
      }),
    },
  );
  if (!response.ok) {
    throw new Error(`WhatsApp menolak pengiriman OTP (HTTP ${response.status}). Periksa template dan konfigurasi WhatsApp OTP.`);
  }
}

export async function createWhatsappOtpChallenge(customerId: string, phoneInput: string) {
  const db = getD1();
  const phone = normalizeWhatsappPhone(phoneInput);

  const used = await db.prepare(
    "SELECT id FROM customer_users WHERE phone = ? AND phone_verified_at IS NOT NULL AND id <> ? LIMIT 1",
  ).bind(phone, customerId).first<{ id: string }>();
  if (used) throw new Error("Nomor WhatsApp ini sudah terhubung ke akun lain.");

  const recent = await db.prepare(
    `SELECT sent_at FROM customer_phone_otp_challenges
     WHERE customer_id = ? AND consumed_at IS NULL
     ORDER BY created_at DESC LIMIT 1`,
  ).bind(customerId).first<{ sent_at: string }>();
  if (recent?.sent_at) {
    const elapsed = Date.now() - new Date(recent.sent_at).getTime();
    if (Number.isFinite(elapsed) && elapsed < OTP_COOLDOWN_SECONDS * 1000) {
      const retryAfter = Math.max(1, Math.ceil((OTP_COOLDOWN_SECONDS * 1000 - elapsed) / 1000));
      throw new Error(`Tunggu ${retryAfter} detik sebelum meminta OTP baru.`);
    }
  }

  await db.prepare(
    "DELETE FROM customer_phone_otp_challenges WHERE expires_at <= datetime('now', '-1 day')",
  ).run().catch(() => undefined);

  const challengeId = crypto.randomUUID();
  const code = randomOtp();
  const saltHex = bytesToHex(crypto.getRandomValues(new Uint8Array(16)));
  const digest = await otpDigest(code, saltHex);
  const createdAt = new Date().toISOString();
  const expiresAt = new Date(Date.now() + OTP_TTL_MS).toISOString();

  await db.prepare(
    `INSERT INTO customer_phone_otp_challenges
      (id, customer_id, phone, otp_hash, otp_salt, expires_at, sent_at, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
  ).bind(challengeId, customerId, phone, digest, saltHex, expiresAt, createdAt, createdAt).run();

  try {
    await sendWhatsappOtp(phone, code);
  } catch (error) {
    await db.prepare(
      "UPDATE customer_phone_otp_challenges SET consumed_at = CURRENT_TIMESTAMP WHERE id = ?",
    ).bind(challengeId).run().catch(() => undefined);
    throw error;
  }

  // Concurrent send requests may both pass the cooldown check. Keep the newest
  // challenge active and only retire challenges that are strictly older, so
  // overlapping requests can never consume each other in both directions.
  await db.prepare(
    `UPDATE customer_phone_otp_challenges
     SET consumed_at = CURRENT_TIMESTAMP
     WHERE customer_id = ? AND id <> ? AND consumed_at IS NULL
       AND (
         datetime(created_at) < datetime(?)
         OR (datetime(created_at) = datetime(?) AND id < ?)
       )`,
  ).bind(customerId, challengeId, createdAt, createdAt, challengeId).run();

  return {
    challengeId,
    phone: maskPhone(phone),
    expiresInSeconds: Math.floor(OTP_TTL_MS / 1000),
    resendAfterSeconds: OTP_COOLDOWN_SECONDS,
  };
}

export async function verifyWhatsappOtpChallenge(customerId: string, challengeId: string, code: string) {
  const db = getD1();

  // Only the newest open challenge is valid. This makes concurrent resend
  // requests deterministic: the newest OTP wins instead of two challenges
  // invalidating each other.
  const newest = await db.prepare(
    `SELECT id FROM customer_phone_otp_challenges
     WHERE customer_id = ? AND consumed_at IS NULL
     ORDER BY datetime(created_at) DESC, id DESC LIMIT 1`,
  ).bind(customerId).first<{ id: string }>();
  if (!newest || newest.id !== challengeId) {
    throw new Error("OTP sudah tidak berlaku. Gunakan OTP terbaru yang dikirim.");
  }

  const claimed = await db.prepare(
    `UPDATE customer_phone_otp_challenges
     SET attempt_count = attempt_count + 1
     WHERE id = ? AND customer_id = ? AND consumed_at IS NULL
       AND attempt_count < ?
       AND datetime(expires_at) > datetime(?)`,
  ).bind(challengeId, customerId, OTP_MAX_ATTEMPTS, new Date().toISOString()).run();

  if (Number(claimed.meta.changes ?? 0) === 0) {
    const current = await db.prepare(
      `SELECT expires_at, attempt_count, consumed_at
       FROM customer_phone_otp_challenges
       WHERE id = ? AND customer_id = ? LIMIT 1`,
    ).bind(challengeId, customerId).first<Pick<ChallengeRow, "expires_at" | "attempt_count" | "consumed_at">>();

    if (!current || current.consumed_at) {
      throw new Error("OTP sudah tidak berlaku. Kirim OTP baru.");
    }
    if (new Date(current.expires_at).getTime() <= Date.now()) {
      await db.prepare(
        "UPDATE customer_phone_otp_challenges SET consumed_at = CURRENT_TIMESTAMP WHERE id = ? AND consumed_at IS NULL",
      ).bind(challengeId).run();
      throw new Error("OTP sudah kedaluwarsa. Kirim OTP baru.");
    }
    throw new Error("Batas percobaan OTP tercapai. Kirim OTP baru.");
  }

  const challenge = await db.prepare(
    `SELECT id, customer_id, phone, otp_hash, otp_salt, expires_at, attempt_count, sent_at, consumed_at
     FROM customer_phone_otp_challenges
     WHERE id = ? AND customer_id = ? LIMIT 1`,
  ).bind(challengeId, customerId).first<ChallengeRow>();
  if (!challenge || challenge.consumed_at) {
    throw new Error("OTP sudah tidak berlaku. Kirim OTP baru.");
  }

  const digest = await otpDigest(code, challenge.otp_salt);
  if (!constantTimeEqual(digest, challenge.otp_hash)) {
    if (challenge.attempt_count >= OTP_MAX_ATTEMPTS) {
      await db.prepare(
        "UPDATE customer_phone_otp_challenges SET consumed_at = CURRENT_TIMESTAMP WHERE id = ? AND consumed_at IS NULL",
      ).bind(challengeId).run();
      throw new Error("OTP salah dan batas percobaan tercapai. Kirim OTP baru.");
    }
    throw new Error(`OTP salah. Sisa percobaan: ${OTP_MAX_ATTEMPTS - challenge.attempt_count}.`);
  }

  try {
    await db.batch([
      db.prepare(
        `UPDATE customer_users
         SET phone = ?, phone_verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
      ).bind(challenge.phone, customerId),
      db.prepare(
        "UPDATE customer_phone_otp_challenges SET consumed_at = CURRENT_TIMESTAMP WHERE id = ? AND consumed_at IS NULL",
      ).bind(challengeId),
      db.prepare(
        `UPDATE customer_phone_otp_challenges
         SET consumed_at = CURRENT_TIMESTAMP
         WHERE customer_id = ? AND id <> ? AND consumed_at IS NULL`,
      ).bind(customerId, challengeId),
    ]);
  } catch (error) {
    if (error instanceof Error && /UNIQUE/i.test(error.message)) {
      throw new Error("Nomor WhatsApp ini sudah terhubung ke akun lain.");
    }
    throw error;
  }

  return { phone: challenge.phone, phoneMasked: maskPhone(challenge.phone) };
}
