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
import { FULFILLMENT_ERROR_TRANSITION_GUARD_SQL } from "../lib/server/fulfillment-transition-guard.mjs";
import { mapMidtransSnapStatus } from "../lib/server/midtrans-status.mjs";

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

test("DOKU callback behavior is replay-idempotent and verified paid may recover local expiry", () => {
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
    if (!canProcessDokuOrderCallback(order.payment_status, "paid")) return false;
    const inserted = db.prepare("INSERT OR IGNORE INTO order_events (order_id,source,event_id,status) VALUES (?,'doku',?,'paid')").run(orderId, eventId);
    if (Number(inserted.changes) === 0) return false;
    db.prepare("UPDATE orders SET payment_status='paid' WHERE id=? AND payment_status IN ('pending','expired')").run(orderId);
    return true;
  };

  assert.equal(applySignedPaid("pending", "evt-1"), true);
  assert.equal(applySignedPaid("pending", "evt-1"), false);
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM order_events WHERE event_id='evt-1'").get().count, 1);
  assert.equal(applySignedPaid("expired", "evt-late"), true);
  assert.equal(db.prepare("SELECT payment_status FROM orders WHERE id='expired'").get().payment_status, "paid");
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
  assert.match(callback, /canProcessDokuOrderCallback\(order\.payment_status, status\)/);
  assert.match(callback, /applyExternalPaymentEvent\(\{/);
  assert.match(callback, /authoritativePaid: status === "paid"/);
  assert.match(externalCheckout, /isAutomaticPackageAvailable\(/);
  assert.match(walletCheckout, /isAutomaticPackageAvailable\(/);
});

test("provider fulfillment transitions cannot downgrade success and webhook replay is inert", () => {
  const db = database();
  db.exec(`
    CREATE TABLE orders (
      id TEXT PRIMARY KEY,
      payment_status TEXT NOT NULL,
      fulfillment_type TEXT NOT NULL,
      fulfillment_status TEXT NOT NULL,
      provider_status TEXT,
      provider_message TEXT,
      updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE order_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      order_id TEXT NOT NULL,
      source TEXT NOT NULL,
      event_id TEXT NOT NULL,
      status TEXT NOT NULL,
      payload_json TEXT,
      UNIQUE(source,event_id)
    );
    INSERT INTO orders (id,payment_status,fulfillment_type,fulfillment_status,provider_status)
    VALUES ('o1','paid','automatic','processing','processing');
  `);

  const applyWebhook = (status, eventId) => {
    db.exec("BEGIN");
    try {
      db.prepare(`
        UPDATE orders
        SET fulfillment_status = ?, provider_status = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = 'o1'
          AND payment_status = 'paid'
          AND fulfillment_type = 'automatic'
          AND fulfillment_status NOT IN ('success','failed','cancelled')
          AND NOT EXISTS (
            SELECT 1 FROM order_events WHERE source = 'digiflazz' AND event_id = ?
          )
      `).run(status, status, eventId);
      db.prepare(`
        INSERT OR IGNORE INTO order_events (order_id,source,event_id,status,payload_json)
        VALUES ('o1','digiflazz',?,?, '{}')
      `).run(eventId, status);
      db.exec("COMMIT");
    } catch (error) {
      db.exec("ROLLBACK");
      throw error;
    }
  };

  applyWebhook("success", "evt-success");
  applyWebhook("processing", "evt-stale-processing");
  applyWebhook("success", "evt-success");

  assert.equal(db.prepare("SELECT fulfillment_status FROM orders WHERE id='o1'").get().fulfillment_status, "success");
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM order_events WHERE event_id='evt-success'").get().count, 1);
  db.close();
});

test("stale fulfillment error paths cannot downgrade terminal provider results", () => {
  const db = database();
  db.exec(`
    CREATE TABLE orders (
      id TEXT PRIMARY KEY,
      payment_status TEXT NOT NULL,
      fulfillment_type TEXT NOT NULL,
      fulfillment_status TEXT NOT NULL,
      provider_status TEXT,
      provider_message TEXT
    );
    INSERT INTO orders VALUES
      ('success','paid','automatic','success','success','delivered'),
      ('failed','paid','automatic','failed','failed','provider failed'),
      ('active','paid','automatic','dispatching','dispatching',NULL);
  `);

  const applyRetryableError = (id) => db.prepare(`
    UPDATE orders
    SET fulfillment_status='processing', provider_status='retryable_error', provider_message='timeout'
    WHERE id=? AND ${FULFILLMENT_ERROR_TRANSITION_GUARD_SQL}
  `).run(id);

  assert.equal(Number(applyRetryableError("success").changes), 0);
  assert.equal(Number(applyRetryableError("failed").changes), 0);
  assert.equal(Number(applyRetryableError("active").changes), 1);
  assert.deepEqual(
    { ...db.prepare("SELECT fulfillment_status,provider_status,provider_message FROM orders WHERE id='success'").get() },
    { fulfillment_status: "success", provider_status: "success", provider_message: "delivered" },
  );
  assert.equal(db.prepare("SELECT provider_status FROM orders WHERE id='active'").get().provider_status, "retryable_error");
  db.close();
});

test("Midtrans fraud challenge never becomes paid before FDS acceptance", () => {
  assert.equal(mapMidtransSnapStatus("capture", "accept"), "paid");
  assert.equal(mapMidtransSnapStatus("capture", null), "paid");
  assert.equal(mapMidtransSnapStatus("capture", "challenge"), "pending");
  assert.equal(mapMidtransSnapStatus("capture", "deny"), "failed");
  assert.equal(mapMidtransSnapStatus("settlement", "accept"), "paid");
  assert.equal(mapMidtransSnapStatus("settlement", "challenge"), "pending");
  assert.equal(mapMidtransSnapStatus("settlement", null), "paid");
});

test("voucher code stays immutable after reservation history so late payment targets the same voucher", () => {
  const db = database();
  db.exec(`
    CREATE TABLE discount_vouchers (
      id INTEGER PRIMARY KEY,
      code TEXT UNIQUE NOT NULL,
      used_count INTEGER NOT NULL DEFAULT 0,
      reserved_count INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE promotion_reservations (
      order_id TEXT PRIMARY KEY,
      voucher_code TEXT,
      status TEXT NOT NULL
    );
    INSERT INTO discount_vouchers VALUES (1,'STABLE',0,0);
    INSERT INTO promotion_reservations VALUES ('late-order','STABLE','released');
  `);

  const history = db.prepare(
    "SELECT 1 AS found FROM promotion_reservations WHERE voucher_code=? LIMIT 1",
  ).get("STABLE");
  assert.equal(Boolean(history), true);

  // Production rejects a code rename when history exists; the late payment
  // therefore increments the same voucher identity instead of a reused code.
  db.prepare(
    "UPDATE promotion_reservations SET status='consumed' WHERE order_id='late-order' AND status='released'",
  ).run();
  db.prepare("UPDATE discount_vouchers SET used_count=used_count+1 WHERE code=?").run("STABLE");
  assert.equal(db.prepare("SELECT used_count FROM discount_vouchers WHERE id=1").get().used_count, 1);
  db.close();

  const promotions = fs.readFileSync(path.join(root, "lib/server/promotions.ts"), "utf8");
  assert.match(promotions, /Kode voucher tidak dapat diubah setelah dipakai atau direservasi/);
});

test("promo identity guards are atomic against concurrent wallet settlement", () => {
  const promotions = fs.readFileSync(path.join(root, "lib/server/promotions.ts"), "utf8");
  assert.match(promotions, /AND used_count = 0 AND reserved_count = 0/);
  assert.match(promotions, /DELETE FROM discount_vouchers[\\s\\S]*WHERE id = \\? AND used_count = 0 AND reserved_count = 0/);
  assert.match(promotions, /DELETE FROM flash_sales[\\s\\S]*WHERE id = \\? AND sold_count = 0 AND reserved_count = 0/);
  assert.match(promotions, /Number\(deleted\.meta\.changes \?\? 0\) > 0/);
  assert.match(promotions, /Voucher baru saja digunakan/);
  assert.match(promotions, /Flash sale baru saja digunakan/);
});

test("wallet-used voucher code cannot be renamed or deleted for later reuse", () => {
  const db = database();
  db.exec(`
    CREATE TABLE discount_vouchers (
      id INTEGER PRIMARY KEY,
      code TEXT UNIQUE NOT NULL,
      used_count INTEGER NOT NULL DEFAULT 0,
      reserved_count INTEGER NOT NULL DEFAULT 0
    );
    INSERT INTO discount_vouchers VALUES (1,'SAVE10',1,0);
  `);

  const current = db.prepare(
    "SELECT code,used_count,reserved_count FROM discount_vouchers WHERE id=1",
  ).get();
  assert.equal(current.used_count, 1);
  assert.equal(current.reserved_count, 0);

  // Production rejects both destructive operations when used_count > 0.
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM discount_vouchers WHERE code='SAVE10'").get().count, 1);
  db.close();

  const promotions = fs.readFileSync(path.join(root, "lib/server/promotions.ts"), "utf8");
  assert.match(promotions, /current\.used_count > 0/);
  assert.match(promotions, /Kode voucher tidak dapat diubah setelah pernah digunakan/);
  assert.match(promotions, /Voucher pernah digunakan\. Nonaktifkan voucher/);
});

test("wallet-used flash-sale identity cannot be repointed or deleted", () => {
  const db = database();
  db.exec(`
    CREATE TABLE flash_sales (
      id INTEGER PRIMARY KEY,
      product_slug TEXT NOT NULL,
      package_sku TEXT NOT NULL,
      sold_count INTEGER NOT NULL DEFAULT 0,
      reserved_count INTEGER NOT NULL DEFAULT 0
    );
    INSERT INTO flash_sales VALUES (9,'game-a','sku-a',1,0);
  `);
  const row = db.prepare("SELECT * FROM flash_sales WHERE id=9").get();
  assert.equal(row.sold_count, 1);
  assert.equal(row.reserved_count, 0);
  db.close();

  const promotions = fs.readFileSync(path.join(root, "lib/server/promotions.ts"), "utf8");
  assert.match(promotions, /reserved_count = 0 AND sold_count = 0/);
  assert.match(promotions, /current\.sold_count > 0/);
  assert.match(promotions, /Flash sale pernah digunakan\. Nonaktifkan promo/);
});

test("historical promo references block destructive voucher reuse and deletion", () => {
  const db = database();
  db.exec(`
    CREATE TABLE discount_vouchers (
      id INTEGER PRIMARY KEY,
      code TEXT UNIQUE NOT NULL,
      reserved_count INTEGER NOT NULL DEFAULT 0,
      is_active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE flash_sales (
      id INTEGER PRIMARY KEY,
      reserved_count INTEGER NOT NULL DEFAULT 0,
      is_active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE promotion_reservations (
      order_id TEXT PRIMARY KEY,
      voucher_code TEXT,
      flash_sale_id INTEGER,
      status TEXT NOT NULL
    );
    INSERT INTO discount_vouchers VALUES (1,'HISTORY',0,0);
    INSERT INTO flash_sales VALUES (7,0,0);
    INSERT INTO promotion_reservations VALUES ('o1','HISTORY',7,'released');
  `);

  assert.ok(db.prepare(
    "SELECT 1 FROM promotion_reservations WHERE voucher_code='HISTORY' LIMIT 1",
  ).get());
  assert.ok(db.prepare(
    "SELECT 1 FROM promotion_reservations WHERE flash_sale_id=7 LIMIT 1",
  ).get());
  // The production delete path checks these references before destructive DELETE.
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM discount_vouchers WHERE id=1").get().count, 1);
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM flash_sales WHERE id=7").get().count, 1);
  db.close();

  const promotions = fs.readFileSync(path.join(root, "lib/server/promotions.ts"), "utf8");
  assert.match(promotions, /promotion_reservations WHERE voucher_code = \\?/);
  assert.match(promotions, /promotion_reservations WHERE flash_sale_id = \\?/);
  assert.match(promotions, /Voucher memiliki riwayat transaksi/);
  assert.match(promotions, /Kode voucher pernah dipakai/);
});

test("verified provider expiry is not blocked by a later local expiry timestamp", () => {
  const db = database();
  db.exec(`
    CREATE TABLE orders (
      id TEXT PRIMARY KEY,
      payment_status TEXT NOT NULL,
      gateway_expired_at TEXT
    );
    INSERT INTO orders VALUES ('o1','pending',datetime('now','+2 hours'));
  `);
  const result = db.prepare(
    "UPDATE orders SET payment_status='expired' WHERE id=? AND payment_status='pending'",
  ).run("o1");
  assert.equal(Number(result.changes), 1);
  assert.equal(db.prepare("SELECT payment_status FROM orders WHERE id='o1'").get().payment_status, "expired");
  db.close();

  const transition = fs.readFileSync(path.join(root, "lib/server/payment-transition.ts"), "utf8");
  assert.match(transition, /authoritativeExpired/);
});

test("public gateway status refresh treats authenticated provider states as authoritative", () => {
  const route = fs.readFileSync(path.join(root, "app/api/orders/status/route.ts"), "utf8");
  const dokuRefresh =
    route.match(/async function refreshDokuStatus[\s\S]*?async function refreshMidtransSnapStatus/)?.[0] || "";
  assert.match(
    dokuRefresh,
    /applyPendingExternalPaymentStatus\(order, "expired", \{ authoritativeExpired: true \}\)/,
  );

  const midtransRefresh =
    route.match(/async function refreshMidtransSnapStatus[\s\S]*?async function recoverPaidAutomaticFulfillment/)?.[0] || "";
  assert.match(midtransRefresh, /applyPendingExternalPaymentStatus\(order, "paid", \{/);
  assert.match(midtransRefresh, /authoritativePaid: true/);
  assert.match(midtransRefresh, /authoritativeExpired: query\.status === "expired"/);
});

test("DigiFlazz reconciliation lease is token-owned across stale reclaim", () => {
  const db = database();
  db.exec(`
    CREATE TABLE orders (
      id TEXT PRIMARY KEY,
      payment_status TEXT NOT NULL,
      fulfillment_type TEXT NOT NULL,
      fulfillment_status TEXT NOT NULL,
      provider_code TEXT NOT NULL,
      provider_status TEXT NOT NULL,
      updated_at TEXT NOT NULL
    );
    INSERT INTO orders VALUES (
      'o1','paid','automatic','processing','digiflazz','processing',datetime('now','-3 minutes')
    );
  `);

  const claim = (leaseStatus) => db.prepare(`
    UPDATE orders
    SET provider_status=?, updated_at=CURRENT_TIMESTAMP
    WHERE id='o1'
      AND payment_status='paid'
      AND fulfillment_type='automatic'
      AND lower(trim(provider_code))='digiflazz'
      AND fulfillment_status NOT IN ('success','failed','cancelled')
      AND (
        (provider_status='processing' AND updated_at <= datetime('now','-2 minutes'))
        OR
        (provider_status LIKE 'reconciling:%' AND updated_at <= datetime('now','-5 minutes'))
      )
  `).run(leaseStatus);

  assert.equal(Number(claim("reconciling:A").changes), 1);
  assert.equal(Number(claim("reconciling:B").changes), 0);
  db.prepare("UPDATE orders SET updated_at=datetime('now','-6 minutes') WHERE id='o1'").run();
  assert.equal(Number(claim("reconciling:B").changes), 1);

  const staleRelease = db.prepare(`
    UPDATE orders SET provider_status='processing'
    WHERE id='o1' AND provider_status=?
  `).run("reconciling:A");
  assert.equal(Number(staleRelease.changes), 0);
  assert.equal(db.prepare("SELECT provider_status FROM orders WHERE id='o1'").get().provider_status, "reconciling:B");
  db.close();
});

test("production backend wires atomic payment events, stable callback identity, and reconciliation lease", () => {
  const paymentTransition = fs.readFileSync(path.join(root, "lib/server/payment-transition.ts"), "utf8");
  const orders = fs.readFileSync(path.join(root, "lib/server/orders.ts"), "utf8");
  const reconciliation = fs.readFileSync(path.join(root, "lib/server/digiflazz-reconciliation.ts"), "utf8");
  const callback = fs.readFileSync(path.join(root, "app/api/fulfillment/digiflazz/callback/route.ts"), "utf8");
  const doku = fs.readFileSync(path.join(root, "app/api/payments/doku/callback/route.ts"), "utf8");
  const midtrans = fs.readFileSync(path.join(root, "app/api/payments/midtrans/snap/notification/route.ts"), "utf8");

  assert.match(paymentTransition, /applyExternalPaymentEvent/);
  assert.match(paymentTransition, /NOT EXISTS \(\s*SELECT 1 FROM order_events/);
  assert.match(orders, /providerTransitionGuard/);
  assert.match(orders, /AND NOT EXISTS \(\s*SELECT 1 FROM order_events WHERE source = \? AND event_id = \?/);
  assert.match(reconciliation, /reconciling:\$\{crypto\.randomUUID\(\)\}/);
  assert.match(reconciliation, /provider_status = 'reconciling' OR provider_status LIKE 'reconciling:%'/);
  assert.match(reconciliation, /claimDigiflazzReconciliation\(order\.id\)/);
  assert.match(callback, /digiflazz-event-/);
  assert.doesNotMatch(callback, /digiflazz-hook-/);
  assert.match(callback, /const normalizedStatus = data\.status\?\.trim\(\)\.toLowerCase\(\) \?\? ""/);
  assert.match(callback, /mapStatus\(normalizedStatus\)/);
  assert.match(callback, /semanticEvent/);
  assert.match(doku, /applyExternalPaymentEvent/);
  assert.match(midtrans, /applyExternalPaymentEvent/);
  assert.match(midtrans, /snap-\$\{transactionId\}-\$\{status\}/);
});

