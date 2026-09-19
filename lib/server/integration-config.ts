import { getD1 } from "@/db";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export type IntegrationProvider = "digiflazz" | "kokinpay" | "google" | "whatsapp" | "resend" | "relay" | "security";
export type IntegrationMode = "direct" | "service";
export type IntegrationEnvironment = "sandbox" | "production" | "development" | "global";

type RuntimeLike = Record<string, unknown> & {
  DB?: D1Database;
  INTEGRATION_ENCRYPTION_KEY?: string;
  PUBLIC_BASE_URL?: string;
  DIGIFLAZZ_ENV?: string;
  KOKINPAY_API_KEY?: string;
  GOOGLE_OAUTH_CLIENT_ID?: string;
  WHATSAPP_GRAPH_API_URL?: string;
  WHATSAPP_ACCESS_TOKEN?: string;
  WHATSAPP_PHONE_NUMBER_ID?: string;
  WHATSAPP_OTP_TEMPLATE_NAME?: string;
  WHATSAPP_OTP_TEMPLATE_LANGUAGE?: string;
  WHATSAPP_OTP_BUTTON_SUBTYPE?: string;
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
  "google:service": ["clientId"],
  "whatsapp:service": ["graphApiUrl", "accessToken", "phoneNumberId", "templateName", "templateLanguage", "buttonSubtype"],
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
  return (provider === "kokinpay" || provider === "google" || provider === "whatsapp" || provider === "resend" || provider === "relay" || provider === "security")
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
    "GOOGLE_OAUTH_",
    "WHATSAPP_",
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

export type IntegrationProfileSnapshot = {
  provider: IntegrationProvider;
  mode: IntegrationMode;
  environment: IntegrationEnvironment;
  encryptedConfig: string | null;
  createdAt: string | null;
  updatedAt: string | null;
};

export type IntegrationSettingSnapshot = {
  settingKey: "digiflazz_environment";
  value: string | null;
  updatedAt: string | null;
};

function guardedOwnershipClause() {
  return `EXISTS (
    SELECT 1 FROM digiflazz_runtime_state
    WHERE id = 1
      AND maintenance_token = ?
      AND maintenance_until > CURRENT_TIMESTAMP
      AND EXISTS (
        SELECT 1 FROM digiflazz_pricelist_sync_state
        WHERE id = 1 AND lock_token = ? AND locked_until > CURRENT_TIMESTAMP
      )
  )`;
}

export async function captureIntegrationProfileSnapshot(
  provider: IntegrationProvider,
  mode: IntegrationMode,
  environment: IntegrationEnvironment,
): Promise<IntegrationProfileSnapshot> {
  const database = getD1();
  await ensureIntegrationTables(database);
  const row = await database.prepare(`
    SELECT encrypted_config, created_at, updated_at
    FROM integration_profiles
    WHERE provider = ? AND mode = ? AND environment = ?
    LIMIT 1
  `).bind(provider, mode, environment).first<{
    encrypted_config: string;
    created_at: string;
    updated_at: string;
  }>();
  return {
    provider,
    mode,
    environment,
    encryptedConfig: row?.encrypted_config ?? null,
    createdAt: row?.created_at ?? null,
    updatedAt: row?.updated_at ?? null,
  };
}

export async function restoreIntegrationProfileSnapshot(
  snapshot: IntegrationProfileSnapshot,
  expectedCurrent: IntegrationProfileSnapshot | null,
  guardToken: string,
) {
  const database = getD1();
  await ensureIntegrationTables(database);
  const ownership = guardedOwnershipClause();
  const expectedConfig = expectedCurrent?.encryptedConfig ?? null;
  const expectedUpdatedAt = expectedCurrent?.updatedAt ?? null;

  if (snapshot.encryptedConfig === null) {
    if (!expectedConfig || !expectedUpdatedAt) return false;
    const result = await database.prepare(`
      DELETE FROM integration_profiles
      WHERE provider = ? AND mode = ? AND environment = ?
        AND encrypted_config = ?
        AND updated_at = ?
        AND ${ownership}
    `).bind(
      snapshot.provider,
      snapshot.mode,
      snapshot.environment,
      expectedConfig,
      expectedUpdatedAt,
      guardToken,
      guardToken,
    ).run();
    return Number(result.meta.changes ?? 0) > 0;
  }

  if (!snapshot.createdAt || !snapshot.updatedAt) return false;
  if (!expectedConfig || !expectedUpdatedAt) return false;
  const expectedPredicate = "AND encrypted_config = ? AND updated_at = ?";
  const statement = database.prepare(`
    UPDATE integration_profiles
       SET encrypted_config = ?, created_at = ?, updated_at = ?
     WHERE provider = ? AND mode = ? AND environment = ?
       ${expectedPredicate}
       AND ${ownership}
  `);
  const args: unknown[] = [
    snapshot.encryptedConfig,
    snapshot.createdAt,
    snapshot.updatedAt,
    snapshot.provider,
    snapshot.mode,
    snapshot.environment,
  ];
  args.push(expectedConfig, expectedUpdatedAt);
  args.push(guardToken, guardToken);
  const result = await statement.bind(...args).run();
  return Number(result.meta.changes ?? 0) > 0;
}

export async function captureIntegrationSettingSnapshot(
  settingKey: IntegrationSettingSnapshot["settingKey"],
): Promise<IntegrationSettingSnapshot> {
  const database = getD1();
  await ensureIntegrationTables(database);
  const row = await database.prepare(
    "SELECT value, updated_at FROM integration_settings WHERE setting_key = ? LIMIT 1",
  ).bind(settingKey).first<{ value: string; updated_at: string }>();
  return { settingKey, value: row?.value ?? null, updatedAt: row?.updated_at ?? null };
}

export async function restoreIntegrationSettingSnapshot(
  snapshot: IntegrationSettingSnapshot,
  expectedCurrent: IntegrationSettingSnapshot | null,
  guardToken: string,
) {
  const database = getD1();
  await ensureIntegrationTables(database);
  const ownership = guardedOwnershipClause();
  const expectedValue = expectedCurrent?.value ?? null;
  const expectedUpdatedAt = expectedCurrent?.updatedAt ?? null;

  if (snapshot.value === null) {
    if (!expectedValue || !expectedUpdatedAt) return false;
    const result = await database.prepare(`
      DELETE FROM integration_settings
      WHERE setting_key = ? AND value = ? AND updated_at = ? AND ${ownership}
    `).bind(snapshot.settingKey, expectedValue, expectedUpdatedAt, guardToken, guardToken).run();
    return Number(result.meta.changes ?? 0) > 0;
  }

  if (!snapshot.updatedAt) return false;
  if (!expectedValue || !expectedUpdatedAt) return false;
  const statement = database.prepare(`
    UPDATE integration_settings
       SET value = ?, updated_at = ?
     WHERE setting_key = ?
       AND value = ?
       AND updated_at = ?
       AND ${ownership}
  `);
  const args: unknown[] = [
    snapshot.value,
    snapshot.updatedAt,
    snapshot.settingKey,
    expectedValue,
    expectedUpdatedAt,
    guardToken,
    guardToken,
  ];
  const result = await statement.bind(...args).run();
  return Number(result.meta.changes ?? 0) > 0;
}

export async function saveIntegrationProfile(input: {
  provider: IntegrationProvider;
  mode: IntegrationMode;
  environment: IntegrationEnvironment;
  values: Record<string, string>;
  clearFields?: string[];
  guardToken?: string;
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
  const guardToken = input.guardToken?.trim() || null;
  const writeSql = guardToken
    ? `INSERT INTO integration_profiles (provider, mode, environment, encrypted_config, updated_at)
       SELECT ?, ?, ?, ?, strftime('%Y-%m-%d %H:%M:%f', 'now')
       WHERE ${guardedOwnershipClause()}
       ON CONFLICT(provider, mode, environment) DO UPDATE SET
         encrypted_config = excluded.encrypted_config,
         updated_at = strftime('%Y-%m-%d %H:%M:%f', 'now')
       RETURNING encrypted_config, created_at, updated_at`
    : `INSERT INTO integration_profiles (provider, mode, environment, encrypted_config, updated_at)
       VALUES (?, ?, ?, ?, strftime('%Y-%m-%d %H:%M:%f', 'now'))
       ON CONFLICT(provider, mode, environment) DO UPDATE SET
         encrypted_config = excluded.encrypted_config,
         updated_at = strftime('%Y-%m-%d %H:%M:%f', 'now')
       RETURNING encrypted_config, created_at, updated_at`;
  const bindArgs: unknown[] = [input.provider, input.mode, input.environment, encrypted];
  if (guardToken) bindArgs.push(guardToken, guardToken);
  const committed = await database.prepare(writeSql)
    .bind(...bindArgs)
    .first<{ encrypted_config: string; created_at: string; updated_at: string }>();
  if (!committed) {
    if (guardToken) {
      throw new Error("Guard konfigurasi DigiFlazz kedaluwarsa sebelum profile dapat disimpan.");
    }
    throw new Error("Konfigurasi integrasi gagal dikonfirmasi setelah disimpan.");
  }
  return {
    configuredFields: Object.keys(merged).sort(),
    committedSnapshot: {
      provider: input.provider,
      mode: input.mode,
      environment: input.environment,
      encryptedConfig: committed.encrypted_config,
      createdAt: committed.created_at,
      updatedAt: committed.updated_at,
    } satisfies IntegrationProfileSnapshot,
  };
}

export async function saveIntegrationSelections(input: Partial<IntegrationOverview["selections"]>) {
  const normalized = input.digiflazzEnvironment
    ? valueOr(input.digiflazzEnvironment, ["development", "production"] as const, "development")
    : null;
  if (!normalized) return { committedSnapshot: null };

  const database = getD1();
  await ensureIntegrationTables(database);
  const committed = await database.prepare(`
    INSERT INTO integration_settings (setting_key, value, updated_at)
    VALUES ('digiflazz_environment', ?, strftime('%Y-%m-%d %H:%M:%f', 'now'))
    ON CONFLICT(setting_key) DO UPDATE SET
      value = excluded.value,
      updated_at = strftime('%Y-%m-%d %H:%M:%f', 'now')
    RETURNING value, updated_at
  `).bind(normalized).first<{ value: string; updated_at: string }>();
  if (!committed) throw new Error("Pilihan environment DigiFlazz gagal dikonfirmasi setelah disimpan.");
  return {
    committedSnapshot: {
      settingKey: "digiflazz_environment",
      value: committed.value,
      updatedAt: committed.updated_at,
    } satisfies IntegrationSettingSnapshot,
  };
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
function applyGoogleConfig(target: Record<string, unknown>, config: Record<string, string>) {
  put(target, "GOOGLE_OAUTH_CLIENT_ID", config.clientId);
}
function applyWhatsappConfig(target: Record<string, unknown>, config: Record<string, string>) {
  put(target, "WHATSAPP_GRAPH_API_URL", config.graphApiUrl);
  put(target, "WHATSAPP_ACCESS_TOKEN", config.accessToken);
  put(target, "WHATSAPP_PHONE_NUMBER_ID", config.phoneNumberId);
  put(target, "WHATSAPP_OTP_TEMPLATE_NAME", config.templateName);
  put(target, "WHATSAPP_OTP_TEMPLATE_LANGUAGE", config.templateLanguage);
  put(target, "WHATSAPP_OTP_BUTTON_SUBTYPE", config.buttonSubtype);
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
      if (profile.provider === "google" && profile.mode === "service" && profile.environment === "global") applyGoogleConfig(target, config);
      if (profile.provider === "whatsapp" && profile.mode === "service" && profile.environment === "global") applyWhatsappConfig(target, config);
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
