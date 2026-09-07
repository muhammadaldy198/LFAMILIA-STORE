import { getD1 } from "@/db";

const MIGRATION_MARKER = "doku_migration_completed";

const requiredColumns: Array<[table: string, column: string, definition: string]> = [
  ["wallet_settings", "doku_topup_enabled", "doku_topup_enabled INTEGER DEFAULT 0 NOT NULL"],
  ["wallet_settings", "doku_checkout_enabled", "doku_checkout_enabled INTEGER DEFAULT 0 NOT NULL"],
  ["wallet_topups", "doku_request_id", "doku_request_id TEXT"],
  ["wallet_topups", "doku_token_id", "doku_token_id TEXT"],
  ["wallet_topups", "doku_payment_url", "doku_payment_url TEXT"],
  ["wallet_topups", "doku_expired_at", "doku_expired_at TEXT"],
  ["orders", "doku_request_id", "doku_request_id TEXT"],
  ["orders", "doku_token_id", "doku_token_id TEXT"],
  ["orders", "doku_payment_url", "doku_payment_url TEXT"],
  ["orders", "doku_expired_at", "doku_expired_at TEXT"],
];

async function ensureMarkerTable() {
  const db = getD1();
  await db.prepare(`CREATE TABLE IF NOT EXISTS integration_settings (
    setting_key TEXT PRIMARY KEY NOT NULL,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`).run();
}

async function tableColumns(table: string) {
  const result = await getD1()
    .prepare(`PRAGMA table_info(${table})`)
    .all<{ name: string }>();
  return new Set(result.results.map((row) => row.name));
}

async function markerExists() {
  await ensureMarkerTable();
  const row = await getD1()
    .prepare("SELECT value FROM integration_settings WHERE setting_key = ? LIMIT 1")
    .bind(MIGRATION_MARKER)
    .first<{ value: string }>();
  return row?.value === "1";
}

export async function getDokuDatabasePreparationStatus() {
  const missing: string[] = [];
  for (const [table, column] of requiredColumns) {
    try {
      const columns = await tableColumns(table);
      if (!columns.has(column)) missing.push(`${table}.${column}`);
    } catch {
      missing.push(`${table}.${column}`);
    }
  }

  const completed = await markerExists();
  return {
    completed,
    schemaReady: missing.length === 0,
    missingColumns: missing,
    safeToRun: !completed,
  };
}

async function disableLegacyPaymentToggles() {
  const columns = await tableColumns("wallet_settings");
  const assignments = [
    "doku_topup_enabled = 0",
    "doku_checkout_enabled = 0",
  ];
  for (const legacy of [
    "midtrans_topup_enabled",
    "midtrans_checkout_enabled",
    "ipaymu_topup_enabled",
    "ipaymu_checkout_enabled",
  ]) {
    if (columns.has(legacy)) assignments.push(`${legacy} = 0`);
  }
  await getD1()
    .prepare(`UPDATE wallet_settings SET ${assignments.join(", ")}, updated_at = CURRENT_TIMESTAMP WHERE id = 1`)
    .run();
}

export async function prepareDokuDatabase() {
  await ensureMarkerTable();
  if (await markerExists()) {
    return {
      ok: true as const,
      alreadyCompleted: true,
      ...(await getDokuDatabasePreparationStatus()),
    };
  }

  const db = getD1();
  for (const [table, column, definition] of requiredColumns) {
    const columns = await tableColumns(table);
    if (!columns.has(column)) {
      await db.prepare(`ALTER TABLE ${table} ADD COLUMN ${definition}`).run();
    }
  }

  await disableLegacyPaymentToggles();

  await db.batch([
    db.prepare("DELETE FROM order_events"),
    db.prepare("DELETE FROM voucher_deliveries"),
    db.prepare("DELETE FROM orders"),
    db.prepare("DELETE FROM wallet_transactions"),
    db.prepare("DELETE FROM wallet_topups"),
    db.prepare("DELETE FROM integration_profiles WHERE provider IN ('midtrans', 'ipaymu', 'vippayment')"),
    db.prepare("DELETE FROM integration_settings WHERE setting_key IN ('midtrans_mode', 'midtrans_environment', 'ipaymu_environment', 'vippayment_environment')"),
    db.prepare(`UPDATE faq_entries
      SET question = replace(replace(question, 'iPaymu', 'DOKU'), 'Midtrans', 'DOKU'),
          answer = replace(replace(answer, 'iPaymu', 'DOKU'), 'Midtrans', 'DOKU')`),
    db.prepare(`INSERT INTO integration_settings (setting_key, value, updated_at)
      VALUES (?, '1', CURRENT_TIMESTAMP)
      ON CONFLICT(setting_key) DO UPDATE SET value = '1', updated_at = CURRENT_TIMESTAMP`)
      .bind(MIGRATION_MARKER),
  ]);

  return {
    ok: true as const,
    alreadyCompleted: false,
    ...(await getDokuDatabasePreparationStatus()),
  };
}
