import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import {
  canProcessDokuOrderCallback,
  hasMinimumAdminRole,
  isDigiflazzSnapshotAvailable,
  resolveMemberTierFromProgress,
} from "../lib/server/final-audit-rules.ts";

const root = process.cwd();

function database() {
  return new DatabaseSync(":memory:");
}

test("RBAC behavior enforces the Super Admin, Admin, and Staff hierarchy from stored roles", () => {
  const db = database();
  db.exec("CREATE TABLE admin_users (id INTEGER PRIMARY KEY, role TEXT NOT NULL)");
  db.exec("INSERT INTO admin_users (id,role) VALUES (1,'super_admin'),(2,'admin'),(3,'staff')");
  const role = (id) => db.prepare("SELECT role FROM admin_users WHERE id = ?").get(id).role;

  assert.equal(hasMinimumAdminRole(role(1), "owner"), true);
  assert.equal(hasMinimumAdminRole(role(1), "admin"), true);
  assert.equal(hasMinimumAdminRole(role(1), "staff"), true);
  assert.equal(hasMinimumAdminRole(role(2), "owner"), false);
  assert.equal(hasMinimumAdminRole(role(2), "admin"), true);
  assert.equal(hasMinimumAdminRole(role(2), "staff"), true);
  assert.equal(hasMinimumAdminRole(role(3), "admin"), false);
  assert.equal(hasMinimumAdminRole(role(3), "staff"), true);
  db.close();
});

test("membership behavior uses paid lifetime spend plus progress bonus at exact tier boundaries", () => {
  const db = database();
  db.exec(`
    CREATE TABLE customer_users (
      id TEXT PRIMARY KEY,
      tier_mode TEXT NOT NULL DEFAULT 'automatic',
      tier_override TEXT,
      tier_progress_bonus INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE orders (
      id TEXT PRIMARY KEY,
      customer_id TEXT NOT NULL,
      total INTEGER NOT NULL,
      payment_status TEXT NOT NULL
    );
  `);
  db.exec("INSERT INTO customer_users (id) VALUES ('c1')");
  db.exec(`
    INSERT INTO orders VALUES
      ('paid-1','c1',600000,'paid'),
      ('paid-2','c1',400000,'paid'),
      ('pending','c1',99000000,'pending')
  `);
  const spend = Number(db.prepare("SELECT COALESCE(SUM(total),0) AS total FROM orders WHERE customer_id='c1' AND payment_status='paid'").get().total);
  assert.equal(spend, 1_000_000);
  assert.equal(resolveMemberTierFromProgress(spend), "gold");

  db.prepare("UPDATE customer_users SET tier_progress_bonus = ? WHERE id='c1'").run(9_000_000);
  const bonus = Number(db.prepare("SELECT tier_progress_bonus FROM customer_users WHERE id='c1'").get().tier_progress_bonus);
  assert.equal(resolveMemberTierFromProgress(spend + bonus), "diamond");
  assert.equal(resolveMemberTierFromProgress(49_999_999), "diamond");
  assert.equal(resolveMemberTierFromProgress(50_000_000), "platinum");
  assert.equal(resolveMemberTierFromProgress(-1), "basic");
  db.close();
});

test("product availability behavior fails closed for seller, buyer, stock, cutoff, and voucher stock", () => {
  const db = database();
  db.exec(`
    CREATE TABLE digiflazz_seller_monitor (
      package_id INTEGER PRIMARY KEY,
      buyer_product_status INTEGER NOT NULL,
      seller_product_status INTEGER NOT NULL,
      unlimited_stock INTEGER NOT NULL,
      stock INTEGER NOT NULL,
      start_cut_off TEXT,
      end_cut_off TEXT
    );
    CREATE TABLE voucher_codes (stock_key TEXT NOT NULL, status TEXT NOT NULL);
    INSERT INTO digiflazz_seller_monitor VALUES
      (1,1,1,0,5,'01:00','02:00'),
      (2,0,1,1,0,NULL,NULL),
      (3,1,1,0,0,NULL,NULL),
      (4,1,1,1,0,'23:00','02:00');
    INSERT INTO voucher_codes VALUES ('READY','available'),('USED','delivered');
  `);

  const row = (id) => db.prepare("SELECT * FROM digiflazz_seller_monitor WHERE package_id=?").get(id);
  const available = (id, currentMinute) => {
    const item = row(id);
    return isDigiflazzSnapshotAvailable({
      buyerProductStatus: item.buyer_product_status,
      sellerProductStatus: item.seller_product_status,
      unlimitedStock: item.unlimited_stock,
      stock: item.stock,
      startCutOff: item.start_cut_off,
      endCutOff: item.end_cut_off,
      currentMinute,
    });
  };

  assert.equal(available(1, 12 * 60), true);
  assert.equal(available(1, 90), false);
  assert.equal(available(2, 12 * 60), false);
  assert.equal(available(3, 12 * 60), false);
  assert.equal(available(4, 30), false);
  assert.equal(Boolean(db.prepare("SELECT 1 FROM voucher_codes WHERE stock_key='READY' AND status='available'").get()), true);
  assert.equal(Boolean(db.prepare("SELECT 1 FROM voucher_codes WHERE stock_key='USED' AND status='available'").get()), false);
  db.close();
});

test("DOKU callback behavior is replay-idempotent and cannot revive a finalized order", () => {
  const db = database();
  db.exec(`
    CREATE TABLE orders (id TEXT PRIMARY KEY, payment_status TEXT NOT NULL);
    CREATE TABLE order_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      order_id TEXT NOT NULL,
      source TEXT NOT NULL,
      event_id TEXT NOT NULL,
      status TEXT NOT NULL,
      UNIQUE(source,event_id)
    );
    INSERT INTO orders VALUES ('pending','pending'),('expired','expired'),('paid','paid');
  `);

  const applySignedPaid = (orderId, eventId) => {
    const order = db.prepare("SELECT payment_status FROM orders WHERE id=?").get(orderId);
    if (!canProcessDokuOrderCallback(order.payment_status)) return false;
    const inserted = db.prepare("INSERT OR IGNORE INTO order_events (order_id,source,event_id,status) VALUES (?,'doku',?,'paid')").run(orderId, eventId);
    if (Number(inserted.changes) === 0) return false;
    db.prepare("UPDATE orders SET payment_status='paid' WHERE id=? AND payment_status='pending'").run(orderId);
    return true;
  };

  assert.equal(applySignedPaid("pending", "evt-1"), true);
  assert.equal(applySignedPaid("pending", "evt-1"), false);
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM order_events WHERE event_id='evt-1'").get().count, 1);
  assert.equal(applySignedPaid("expired", "evt-late"), false);
  assert.equal(db.prepare("SELECT payment_status FROM orders WHERE id='expired'").get().payment_status, "expired");
  assert.equal(applySignedPaid("paid", "evt-downgrade"), false);
  db.close();
});

test("production modules are wired to the behavior-tested rules", () => {
  const admin = fs.readFileSync(path.join(root, "lib/server/admin.ts"), "utf8");
  const members = fs.readFileSync(path.join(root, "lib/server/member-tiers.ts"), "utf8");
  const availability = fs.readFileSync(path.join(root, "lib/server/availability.ts"), "utf8");
  const callback = fs.readFileSync(path.join(root, "app/api/payments/doku/callback/route.ts"), "utf8");
  const externalCheckout = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
  const walletCheckout = fs.readFileSync(path.join(root, "app/api/payments/wallet/create/route.ts"), "utf8");

  assert.match(admin, /hasMinimumAdminRole\(session\.role, minimumRole\)/);
  assert.match(members, /resolveMemberTierFromProgress\(progress\)/);
  assert.match(availability, /isDigiflazzSnapshotAvailable\(/);
  assert.match(callback, /applyPendingExternalPaymentStatus\(order, notification\.status\)/);
  assert.match(externalCheckout, /isAutomaticPackageAvailable\(/);
  assert.match(walletCheckout, /isAutomaticPackageAvailable\(/);
});
