import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

function migratedDatabase() {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  const migrations = fs.readdirSync(path.join(projectRoot, "drizzle"))
    .filter((name) => /^\d{4}_.+\.sql$/.test(name))
    .sort();
  for (const migration of migrations) {
    const sql = fs.readFileSync(path.join(projectRoot, "drizzle", migration), "utf8")
      .replaceAll("--> statement-breakpoint", "");
    db.exec(sql);
  }
  return db;
}

function insertOrder(db, id, referenceId) {
  db.prepare(`INSERT INTO orders (
    id, reference_id, product_slug, product_name, package_sku, package_label,
    fulfillment_type, target_template, destination, buyer_name, buyer_email,
    buyer_phone, subtotal, total, payment_method, payment_channel
  ) VALUES (?, ?, 'redfinger', 'REDFINGER', 'rf-30', '30 Hari',
    'automatic', '{{destination}}', 'akun', 'Pembeli', 'buyer@example.com',
    '628123456789', 10000, 10000, 'qris', 'mpm')`).run(id, referenceId);
}

test("voucher migrations create encrypted stock tables", () => {
  const db = migratedDatabase();
  const tables = db.prepare("SELECT name FROM sqlite_master WHERE type = 'table'").all().map((row) => row.name);
  assert.ok(tables.includes("voucher_codes"));
  assert.ok(tables.includes("voucher_deliveries"));
});

test("one stock code can only be reserved by one paid order", () => {
  const db = migratedDatabase();
  insertOrder(db, "order-a", "LF-20260829-AAAAAAAAAAAA");
  insertOrder(db, "order-b", "LF-20260829-BBBBBBBBBBBB");
  db.prepare(`INSERT INTO voucher_codes
    (stock_key, code_ciphertext, code_iv, code_tag, code_hash)
    VALUES ('redfinger-30-hari', 'cipher', 'iv', 'tag', 'hash-a')`).run();

  const reserve = db.prepare(`UPDATE voucher_codes
    SET status = 'reserved', order_id = ?, reserved_at = CURRENT_TIMESTAMP
    WHERE id = (
      SELECT id FROM voucher_codes
      WHERE stock_key = ? AND status = 'available' ORDER BY id LIMIT 1
    ) AND status = 'available'
    RETURNING id, order_id`);

  const first = reserve.all("order-a", "redfinger-30-hari");
  const second = reserve.all("order-b", "redfinger-30-hari");
  assert.equal(first.length, 1);
  assert.equal(first[0].order_id, "order-a");
  assert.equal(second.length, 0);
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM voucher_codes WHERE status = 'available'").get().count, 0);
});

test("delivery attempts are unique per order and channel", () => {
  const db = migratedDatabase();
  insertOrder(db, "order-a", "LF-20260829-AAAAAAAAAAAA");
  const codeId = Number(db.prepare(`INSERT INTO voucher_codes
    (stock_key, code_ciphertext, code_iv, code_tag, code_hash, status, order_id)
    VALUES ('redfinger-30-hari', 'cipher', 'iv', 'tag', 'hash-a', 'reserved', 'order-a') RETURNING id`).get().id);

  db.prepare(`INSERT INTO voucher_deliveries
    (order_id, voucher_code_id, channel, status, attempts)
    VALUES ('order-a', ?, 'email', 'sent', 1)`).run(codeId);
  assert.throws(() => db.prepare(`INSERT INTO voucher_deliveries
    (order_id, voucher_code_id, channel, status, attempts)
    VALUES ('order-a', ?, 'email', 'sent', 1)`).run(codeId), /UNIQUE/i);
});
