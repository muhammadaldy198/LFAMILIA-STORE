import { getD1 } from "@/db";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export type DokuPaymentMode = "checkout" | "direct";
export type MidtransPaymentMode = "snap" | "bisnap";
export type PaymentEnvironment = "sandbox" | "production";
export type HostedProvider = "doku" | "midtrans";
export type HostedMode = "checkout" | "snap";

type RuntimeLike = { INTEGRATION_ENCRYPTION_KEY?: string; PUBLIC_BASE_URL?: string };
type EncryptedValue = { v: 1; iv: string; data: string };
type ProfileRow = { encrypted_config: string; updated_at: string };

const encoder = new TextEncoder();
const decoder = new TextDecoder();

function runtime() { return getRuntimeEnv<RuntimeLike>(); }
function secret() {
  const value = runtime().INTEGRATION_ENCRYPTION_KEY?.trim();
  if (!value || value.length < 32) throw new Error("INTEGRATION_ENCRYPTION_KEY belum siap.");
  return value;
}

async function ensureTables() {
  const db = getD1();
  await db.prepare(`CREATE TABLE IF NOT EXISTS integration_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL,
    mode TEXT NOT NULL,
    environment TEXT NOT NULL,
    encrypted_config TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(provider, mode, environment)
  )`).run();
  await db.prepare(`CREATE TABLE IF NOT EXISTS integration_settings (
    setting_key TEXT PRIMARY KEY NOT NULL,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`).run();
}

async function deriveKey(value: string) {
  const digest = await crypto.subtle.digest("SHA-256", encoder.encode(value));
  return crypto.subtle.importKey("raw", digest, "AES-GCM", false, ["encrypt", "decrypt"]);
}
async function encrypt(values: Record<string, string>) {
  const key = await deriveKey(secret());
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, encoder.encode(JSON.stringify(values)));
  return JSON.stringify({ v: 1, iv: Buffer.from(iv).toString("base64"), data: Buffer.from(data).toString("base64") } satisfies EncryptedValue);
}
async function decrypt(value: string) {
  const payload = JSON.parse(value) as EncryptedValue;
  if (payload.v !== 1 || !payload.iv || !payload.data) throw new Error("Format kredensial tidak valid.");
  const key = await deriveKey(secret());
  const raw = await crypto.subtle.decrypt({ name: "AES-GCM", iv: new Uint8Array(Buffer.from(payload.iv, "base64")) }, key, new Uint8Array(Buffer.from(payload.data, "base64")));
  const parsed = JSON.parse(decoder.decode(raw));
  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("Kredensial tidak valid.");
  return Object.fromEntries(Object.entries(parsed).flatMap(([key, field]) => typeof field === "string" ? [[key, field]] : [])) as Record<string, string>;
}

async function settings() {
  await ensureTables();
  const rows = await getD1().prepare("SELECT setting_key, value FROM integration_settings").all<{ setting_key: string; value: string }>();
  return new Map(rows.results.map((row) => [row.setting_key, row.value]));
}
function env(value: string | undefined): PaymentEnvironment { return value === "production" ? "production" : "sandbox"; }

export async function getActivePaymentModes() {
  const current = await settings();
  return {
    dokuMode: (current.get("doku_payment_mode") === "direct" ? "direct" : "checkout") as DokuPaymentMode,
    midtransMode: (current.get("midtrans_payment_mode") === "bisnap" ? "bisnap" : "snap") as MidtransPaymentMode,
    dokuEnvironment: env(current.get("doku_environment")),
    midtransEnvironment: env(current.get("midtrans_environment")),
  };
}

export async function savePaymentModeSelections(input: {
  dokuMode?: DokuPaymentMode;
  midtransMode?: MidtransPaymentMode;
  dokuEnvironment?: PaymentEnvironment;
  midtransEnvironment?: PaymentEnvironment;
}) {
  await ensureTables();
  const db = getD1();
  const rows: Array<[string, string | undefined]> = [
    ["doku_payment_mode", input.dokuMode],
    ["midtrans_payment_mode", input.midtransMode],
    ["doku_environment", input.dokuEnvironment],
    ["midtrans_environment", input.midtransEnvironment],
  ];
  const statements = rows.flatMap(([key, value]) => value ? [db.prepare(`INSERT INTO integration_settings (setting_key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP`).bind(key, value)] : []);
  if (statements.length) await db.batch(statements);
}

const allowedFields: Record<HostedMode, readonly string[]> = {
  checkout: ["clientId", "secretKey"],
  snap: ["serverKey", "clientKey"],
};

export async function saveHostedGatewayProfile(input: {
  provider: HostedProvider;
  mode: HostedMode;
  environment: PaymentEnvironment;
  values: Record<string, string>;
}) {
  if ((input.provider === "doku" && input.mode !== "checkout") || (input.provider === "midtrans" && input.mode !== "snap")) throw new Error("Mode gateway tidak valid.");
  await ensureTables();
  const db = getD1();
  const existing = await db.prepare("SELECT encrypted_config FROM integration_profiles WHERE provider = ? AND mode = ? AND environment = ? LIMIT 1")
    .bind(input.provider, input.mode, input.environment).first<ProfileRow>();
  let merged: Record<string, string> = {};
  if (existing?.encrypted_config) merged = await decrypt(existing.encrypted_config);
  for (const [key, value] of Object.entries(input.values)) {
    if (!allowedFields[input.mode].includes(key)) throw new Error(`Field ${key} tidak diizinkan.`);
    if (value.trim()) merged[key] = value.trim();
  }
  const encrypted = await encrypt(merged);
  await db.prepare(`INSERT INTO integration_profiles (provider, mode, environment, encrypted_config, updated_at)
    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(provider, mode, environment) DO UPDATE SET encrypted_config = excluded.encrypted_config, updated_at = CURRENT_TIMESTAMP`)
    .bind(input.provider, input.mode, input.environment, encrypted).run();
}

async function profile(provider: HostedProvider, mode: HostedMode, environment: PaymentEnvironment) {
  await ensureTables();
  const row = await getD1().prepare("SELECT encrypted_config FROM integration_profiles WHERE provider = ? AND mode = ? AND environment = ? LIMIT 1")
    .bind(provider, mode, environment).first<ProfileRow>();
  if (!row?.encrypted_config) return null;
  try { return await decrypt(row.encrypted_config); } catch { return null; }
}

export async function getHostedGatewayProfileForEnvironment(provider: HostedProvider, mode: HostedMode, environment: PaymentEnvironment) {
  return profile(provider, mode, environment);
}

export async function getDokuCheckoutConfig() {
  const { dokuEnvironment } = await getActivePaymentModes();
  const values = await profile("doku", "checkout", dokuEnvironment);
  if (!values?.clientId || !values.secretKey) throw new Error(`Kredensial DOKU Checkout ${dokuEnvironment} belum lengkap.`);
  return {
    environment: dokuEnvironment,
    clientId: values.clientId,
    secretKey: values.secretKey,
    apiOrigin: dokuEnvironment === "production" ? "https://api.doku.com" : "https://api-sandbox.doku.com",
  };
}

export async function getMidtransSnapConfig() {
  const { midtransEnvironment } = await getActivePaymentModes();
  const values = await profile("midtrans", "snap", midtransEnvironment);
  if (!values?.serverKey || !values.clientKey) throw new Error(`Kredensial Midtrans Snap ${midtransEnvironment} belum lengkap.`);
  return {
    environment: midtransEnvironment,
    serverKey: values.serverKey,
    clientKey: values.clientKey,
    snapOrigin: midtransEnvironment === "production" ? "https://app.midtrans.com" : "https://app.sandbox.midtrans.com",
    apiOrigin: midtransEnvironment === "production" ? "https://api.midtrans.com" : "https://api.sandbox.midtrans.com",
  };
}

export async function getPaymentModeOverview() {
  const modes = await getActivePaymentModes();
  const [doku, midtrans] = await Promise.all([
    profile("doku", "checkout", modes.dokuEnvironment),
    profile("midtrans", "snap", modes.midtransEnvironment),
  ]);
  const base = (() => { try { return new URL(runtime().PUBLIC_BASE_URL || "").origin; } catch { return ""; } })();
  return {
    ...modes,
    dokuCheckoutConfigured: Boolean(doku?.clientId && doku.secretKey),
    midtransSnapConfigured: Boolean(midtrans?.serverKey && midtrans.clientKey),
    callbacks: {
      dokuNotification: `${base}/api/payments/doku/callback`,
      midtransSnapNotification: `${base}/api/payments/midtrans/snap/notification`,
      paymentReturn: `${base}/payment`,
    },
  };
}
