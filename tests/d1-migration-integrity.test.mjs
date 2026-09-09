import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const drizzleDir = path.join(root, "drizzle");

function migrationFiles() {
  return fs.readdirSync(drizzleDir)
    .filter((name) => /^\d{4}_.+\.sql$/.test(name))
    .sort();
}

function migratedDatabase() {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  for (const migration of migrationFiles()) {
    const sql = fs.readFileSync(path.join(drizzleDir, migration), "utf8")
      .replaceAll("--> statement-breakpoint", "");
    db.exec(sql);
  }
  return db;
}

function repairColumns() {
  const source = fs.readFileSync(path.join(root, "lib/server/database-repair.ts"), "utf8");
  return [...source.matchAll(/\[\s*"([A-Za-z0-9_]+)"\s*,\s*"([A-Za-z0-9_]+)"\s*,\s*"[^"]+"/g)]
    .map((match) => [match[1], match[2]]);
}

test("D1 migration numeric prefixes have no new collisions", () => {
  const counts = new Map();
  for (const name of migrationFiles()) {
    const prefix = name.slice(0, 4);
    counts.set(prefix, (counts.get(prefix) ?? 0) + 1);
  }
  const duplicatePrefixes = [...counts.entries()]
    .filter(([, count]) => count > 1)
    .map(([prefix]) => prefix)
    .sort();
  assert.deepEqual(duplicatePrefixes, ["0006"]);
});

test("runtime compatibility repair is limited to documented legacy gaps", () => {
  const db = migratedDatabase();
  const missing = [];

  for (const [table, column] of repairColumns()) {
    const columns = new Set(
      db.prepare(`PRAGMA table_info(${table})`).all().map((row) => row.name),
    );
    if (!columns.has(column)) missing.push(`${table}.${column}`);
  }

  const expectedLegacyOnly = [
    "product_packages.package_group",
    "products.package_tabs_enabled",
    "products.package_tabs_json",
    "store_settings.support_widget_enabled",
  ].sort();

  assert.deepEqual(missing.sort(), expectedLegacyOnly);
});

test("DOKU migration owns payment columns without destructive transaction cleanup", () => {
  const migration = fs.readFileSync(
    path.join(drizzleDir, "0023_doku_digiflazz_reset.sql"),
    "utf8",
  );
  for (const column of [
    "doku_topup_enabled",
    "doku_checkout_enabled",
    "doku_request_id",
    "doku_token_id",
    "doku_payment_url",
    "doku_expired_at",
  ]) {
    assert.match(migration, new RegExp(column));
  }
  for (const table of ["order_events", "voucher_deliveries", "orders", "wallet_transactions", "wallet_topups"]) {
    assert.doesNotMatch(migration, new RegExp(`DELETE FROM ${table}`));
  }
});

test("runtime DOKU preparation never deletes transactional data", () => {
  const source = fs.readFileSync(
    path.join(root, "lib/server/doku-database-preparation.ts"),
    "utf8",
  );
  for (const table of ["order_events", "voucher_deliveries", "orders", "wallet_transactions", "wallet_topups"]) {
    assert.doesNotMatch(source, new RegExp(`DELETE FROM ${table}`));
  }
});

test("DOKU Direct API migration adds customer-facing payment artifacts", () => {
  const migration = fs.readFileSync(
    path.join(drizzleDir, "0025_doku_direct_api.sql"),
    "utf8",
  );
  for (const column of [
    "doku_reference_no",
    "doku_payment_no",
    "doku_qr_content",
    "doku_payment_name",
    "doku_status_checked_at",
  ]) {
    assert.match(migration, new RegExp(column));
  }
});

test("external checkout migration adds a unique retry key", () => {
  const migration = fs.readFileSync(
    path.join(drizzleDir, "0026_external_checkout_idempotency.sql"),
    "utf8",
  );
  assert.match(migration, /external_checkout_key/);
  assert.match(migration, /orders_external_checkout_key_unique/);
  assert.match(migration, /WHERE `external_checkout_key` IS NOT NULL/);
});

test("unexpected D1 repair errors are not swallowed", () => {
  const source = fs.readFileSync(path.join(root, "lib/server/database-repair.ts"), "utf8");
  assert.match(source, /if \(\/no such table\/i\.test\(message\)\)/);
  assert.match(source, /throw error/);
});

test("feature modules do not run ad-hoc ALTER TABLE repairs", () => {
  const products = fs.readFileSync(path.join(root, "lib/server/products.ts"), "utf8");
  assert.doesNotMatch(products, /ALTER TABLE/);
  assert.match(products, /ensureLegacyDatabaseColumns\(\)/);
});
