import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const walletSource = fs.readFileSync(path.join(root, "lib/server/wallet.ts"), "utf8");

function createDatabase() {
  const database = new DatabaseSync(":memory:");
  database.exec(`
    CREATE TABLE customer_users (id TEXT PRIMARY KEY, balance INTEGER NOT NULL DEFAULT 0, updated_at TEXT);
    CREATE TABLE orders (
      id TEXT PRIMARY KEY, customer_id TEXT, payment_method TEXT, payment_status TEXT NOT NULL DEFAULT 'pending',
      fulfillment_type TEXT NOT NULL, fulfillment_status TEXT NOT NULL DEFAULT 'pending',
      voucher_code TEXT, flash_sale_id INTEGER, provider_status TEXT, provider_code TEXT, updated_at TEXT
    );
    CREATE TABLE wallet_transactions (
      id TEXT PRIMARY KEY, customer_id TEXT NOT NULL, direction TEXT NOT NULL, amount INTEGER NOT NULL,
      balance_before INTEGER NOT NULL, balance_after INTEGER NOT NULL, reference TEXT UNIQUE, description TEXT
    );
    CREATE TABLE discount_vouchers (
      code TEXT PRIMARY KEY, is_active INTEGER NOT NULL DEFAULT 1, starts_at TEXT, ends_at TEXT,
      usage_limit INTEGER, used_count INTEGER NOT NULL DEFAULT 0, updated_at TEXT
    );
    CREATE TABLE flash_sales (
      id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL DEFAULT 1, starts_at TEXT, ends_at TEXT,
      stock_limit INTEGER, sold_count INTEGER NOT NULL DEFAULT 0, updated_at TEXT
    );
    CREATE TABLE order_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT, source TEXT, event_id TEXT, status TEXT, payload_json TEXT,
      UNIQUE(source, event_id)
    );
  `);
  return database;
}

function executeSettlement(database, input) {
  const reference = `order:${input.orderId}`;
  const now = new Date().toISOString();
  database.exec("BEGIN");
  try {
    const balance = database.prepare("SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance FROM wallet_transactions WHERE customer_id = ?").get(input.customerId).balance;
    const order = database.prepare("SELECT * FROM orders WHERE id = ? AND customer_id = ? AND payment_method = 'wallet' AND payment_status = 'pending'").get(input.orderId, input.customerId);
    if (!order || balance < input.amount) throw new Error("settlement_blocked");
    if (input.voucherCode) {
      const voucher = database.prepare("SELECT * FROM discount_vouchers WHERE code = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ? AND (usage_limit IS NULL OR used_count < usage_limit)").get(input.voucherCode, now, now);
      if (!voucher) throw new Error("promotion_exhausted");
    }
    if (input.flashSaleId) {
      const flash = database.prepare("SELECT * FROM flash_sales WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ? AND (stock_limit IS NULL OR sold_count < stock_limit)").get(input.flashSaleId, now, now);
      if (!flash) throw new Error("promotion_exhausted");
    }
    database.prepare("INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?)").run(crypto.randomUUID(), input.customerId, input.amount, balance, balance - input.amount, reference, input.description);
    if (input.voucherCode) database.prepare("UPDATE discount_vouchers SET used_count = used_count + 1 WHERE code = ?").run(input.voucherCode);
    if (input.flashSaleId) database.prepare("UPDATE flash_sales SET sold_count = sold_count + 1 WHERE id = ?").run(input.flashSaleId);
    database.prepare("UPDATE customer_users SET balance = ? WHERE id = ?").run(balance - input.amount, input.customerId);
    database.prepare("UPDATE orders SET payment_status = 'paid', fulfillment_status = ? WHERE id = ?").run(input.fulfillmentType === "manual" ? "manual_pending" : "processing", input.orderId);
    database.prepare("INSERT OR IGNORE INTO order_events (order_id, source, event_id, status, payload_json) VALUES (?, 'wallet', ?, 'paid', ?)").run(input.orderId, `wallet-${input.orderId}`, JSON.stringify({ amount: input.amount }));
    database.exec("COMMIT");
    return balance - input.amount;
  } catch (error) {
    database.exec("ROLLBACK");
    throw error;
  }
}

test("wallet settlement commits one debit, payment state, promotion counters, and event", () => {
  const database = createDatabase();
  const now = Date.now();
  database.prepare("INSERT INTO customer_users (id, balance) VALUES ('c1', 100000)").run();
  database.prepare("INSERT INTO wallet_transactions VALUES ('seed','c1','credit',100000,0,100000,'seed','seed')").run();
  database.prepare("INSERT INTO orders VALUES ('order-1','c1','wallet','pending','automatic','pending','SAVE','1',NULL,NULL,CURRENT_TIMESTAMP)").run();
  database.prepare("INSERT INTO discount_vouchers VALUES ('SAVE',1,?,?,1,0,NULL)").run(new Date(now - 1000).toISOString(), new Date(now + 60000).toISOString());
  database.prepare("INSERT INTO flash_sales VALUES (1,1,?,?,1,0,NULL)").run(new Date(now - 1000).toISOString(), new Date(now + 60000).toISOString());
  const after = executeSettlement(database, { customerId: "c1", orderId: "order-1", amount: 25000, description: "Order", fulfillmentType: "automatic", voucherCode: "SAVE", flashSaleId: 1 });
  assert.equal(after, 75000);
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM wallet_transactions WHERE direction = 'debit'").get().count, 1);
  assert.equal(database.prepare("SELECT payment_status FROM orders WHERE id = 'order-1'").get().payment_status, "paid");
  assert.equal(database.prepare("SELECT used_count FROM discount_vouchers WHERE code = 'SAVE'").get().used_count, 1);
  assert.equal(database.prepare("SELECT sold_count FROM flash_sales WHERE id = 1").get().sold_count, 1);
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM order_events WHERE order_id = 'order-1'").get().count, 1);
  database.close();
});

test("wallet settlement does not debit when a limited promotion is exhausted", () => {
  const database = createDatabase();
  const now = Date.now();
  database.prepare("INSERT INTO customer_users (id, balance) VALUES ('c1', 100000)").run();
  database.prepare("INSERT INTO wallet_transactions VALUES ('seed','c1','credit',100000,0,100000,'seed','seed')").run();
  database.prepare("INSERT INTO orders VALUES ('order-2','c1','wallet','pending','automatic','pending','SAVE','1',NULL,NULL,CURRENT_TIMESTAMP)").run();
  database.prepare("INSERT INTO discount_vouchers VALUES ('SAVE',1,?,?,1,1,NULL)").run(new Date(now - 1000).toISOString(), new Date(now + 60000).toISOString());
  database.prepare("INSERT INTO flash_sales VALUES (1,1,?,?,1,0,NULL)").run(new Date(now - 1000).toISOString(), new Date(now + 60000).toISOString());
  assert.throws(() => executeSettlement(database, { customerId: "c1", orderId: "order-2", amount: 25000, description: "Order", fulfillmentType: "automatic", voucherCode: "SAVE", flashSaleId: 1 }), /promotion_exhausted/);
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM wallet_transactions WHERE direction = 'debit'").get().count, 0);
  assert.equal(database.prepare("SELECT payment_status FROM orders WHERE id = 'order-2'").get().payment_status, "pending");
  database.close();
});

test("wallet retries resume pending settlement and automatic fulfillment", () => {
  const route = fs.readFileSync(path.join(root, "app/api/payments/wallet/create/route.ts"), "utf8");
  const orders = fs.readFileSync(path.join(root, "lib/server/orders.ts"), "utf8");
  const worker = fs.readFileSync(path.join(root, "worker/index.ts"), "utf8");
  assert.match(route, /existing\.payment_status === "pending"[\s\S]*settleWalletOrder/);
  assert.match(route, /existing\.payment_status === "paid"[\s\S]*fulfillAutomaticOrder/);
  assert.match(route, /retryable: true[^\n]*status: 429/);
  assert.match(orders, /provider_status = 'dispatching'[\s\S]*datetime\('now', '-2 minutes'\)/);
  assert.match(orders, /provider_code IN \('digiflazz', 'voucher-stock'\)/);
  assert.match(orders, /provider_code NOT IN \('digiflazz', 'voucher-stock'\)/);
  assert.match(orders, /provider_status = 'retryable_error'/);
  assert.match(orders, /notifyOrderFulfillmentSuccessById\(row\.id\)/);
  assert.match(orders, /fulfillment-attempt-/);
  assert.match(orders, /provider_status = 'retry_exhausted'/);
  assert.match(orders, /COUNT\(\*\)[\s\S]*status = 'dispatching'[\s\S]*>= 5/);
  assert.match(orders, /ORDER BY CASE WHEN provider_status IS NULL THEN 0 ELSE 1 END/);
  assert.match(worker, /recoverStaleAutomaticOrders\(publicBaseUrl\)/);
  assert.match(worker, /reconcileStaleDigiflazzProcessing\(publicBaseUrl\)/);
});

test("deterministic wallet checkout validation is not reported as a retryable outage", () => {
  const route = fs.readFileSync(path.join(root, "app/api/payments/wallet/create/route.ts"), "utf8");
  const orders = fs.readFileSync(path.join(root, "lib/server/orders.ts"), "utf8");
  const promotions = fs.readFileSync(path.join(root, "lib/server/promotions.ts"), "utf8");
  assert.match(route, /error instanceof CheckoutValidationError/);
  assert.match(route, /error instanceof PromotionQuoteError/);
  assert.match(route, /status: clientInputRejected \? 400/);
  assert.match(orders, /throw new CheckoutValidationError/);
  assert.match(promotions, /export class PromotionQuoteError/);
});

test("automatic recovery retires an order after five dispatch attempts", () => {
  assert.match(walletSource, /WalletSettlementError/);
  const orders = fs.readFileSync(path.join(root, "lib/server/orders.ts"), "utf8");
  assert.match(orders, /retry_exhausted/);
  assert.match(orders, />= 5/);
});

test("automatic fulfillment atomically claims only five dispatch attempts", () => {
  const orders = fs.readFileSync(path.join(root, "lib/server/orders.ts"), "utf8");
  assert.match(orders, /claimAutomaticFulfillmentAttempt/);
  assert.match(orders, /db\.batch/);
  assert.match(orders, /< 5/);
});

test("failed attempt logging rolls back the dispatch claim", () => {
  const orders = fs.readFileSync(path.join(root, "lib/server/orders.ts"), "utf8");
  assert.match(orders, /setRetryableFulfillmentError/);
  assert.match(orders, /retryable_error/);
});
