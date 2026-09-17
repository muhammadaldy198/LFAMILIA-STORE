import { getD1 } from "@/db";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export type IntegrationProvider = "digiflazz" | "kokinpay" | "resend" | "relay" | "security";
export type IntegrationMode = "direct" | "service";
export type IntegrationEnvironment = "sandbox" | "production" | "development" | "global";

type RuntimeLike = Record<string, unknown> & {
  DB?: D1Database;
  INTEGRATION_ENCRYPTION_KEY?: string;
  PUBLIC_BASE_URL?: string;
  DIGIFLAZZ_ENV?: string;
  KOKINPAY_API_KEY?: string;
  RESEND_API_KEY?: string;
  RESEND_FROM_EMAIL?: string;
  RESEND_API_URL?: string;
  VOUCHER_DELIVERY_CHANNEL?: string;
  PROVIDER_RELAY_TOKEN?: string;
  PROVIDER_RELAY_HOSTS?: string;
  PROVIDER_RELAY_DIGIFLAZZ_ORIGIN?: string;
  VOUCHER_ENCRYPTION_KEY?: string;
};

type StoredProfile = {
  provider: IntegrationProvider;
  mode: IntegrationMode;
  environment: IntegrationEnvironment;
  encrypted_config: string;
  created_at: string;
  updated_at: string;
};

type StoredSetting = { setting_key: string; value: string };

type EncryptedValue = { v: 1; iv: string; data: string };

export type IntegrationProfileSummary = {
  provider: IntegrationProvider;
  mode: IntegrationMode;
  environment: IntegrationEnvironment;
  configured: boolean;
  configuredFields: string[];
  decryptionError: boolean;
  updatedAt: string;
};

export type IntegrationOverview = {
  encryptionReady: boolean;
  encryptionHint: string;
  selections: {
    digiflazzEnvironment: "development" | "production";
  };
  profiles: IntegrationProfileSummary[];
  callbacks: Array<{ id: string; label: string; description: string; kind: "notification" | "callback" | "fallback"; url: string }>;
};

export const profileFields: Record<string, readonly string[]> = {
  "digiflazz:direct": ["username", "apiKey", "transactionApiUrl", "priceListUrl", "webhookSecret"],
  "kokinpay:service": ["apiKey"],
  "resend:service": ["apiKey", "fromEmail", "apiUrl", "deliveryChannel"],
  "relay:service": ["digiflazzOrigin", "hosts", "token"],
  "security:service": ["voucherEncryptionKey"],
};

let schemaPromise: Promise<void> | null = null;
const encoder = new TextEncoder();
const decoder = new TextDecoder();

function profileFieldKey(provider: IntegrationProvider, mode: IntegrationMode) {
  return `${provider}:${mode}`;
}

function isProfileSupported(provider: IntegrationProvider, mode: IntegrationMode, environment: IntegrationEnvironment) {
  if (provider === "digiflazz") return mode === "direct" && (environment === "development" || environment === "production");
  return (provider === "kokinpay" || provider === "resend" || provider === "relay" || provider === "security")
    && mode === "service"
    && environment === "global";
}

function secretFrom(value: RuntimeLike) {
  const secret = value.INTEGRATION_ENCRYPTION_KEY?.trim();
  return secret && secret.length >= 32 ? secret : null;
}

function runtime() {
  return getRuntimeEnv<RuntimeLike>();
}

function withoutDashboardManagedRuntime(source: RuntimeLike) {
  const target: Record<string, unknown> = { ...source };
  const managedPrefixes = [
    "DOKU_",
    "MIDTRANS_",
    "DIGIFLAZZ_",
    "KOKINPAY_",
    "MELOSTORE_",
    "RESEND_",
    "PROVIDER_RELAY_",
  ];
  const managedKeys = new Set([
    "NICKNAME_API_KEY",
    "VOUCHER_DELIVERY_CHANNEL",
    "VOUCHER_ENCRYPTION_KEY",
  ]);

  for (const key of Object.keys(target)) {
    if (
      managedKeys.has(key) ||
      managedPrefixes.some((prefix) => key.startsWith(prefix))
    ) {
      delete target[key];
    }
  }

  return target;
}

async function ensureIntegrationTables(database: D1Database) {
  if (!schemaPromise) {
    schemaPromise = (async () => {
      await database.prepare(`CREATE TABLE IF NOT EXISTS integration_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider TEXT NOT NULL,
        mode TEXT NOT NULL,
        environment TEXT NOT NULL,
        encrypted_config TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(provider, mode, environment)
      )`).run();
      await database.prepare(`CREATE TABLE IF NOT EXISTS integration_settings (
        setting_key TEXT PRIMARY KEY NOT NULL,
        value TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )`).run();
    })().catch((error) => {
      schemaPromise = null;
      throw error;
    });
  }
  return schemaPromise;
}

async function deriveKey(secret: string) {
  const digest = await crypto.subtle.digest("SHA-256", encoder.encode(secret));
  return crypto.subtle.importKey("raw", digest, "AES-GCM", false, ["encrypt", "decrypt"]);
}

async function encryptConfig(secret: string, config: Record<string, string>) {
  const key = await deriveKey(secret);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const encrypted = await crypto.subtle.encrypt(
    { name: "AES-GCM", iv },
    key,
    encoder.encode(JSON.stringify(config)),
  );
  return JSON.stringify({
    v: 1,
    iv: Buffer.from(iv).toString("base64"),
    data: Buffer.from(encrypted).toString("base64"),
  } satisfies EncryptedValue);
}

async function decryptConfig(secret: string, encryptedValue: string) {
  const payload = JSON.parse(encryptedValue) as Partial<EncryptedValue>;
  if (payload.v !== 1 || !payload.iv || !payload.data) throw new Error("Format kredensial terenkripsi tidak valid.");
  const key = await deriveKey(secret);
  const decrypted = await crypto.subtle.decrypt(
    { name: "AES-GCM", iv: new Uint8Array(Buffer.from(payload.iv, "base64")) },
    key,
    new Uint8Array(Buffer.from(payload.data, "base64")),
  );
  const value = JSON.parse(decoder.decode(decrypted));
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("Kredensial terenkripsi tidak valid.");
  return Object.fromEntries(
    Object.entries(value).flatMap(([key, field]) => typeof field === "string" ? [[key, field]] : []),
  ) as Record<string, string>;
}

async function readStoredProfiles(database = getD1()) {
  await ensureIntegrationTables(database);
  const rows = await database.prepare(`SELECT provider, mode, environment, encrypted_config, created_at, updated_at
    FROM integration_profiles ORDER BY provider ASC, mode ASC, environment ASC`).all<StoredProfile>();
  return rows.results.filter((row) =>
    isProfileSupported(row.provider, row.mode, row.environment),
  );
}

async function readStoredSettings(database = getD1()) {
  await ensureIntegrationTables(database);
  const rows = await database.prepare("SELECT setting_key, value FROM integration_settings").all<StoredSetting>();
  return new Map(rows.results.map((row) => [row.setting_key, row.value]));
}

function valueOr<T extends string>(value: string | undefined, allowed: readonly T[], fallback: T) {
  return value && allowed.includes(value as T) ? value as T : fallback;
}

function publicBaseUrl(value: RuntimeLike) {
  try {
    return new URL(String(value.PUBLIC_BASE_URL || "")).origin;
  } catch {
    return "";
  }
}

function buildCallbacks(baseUrl: string) {
  const route = (path: string) => baseUrl ? `${baseUrl}${path}` : path;
  return [
    { id: "digiflazz", label: "DigiFlazz Webhook", description: "Webhook status fulfillment DigiFlazz.", kind: "callback" as const, url: route("/api/fulfillment/digiflazz/callback") },
  ];
}

export async function getIntegrationOverview(): Promise<IntegrationOverview> {
  const current = runtime();
  const database = getD1();
  const secret = secretFrom(current);
  const profiles = await readStoredProfiles(database);
  const configuredProfiles = await Promise.all(profiles.map(async (profile) => {
    let configuredFields: string[] = [];
    let decryptionError = false;
    if (secret) {
      try {
        const config = await decryptConfig(secret, profile.encrypted_config);
        const allowed = profileFields[profileFieldKey(profile.provider, profile.mode)] ?? [];
        configuredFields = Object.keys(config).filter((field) => allowed.includes(field));
      } catch {
        decryptionError = true;
      }
    }
    return {
      provider: profile.provider,
      mode: profile.mode,
      environment: profile.environment,
      configured: secret ? !decryptionError && configuredFields.length > 0 : Boolean(profile.encrypted_config),
      configuredFields,
      decryptionError,
      updatedAt: profile.updated_at,
    } satisfies IntegrationProfileSummary;
  }));
  const selected = await readStoredSettings(database);

  return {
    encryptionReady: Boolean(secret),
    encryptionHint: secret
      ? "Kredensial disimpan terenkripsi dan tidak ditampilkan kembali setelah disimpan."
      : "Tambahkan Cloudflare Secret INTEGRATION_ENCRYPTION_KEY (minimal 32 karakter) satu kali untuk mengaktifkan penyimpanan terenkripsi.",
    selections: {
      digiflazzEnvironment: valueOr(selected.get("digiflazz_environment") || current.DIGIFLAZZ_ENV, ["development", "production"] as const, "development"),
    },
    profiles: configuredProfiles,
    callbacks: buildCallbacks(publicBaseUrl(current)),
  };
}

export async function saveIntegrationProfile(input: {
  provider: IntegrationProvider;
  mode: IntegrationMode;
  environment: IntegrationEnvironment;
  values: Record<string, string>;
  clearFields?: string[];
}) {
  if (!isProfileSupported(input.provider, input.mode, input.environment)) throw new Error("Kombinasi provider, mode, dan environment tidak didukung.");
  const allowed = profileFields[profileFieldKey(input.provider, input.mode)] ?? [];
  const secret = secretFrom(runtime());
  if (!secret) throw new Error("INTEGRATION_ENCRYPTION_KEY belum dikonfigurasi di Cloudflare.");
  const database = getD1();
  await ensureIntegrationTables(database);
  const existing = await database.prepare(`SELECT encrypted_config FROM integration_profiles
    WHERE provider = ? AND mode = ? AND environment = ? LIMIT 1`)
    .bind(input.provider, input.mode, input.environment)
    .first<{ encrypted_config: string }>();
  let merged: Record<string, string> = {};
  if (existing?.encrypted_config) {
    try {
      merged = await decryptConfig(secret, existing.encrypted_config);
      merged = Object.fromEntries(
        Object.entries(merged).filter(([key]) => allowed.includes(key)),
      );
    } catch {
      throw new Error("Kredensial lama tidak dapat dibuka. Pastikan INTEGRATION_ENCRYPTION_KEY tidak berubah.");
    }
  }
  for (const [key, value] of Object.entries(input.values)) {
    if (!allowed.includes(key)) throw new Error(`Field ${key} tidak diizinkan untuk konfigurasi ini.`);
    const normalized = value.trim();
    if (normalized) merged[key] = normalized;
  }
  for (const key of input.clearFields ?? []) {
    if (!allowed.includes(key)) throw new Error(`Field ${key} tidak diizinkan untuk konfigurasi ini.`);
    delete merged[key];
  }
  const encrypted = await encryptConfig(secret, merged);
  await database.prepare(`INSERT INTO integration_profiles (provider, mode, environment, encrypted_config, updated_at)
    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(provider, mode, environment) DO UPDATE SET
      encrypted_config = excluded.encrypted_config,
      updated_at = CURRENT_TIMESTAMP`)
    .bind(input.provider, input.mode, input.environment, encrypted)
    .run();
  return { configuredFields: Object.keys(merged).sort() };
}

export async function saveIntegrationSelections(input: Partial<IntegrationOverview["selections"]>) {
  const normalized = {
    digiflazzEnvironment: input.digiflazzEnvironment && valueOr(input.digiflazzEnvironment, ["development", "production"] as const, "development"),
  };
  const values: Array<[string, string | undefined]> = [
    ["digiflazz_environment", normalized.digiflazzEnvironment],
  ];
  const database = getD1();
  await ensureIntegrationTables(database);
  const statements = values.flatMap(([key, value]) => value
    ? [database.prepare(`INSERT INTO integration_settings (setting_key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
      ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP`).bind(key, value)]
    : [],
  );
  if (statements.length) await database.batch(statements);
}

function put(target: Record<string, unknown>, key: string, value: string | undefined) {
  if (value?.trim()) target[key] = value.trim();
}


function applyDigiflazzConfig(target: Record<string, unknown>, environment: "development" | "production", config: Record<string, string>, active: boolean) {
  const prefix = `DIGIFLAZZ_${environment.toUpperCase()}_`;
  put(target, `${prefix}API_KEY`, config.apiKey);
  put(target, `${prefix}API_URL`, config.transactionApiUrl);
  put(target, `${prefix}PRICE_LIST_URL`, config.priceListUrl);
  if (active) {
    put(target, "DIGIFLAZZ_USERNAME", config.username);
    put(target, "DIGIFLAZZ_WEBHOOK_SECRET", config.webhookSecret);
  }
}

function applyKokinpayConfig(target: Record<string, unknown>, config: Record<string, string>) {
  put(target, "KOKINPAY_API_KEY", config.apiKey);
}
function applyResendConfig(target: Record<string, unknown>, config: Record<string, string>) {
  put(target, "RESEND_API_KEY", config.apiKey);
  put(target, "RESEND_FROM_EMAIL", config.fromEmail);
  put(target, "RESEND_API_URL", config.apiUrl);
  const channel = config.deliveryChannel?.trim().toLowerCase();
  if (channel === "website" || channel === "email") target.VOUCHER_DELIVERY_CHANNEL = channel;
}
function applyRelayConfig(target: Record<string, unknown>, config: Record<string, string>) {
  put(target, "PROVIDER_RELAY_DIGIFLAZZ_ORIGIN", config.digiflazzOrigin);
  put(target, "PROVIDER_RELAY_HOSTS", config.hosts);
  put(target, "PROVIDER_RELAY_TOKEN", config.token);
}
function applySecurityConfig(target: Record<string, unknown>, config: Record<string, string>) {
  put(target, "VOUCHER_ENCRYPTION_KEY", config.voucherEncryptionKey);
}

/** Merges encrypted panel credentials into the Worker runtime without exposing them to clients. */
export async function hydrateIntegrationRuntimeEnv<T extends object>(env: T): Promise<T> {
  const source = env as RuntimeLike;
  const database = source.DB;
  const secret = secretFrom(source);
  const systemOnly = withoutDashboardManagedRuntime(source);
  if (!database || !secret) return systemOnly as T;
  try {
    await ensureIntegrationTables(database);
    const [profiles, settings] = await Promise.all([readStoredProfiles(database), readStoredSettings(database)]);
    const target: Record<string, unknown> = { ...systemOnly };
    const digiflazzEnvironment = valueOr(settings.get("digiflazz_environment"), ["development", "production"] as const, "development");
    target.DIGIFLAZZ_ENV = digiflazzEnvironment;

    for (const profile of profiles) {
      let config: Record<string, string>;
      try {
        config = await decryptConfig(secret, profile.encrypted_config);
      } catch {
        continue;
      }
      if (profile.provider === "digiflazz" && profile.mode === "direct" && (profile.environment === "development" || profile.environment === "production")) {
        applyDigiflazzConfig(target, profile.environment, config, profile.environment === digiflazzEnvironment);
      }
      if (profile.provider === "kokinpay" && profile.mode === "service" && profile.environment === "global") applyKokinpayConfig(target, config);
      if (profile.provider === "resend" && profile.mode === "service" && profile.environment === "global") applyResendConfig(target, config);
      if (profile.provider === "relay" && profile.mode === "service" && profile.environment === "global") applyRelayConfig(target, config);
      if (profile.provider === "security" && profile.mode === "service" && profile.environment === "global") applySecurityConfig(target, config);
    }
    return target as T;
  } catch {
    // Fail closed: provider credentials are dashboard-managed and must never
    // fall back to stale Cloudflare Variables/Secrets.
    return systemOnly as T;
  }
}
