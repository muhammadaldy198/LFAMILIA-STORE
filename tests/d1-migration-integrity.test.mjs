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

test("runtime compatibility repair is limited to the documented legacy gap", () => {
  const db = migratedDatabase();
  const missing = [];

  for (const [table, column] of repairColumns()) {
    const columns = new Set(
      db.prepare(`PRAGMA table_info(${table})`).all().map((row) => row.name),
    );
    if (!columns.has(column)) missing.push(`${table}.${column}`);
  }

  const expectedLegacyOnly = [
    "orders.midtrans_expired_at",
    "orders.midtrans_mode",
    "orders.midtrans_payment_name",
    "orders.midtrans_payment_no",
    "product_packages.package_group",
    "products.package_tabs_enabled",
    "products.package_tabs_json",
    "store_settings.support_widget_enabled",
    "wallet_topups.midtrans_expired_at",
    "wallet_topups.midtrans_mode",
    "wallet_topups.midtrans_payment_name",
    "wallet_topups.midtrans_payment_no",
  ].sort();

  assert.deepEqual(missing.sort(), expectedLegacyOnly);
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
  const repair = fs.readFileSync(path.join(root, "lib/server/database-repair.ts"), "utf8");
  assert.match(repair, /\["products", "description", "description TEXT"\]/);
});

test("Drizzle product metadata reflects active runtime columns", () => {
  const schema = fs.readFileSync(path.join(root, "db/schema.ts"), "utf8");
  for (const field of [
    "description",
    "packageTabsEnabled",
    "packageTabsJson",
    "packageGroup",
    "supplierPrice",
    "pricingMode",
    "marginType",
    "marginValue",
    "supplierSyncedAt",
  ]) {
    assert.match(schema, new RegExp(`\\b${field}:`));
  }
});
