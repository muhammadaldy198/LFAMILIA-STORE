import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();

test("wallet settlement does not consume promo capacity reserved by another checkout", () => {
  const db = new DatabaseSync(":memory:");
  db.exec(`
    CREATE TABLE discount_vouchers (
      code TEXT PRIMARY KEY,
      is_active INTEGER NOT NULL,
      starts_at TEXT NOT NULL,
      ends_at TEXT NOT NULL,
      usage_limit INTEGER,
      used_count INTEGER NOT NULL DEFAULT 0,
      reserved_count INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE flash_sales (
      id INTEGER PRIMARY KEY,
      is_active INTEGER NOT NULL,
      starts_at TEXT NOT NULL,
      ends_at TEXT NOT NULL,
      stock_limit INTEGER,
      sold_count INTEGER NOT NULL DEFAULT 0,
      reserved_count INTEGER NOT NULL DEFAULT 0
    );
  `);
  const start = new Date(Date.now() - 60_000).toISOString();
  const end = new Date(Date.now() + 60_000).toISOString();
  db.prepare("INSERT INTO discount_vouchers VALUES ('ONE',1,?,?,1,0,1)").run(start, end);
  db.prepare("INSERT INTO flash_sales VALUES (1,1,?,?,1,0,1)").run(start, end);

  const voucherAvailable = db.prepare(
    `SELECT 1 AS ok FROM discount_vouchers
     WHERE code='ONE' AND is_active=1 AND starts_at <= ? AND ends_at >= ?
       AND (usage_limit IS NULL OR used_count + reserved_count < usage_limit)`,
  ).get(start, start);
  const flashAvailable = db.prepare(
    `SELECT 1 AS ok FROM flash_sales
     WHERE id=1 AND is_active=1 AND starts_at <= ? AND ends_at >= ?
       AND (stock_limit IS NULL OR sold_count + reserved_count < stock_limit)`,
  ).get(start, start);

  assert.equal(voucherAvailable, undefined);
  assert.equal(flashAvailable, undefined);
  db.close();
});

test("production wallet settlement enforces used plus reserved quota in debit and counter updates", () => {
  const source = fs.readFileSync(path.join(root, "lib/server/wallet.ts"), "utf8");
  assert.ok((source.match(/used_count \+ reserved_count < usage_limit/g) ?? []).length >= 2);
  assert.ok((source.match(/sold_count \+ reserved_count < stock_limit/g) ?? []).length >= 2);
});
