import { getD1 } from "@/db";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export type PaymentEnvironment = "sandbox" | "production";
export type PaymentProvider = "doku" | "midtrans";
export type PaymentProfileMode = "checkout" | "direct" | "snap";

type RuntimeLike = Record<string, unknown> & {
  DB?: D1Database;
  INTEGRATION_ENCRYPTION_KEY?: string;
  PUBLIC_BASE_URL?: string;
};
type EncryptedValue = { v: 1; iv: string; data: string };
type ProfileRow = { encrypted_config: string; updated_at: string };

const encoder = new TextEncoder();
const decoder = new TextDecoder();

function runtime() { return getRuntimeEnv<RuntimeLike>(); }
function secretFrom(source: RuntimeLike) {
  const value = source.INTEGRATION_ENCRYPTION_KEY?.trim();
  if (!value || value.length < 32) throw new Error("INTEGRATION_ENCRYPTION_KEY belum siap.");
  return value;
}
function secret() { return secretFrom(runtime()); }

async function ensureTables(db = getD1()) {
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
async function encryptWithSecret(values: Record<string, string>, secretValue: string) {
  const key = await deriveKey(secretValue);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, encoder.encode(JSON.stringify(values)));
  return JSON.stringify({ v: 1, iv: Buffer.from(iv).toString("base64"), data: Buffer.from(data).toString("base64") } satisfies EncryptedValue);
}
async function decryptWithSecret(value: string, secretValue: string) {
  const payload = JSON.parse(value) as EncryptedValue;
  if (payload.v !== 1 || !payload.iv || !payload.data) throw new Error("Format kredensial tidak valid.");
  const key = await deriveKey(secretValue);
  const raw = await crypto.subtle.decrypt({ name: "AES-GCM", iv: new Uint8Array(Buffer.from(payload.iv, "base64")) }, key, new Uint8Array(Buffer.from(payload.data, "base64")));
  const parsed = JSON.parse(decoder.decode(raw));
  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("Kredensial tidak valid.");
  return Object.fromEntries(Object.entries(parsed).flatMap(([key, field]) => typeof field === "string" ? [[key, field]] : [])) as Record<string, string>;
}
async function encrypt(values: Record<string, string>) { return encryptWithSecret(values, secret()); }
async function decrypt(value: string) { return decryptWithSecret(value, secret()); }

async function settings(db = getD1()) {
  await ensureTables(db);
  const rows = await db.prepare("SELECT setting_key, value FROM integration_settings").all<{ setting_key: string; value: string }>();
  return new Map(rows.results.map((row) => [row.setting_key, row.value]));
}
function env(value: string | undefined): PaymentEnvironment { return value === "production" ? "production" : "sandbox"; }
function gateway(value: string | undefined): PaymentProvider { return value === "midtrans" ? "midtrans" : "doku"; }

export async function getActivePaymentModes() {
  const current = await settings();
  return {
    dokuEnvironment: env(current.get("doku_environment")),
    midtransEnvironment: env(current.get("midtrans_environment")),
    walletTopupGateway: gateway(current.get("wallet_topup_gateway")),
    dokuMode: "checkout" as const,
    midtransMode: "snap" as const,
  };
}

export async function savePaymentModeSelections(input: {
  dokuEnvironment?: PaymentEnvironment;
  midtransEnvironment?: PaymentEnvironment;
  walletTopupGateway?: PaymentProvider;
}) {
  await ensureTables();
  const db = getD1();
  const rows: Array<[string, string | undefined]> = [
    ["doku_environment", input.dokuEnvironment],
    ["midtrans_environment", input.midtransEnvironment],
    ["wallet_topup_gateway", input.walletTopupGateway],
  ];
  const statements = rows.flatMap(([key, value]) => value ? [db.prepare(`INSERT INTO integration_settings (setting_key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP`).bind(key, value)] : []);
  if (statements.length) await db.batch(statements);
}

const allowedFields: Record<PaymentProfileMode, readonly string[]> = {
  checkout: ["clientId", "secretKey", "apiUrl"],
  // Legacy Direct profiles remain readable only so an existing Client ID /
  // Secret Key can be carried over without asking the owner to re-enter them.
  direct: ["clientId", "secretKey", "privateKey", "privateKeyPassphrase", "apiUrl", "qrisMerchantId", "qrisTerminalId", "qrisPostalCode", "vaConfigJson"],
  snap: ["serverKey", "clientKey"],
};

async function saveProfile(input: {
  provider: PaymentProvider;
  mode: PaymentProfileMode;
  environment: PaymentEnvironment;
  values: Record<string, string>;
}) {
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

export async function savePaymentGatewayProfile(input: {
  provider: PaymentProvider;
  mode: "checkout" | "snap";
  environment: PaymentEnvironment;
  values: Record<string, string>;
}) {
  if ((input.provider === "doku" && input.mode !== "checkout") || (input.provider === "midtrans" && input.mode !== "snap")) {
    throw new Error("Mode gateway tidak valid.");
  }
  if (input.provider === "doku") {
    const existing = await profile("doku", "checkout", input.environment) ?? await profile("doku", "direct", input.environment);
    if (!input.values.apiUrl?.trim() && !existing?.apiUrl?.trim()) {
      input = {
        ...input,
        values: {
          ...input.values,
          apiUrl: input.environment === "production"
            ? "https://api.doku.com"
            : "https://api-sandbox.doku.com",
        },
      };
    }
  }
  return saveProfile(input);
}

async function profile(provider: PaymentProvider, mode: PaymentProfileMode, environment: PaymentEnvironment, db = getD1(), explicitSecret?: string) {
  await ensureTables(db);
  const row = await db.prepare("SELECT encrypted_config FROM integration_profiles WHERE provider = ? AND mode = ? AND environment = ? LIMIT 1")
    .bind(provider, mode, environment).first<ProfileRow>();
  if (!row?.encrypted_config) return null;
  try { return explicitSecret ? await decryptWithSecret(row.encrypted_config, explicitSecret) : await decrypt(row.encrypted_config); } catch { return null; }
}

export async function getHostedGatewayProfileForEnvironment(provider: "midtrans", mode: "snap", environment: PaymentEnvironment) {
  return profile(provider, mode, environment);
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

function checkoutReady(values: Record<string, string> | null) {
  if (!values?.clientId || !values.secretKey || !values.apiUrl) return false;
  try {
    return new URL(values.apiUrl).protocol === "https:";
  } catch {
    return false;
  }
}

export async function getPaymentModeOverview() {
  const modes = await getActivePaymentModes();
  const [dokuSandbox, dokuProduction, midtransSandbox, midtransProduction] = await Promise.all([
    profile("doku", "checkout", "sandbox").then(async (value) => value ?? profile("doku", "direct", "sandbox")),
    profile("doku", "checkout", "production").then(async (value) => value ?? profile("doku", "direct", "production")),
    profile("midtrans", "snap", "sandbox"),
    profile("midtrans", "snap", "production"),
  ]);
  const configured = {
    doku: {
      sandbox: checkoutReady(dokuSandbox),
      production: checkoutReady(dokuProduction),
    },
    midtrans: {
      sandbox: Boolean(midtransSandbox?.serverKey && midtransSandbox.clientKey),
      production: Boolean(midtransProduction?.serverKey && midtransProduction.clientKey),
    },
  };
  const base = (() => { try { return new URL(runtime().PUBLIC_BASE_URL || "").origin; } catch { return ""; } })();
  return {
    ...modes,
    dokuCheckoutConfigured: configured.doku[modes.dokuEnvironment],
    midtransSnapConfigured: configured.midtrans[modes.midtransEnvironment],
    configured,
    callbacks: {
      dokuNotification: `${base}/api/payments/doku/callback`,
      midtransSnapNotification: `${base}/api/payments/midtrans/snap/notification`,
      paymentReturn: `${base}/payment`,
    },
  };
}

/** Injects encrypted DOKU Checkout credentials into runtime only on the server. */
export async function hydrateDokuCheckoutRuntimeEnv<T extends object>(sourceEnv: T): Promise<T> {
  const source = sourceEnv as T & RuntimeLike;
  const db = source.DB;
  const encryptionSecret = source.INTEGRATION_ENCRYPTION_KEY?.trim();
  if (!db || !encryptionSecret || encryptionSecret.length < 32) return sourceEnv;
  try {
    const current = await settings(db);
    const environment = env(current.get("doku_environment"));
    const [sandboxValues, productionValues] = await Promise.all([
      profile("doku", "checkout", "sandbox", db, encryptionSecret).then(async (value) => value ?? profile("doku", "direct", "sandbox", db, encryptionSecret)),
      profile("doku", "checkout", "production", db, encryptionSecret).then(async (value) => value ?? profile("doku", "direct", "production", db, encryptionSecret)),
    ]);
    const target: Record<string, unknown> = { ...source, DOKU_ENV: environment };
    const put = (key: string, value: string | undefined) => { if (value?.trim()) target[key] = value.trim(); };
    const applyProfile = (
      profileEnvironment: PaymentEnvironment,
      values: Record<string, string> | null,
    ) => {
      if (!values) return;
      const prefix = `DOKU_${profileEnvironment.toUpperCase()}_`;
      put(`${prefix}CLIENT_ID`, values.clientId);
      put(`${prefix}SECRET_KEY`, values.secretKey);
      put(`${prefix}API_URL`, values.apiUrl || (profileEnvironment === "production" ? "https://api.doku.com" : "https://api-sandbox.doku.com"));
    };
    // Keep both profiles hydrated so callbacks/reconciliation for an older
    // environment remain verifiable after Admin switches the active one.
    applyProfile("sandbox", sandboxValues);
    applyProfile("production", productionValues);
    return target as T;
  } catch {
    return sourceEnv;
  }
}
