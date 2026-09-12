import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const migration = fs.readFileSync(
  path.join(root, "drizzle/0029_final_source_audit_remediation.sql"),
  "utf8",
);

function createPreMigrationDatabase() {
  const db = new DatabaseSync(":memory:");
  db.exec(`
    CREATE TABLE orders (
      id TEXT PRIMARY KEY,
      package_sku TEXT NOT NULL,
      fulfillment_type TEXT NOT NULL,
      provider_code TEXT,
      payment_status TEXT NOT NULL DEFAULT 'pending'
    );
    CREATE TABLE product_packages (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      sku TEXT NOT NULL,
      supplier_price INTEGER
    );
    CREATE TABLE wallet_topups (
      id TEXT PRIMARY KEY,
      customer_id TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending'
    );
    CREATE TABLE discount_vouchers (
      code TEXT PRIMARY KEY,
      is_active INTEGER NOT NULL DEFAULT 1,
      starts_at TEXT NOT NULL,
      ends_at TEXT NOT NULL,
      usage_limit INTEGER,
      used_count INTEGER NOT NULL DEFAULT 0,
      updated_at TEXT
    );
    CREATE TABLE flash_sales (
      id INTEGER PRIMARY KEY,
      is_active INTEGER NOT NULL DEFAULT 1,
      starts_at TEXT NOT NULL,
      ends_at TEXT NOT NULL,
      stock_limit INTEGER,
      sold_count INTEGER NOT NULL DEFAULT 0,
      updated_at TEXT
    );
  `);
  return db;
}

function applyMigration(db) {
  db.exec(migration);
}

function activeWindow() {
  return {
    start: new Date(Date.now() - 60_000).toISOString(),
    end: new Date(Date.now() + 60 * 60_000).toISOString(),
  };
}

test("promotion reservations enforce quota atomically and consumed reservations cannot be released", () => {
  const db = createPreMigrationDatabase();
  applyMigration(db);
  const { start, end } = activeWindow();
  db.prepare("INSERT INTO discount_vouchers (code,is_active,starts_at,ends_at,usage_limit,used_count) VALUES ('ONE',1,?,?,1,0)").run(start, end);

  db.prepare("INSERT INTO promotion_reservations (order_id,voucher_code,status,expires_at) VALUES ('o1','ONE','reserved',?)").run(end);
  assert.equal(db.prepare("SELECT reserved_count FROM discount_vouchers WHERE code='ONE'").get().reserved_count, 1);
  assert.throws(
    () => db.prepare("INSERT INTO promotion_reservations (order_id,voucher_code,status,expires_at) VALUES ('o2','ONE','reserved',?)").run(end),
    /Voucher sudah tidak tersedia/,
  );

  db.prepare("UPDATE promotion_reservations SET status='consumed' WHERE order_id='o1' AND status='reserved'").run();
  const consumed = db.prepare("SELECT reserved_count, used_count FROM discount_vouchers WHERE code='ONE'").get();
  assert.equal(consumed.reserved_count, 0);
  assert.equal(consumed.used_count, 1);

  db.prepare("UPDATE promotion_reservations SET status='released' WHERE order_id='o1' AND status='reserved'").run();
  const afterReleaseAttempt = db.prepare("SELECT reserved_count, used_count FROM discount_vouchers WHERE code='ONE'").get();
  assert.equal(afterReleaseAttempt.reserved_count, 0);
  assert.equal(afterReleaseAttempt.used_count, 1);
  assert.equal(db.prepare("SELECT status FROM promotion_reservations WHERE order_id='o1'").get().status, "consumed");
  db.close();
});

test("expired promotion recovery releases reserved_count once and is idempotent", () => {
  const db = createPreMigrationDatabase();
  applyMigration(db);
  const { start, end } = activeWindow();
  db.prepare("INSERT INTO discount_vouchers (code,is_active,starts_at,ends_at,usage_limit,used_count) VALUES ('EXP',1,?,?,10,0)").run(start, end);
  db.prepare("INSERT INTO promotion_reservations (order_id,voucher_code,status,expires_at) VALUES ('expired-order','EXP','reserved',datetime('now','-1 minute'))").run();
  assert.equal(db.prepare("SELECT reserved_count FROM discount_vouchers WHERE code='EXP'").get().reserved_count, 1);

  const releaseSql = "UPDATE promotion_reservations SET status = 'released', updated_at = CURRENT_TIMESTAMP WHERE status = 'reserved' AND datetime(expires_at) <= datetime('now')";
  db.exec(releaseSql);
  db.exec(releaseSql);

  assert.equal(db.prepare("SELECT status FROM promotion_reservations WHERE order_id='expired-order'").get().status, "released");
  assert.equal(db.prepare("SELECT reserved_count FROM discount_vouchers WHERE code='EXP'").get().reserved_count, 0);
  db.close();
});

test("order delivery mode and supplier cost are backfilled as immutable snapshots", () => {
  const db = createPreMigrationDatabase();
  db.prepare("INSERT INTO product_packages (sku,supplier_price) VALUES ('DIGI',7000),('VCHR',8000),('MAN',9000)").run();
  db.prepare("INSERT INTO orders (id,package_sku,fulfillment_type,provider_code) VALUES ('d','DIGI','automatic','digiflazz'),('v','VCHR','automatic','voucher-stock'),('m','MAN','manual',NULL)").run();
  applyMigration(db);

  assert.deepEqual(
    db.prepare("SELECT delivery_mode,supplier_cost_snapshot FROM orders WHERE id='d'").get(),
    { delivery_mode: "direct", supplier_cost_snapshot: 7000 },
  );
  assert.deepEqual(
    db.prepare("SELECT delivery_mode,supplier_cost_snapshot FROM orders WHERE id='v'").get(),
    { delivery_mode: "voucher", supplier_cost_snapshot: 8000 },
  );
  assert.deepEqual(
    db.prepare("SELECT delivery_mode,supplier_cost_snapshot FROM orders WHERE id='m'").get(),
    { delivery_mode: "manual", supplier_cost_snapshot: 9000 },
  );

  db.prepare("UPDATE product_packages SET supplier_price=1 WHERE sku='DIGI'").run();
  assert.equal(db.prepare("SELECT supplier_cost_snapshot FROM orders WHERE id='d'").get().supplier_cost_snapshot, 7000);
  db.close();
});

test("wallet topup idempotency key is unique per customer but reusable by a different customer", () => {
  const db = createPreMigrationDatabase();
  applyMigration(db);
  db.prepare("INSERT INTO wallet_topups (id,customer_id,status,external_checkout_key,doku_environment) VALUES ('t1','c1','pending','key-1','sandbox')").run();
  assert.throws(
    () => db.prepare("INSERT INTO wallet_topups (id,customer_id,status,external_checkout_key,doku_environment) VALUES ('t2','c1','pending','key-1','sandbox')").run(),
    /UNIQUE constraint failed/,
  );
  db.prepare("INSERT INTO wallet_topups (id,customer_id,status,external_checkout_key,doku_environment) VALUES ('t3','c2','pending','key-1','production')").run();
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM wallet_topups WHERE external_checkout_key='key-1'").get().count, 2);
  db.close();
});
