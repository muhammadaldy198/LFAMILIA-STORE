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

test("final storefront migration creates editable content, promotions, and admin roles", () => {
  const db = migratedDatabase();
  const tables = new Set(db.prepare("SELECT name FROM sqlite_master WHERE type = 'table'").all().map((row) => row.name));
  for (const table of ["store_settings", "product_categories", "product_notices", "discount_vouchers", "flash_sales", "admin_users", "faq_entries", "media_assets"]) {
    assert.ok(tables.has(table), `${table} should exist`);
  }
  const owners = db.prepare("SELECT email, role, is_active FROM admin_users WHERE role = 'owner'").all();
  assert.equal(owners.length, 0, "fresh databases must create the owner through the protected setup flow");
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM product_categories").get().count, 4);
});

test("product notices cascade and flash sales can be scheduled repeatedly", () => {
  const db = migratedDatabase();
  const baselineNotices = Number(db.prepare("SELECT COUNT(*) AS count FROM product_notices").get().count);
  const productId = Number(db.prepare(`INSERT INTO products (
    slug, name, publisher, category, initials, accent, input_label, input_placeholder
  ) VALUES ('test-game', 'Test Game', 'Studio', 'game', 'TG', 'from-black to-white', 'User ID', 'Masukkan ID') RETURNING id`).get().id);
  db.prepare("INSERT INTO product_notices (product_id, title, body) VALUES (?, 'Jam layanan', 'Buka pukul 09.00')").run(productId);
  db.prepare("INSERT INTO flash_sales (product_slug, package_sku, sale_price, starts_at, ends_at) VALUES ('test-game', 'sku-1', 9000, '2026-08-01T00:00:00.000Z', '2026-08-02T00:00:00.000Z')").run();
  db.prepare("INSERT INTO flash_sales (product_slug, package_sku, sale_price, starts_at, ends_at) VALUES ('test-game', 'sku-1', 8000, '2026-09-01T00:00:00.000Z', '2026-09-02T00:00:00.000Z')").run();
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM flash_sales").get().count, 2);
  db.prepare("DELETE FROM products WHERE id = ?").run(productId);
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM product_notices").get().count, baselineNotices);
});

test("one-time catalog repopulation restores the bundled catalog as normal rows", () => {
  const db = migratedDatabase();
  assert.equal(Number(db.prepare("SELECT COUNT(*) AS count FROM products").get().count), 24);
  assert.equal(Number(db.prepare("SELECT COUNT(*) AS count FROM product_packages").get().count), 83);
  assert.equal(Number(db.prepare("SELECT COUNT(*) AS count FROM product_notices").get().count), 3);
});

test("customer experience migration creates accounts, wallet, reviews, banners, popups, and news", () => {
  const db = migratedDatabase();
  const tables = new Set(db.prepare("SELECT name FROM sqlite_master WHERE type = 'table'").all().map((row) => row.name));
  for (const table of ["customer_users", "customer_sessions", "customer_game_accounts", "wallet_settings", "wallet_topups", "wallet_transactions", "product_reviews", "home_banners", "site_popups", "news_articles"]) {
    assert.ok(tables.has(table), `${table} should exist`);
  }
  const productColumns = new Set(db.prepare("PRAGMA table_info(products)").all().map((row) => row.name));
  const orderColumns = new Set(db.prepare("PRAGMA table_info(orders)").all().map((row) => row.name));
  const settingsColumns = new Set(db.prepare("PRAGMA table_info(store_settings)").all().map((row) => row.name));
  assert.ok(productColumns.has("banner_url"));
  assert.ok(orderColumns.has("customer_id"));
  assert.ok(settingsColumns.has("discord_url"));

  db.prepare(`INSERT INTO customer_users (id, email, name, phone, password_hash, password_salt)
    VALUES ('customer-a', 'buyer@example.com', 'Buyer Test', '628123456789', 'hash', 'salt')`).run();
  assert.equal(db.prepare("SELECT leaderboard_opt_in FROM customer_users WHERE id = 'customer-a'").get().leaderboard_opt_in, 0);
  db.prepare(`INSERT INTO wallet_transactions
    (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
    VALUES ('wallet-a', 'customer-a', 'credit', 10000, 0, 10000, 'topup:one', 'Top up')`).run();
  assert.throws(() => db.prepare(`INSERT INTO wallet_transactions
    (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
    VALUES ('wallet-b', 'customer-a', 'credit', 10000, 10000, 20000, 'topup:one', 'Duplikat')`).run(), /UNIQUE/i);
});


test("voucher stock defaults to website delivery when no optional email channel is configured", () => {
  const source = fs.readFileSync(path.join(projectRoot, "lib/server/vouchers.ts"), "utf8");
  assert.match(source, /VOUCHER_DELIVERY_CHANNEL\?\.trim\(\) \|\| "website"/);
  assert.doesNotMatch(source, /requireRuntimeValue\(runtime\(\)\.VOUCHER_DELIVERY_CHANNEL/);
});
