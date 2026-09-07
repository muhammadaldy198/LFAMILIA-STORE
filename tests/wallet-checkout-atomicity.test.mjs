import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import test from "node:test";
import { register } from "tsx/esm/api";

class D1Statement {
  constructor(database, sql) {
    this.statement = database.prepare(sql);
    this.values = [];
  }

  bind(...values) {
    this.values = values;
    return this;
  }

  async run() {
    const result = this.statement.run(...this.values);
    return { meta: { changes: Number(result.changes) } };
  }

  async first() {
    return this.statement.get(...this.values) ?? null;
  }
}

class TestD1 {
  constructor(database) {
    this.database = database;
  }

  prepare(sql) {
    return new D1Statement(this.database, sql);
  }

  async batch(statements) {
    this.database.exec("BEGIN IMMEDIATE");
    try {
      const results = [];
      for (const statement of statements) results.push(await statement.run());
      this.database.exec("COMMIT");
      return results;
    } catch (error) {
      this.database.exec("ROLLBACK");
      throw error;
    }
  }
}

const unregister = register();
const { setRuntimeEnv } = await import("../lib/server/runtime-env.ts");
const { settleWalletOrder } = await import("../lib/server/wallet.ts");

function createDatabase() {
  const database = new DatabaseSync(":memory:");
  database.exec(`
    CREATE TABLE customer_users (id TEXT PRIMARY KEY, balance INTEGER NOT NULL DEFAULT 0, updated_at TEXT);
    CREATE TABLE orders (
      id TEXT PRIMARY KEY, customer_id TEXT, payment_method TEXT NOT NULL,
      payment_status TEXT NOT NULL DEFAULT 'pending', fulfillment_status TEXT NOT NULL DEFAULT 'waiting_payment',
      updated_at TEXT
    );
    CREATE TABLE wallet_transactions (
      id TEXT PRIMARY KEY, customer_id TEXT NOT NULL, direction TEXT NOT NULL, amount INTEGER NOT NULL,
      balance_before INTEGER NOT NULL, balance_after INTEGER NOT NULL, reference TEXT NOT NULL UNIQUE,
      description TEXT NOT NULL
    );
    CREATE TABLE discount_vouchers (
      code TEXT PRIMARY KEY, is_active INTEGER NOT NULL, starts_at TEXT NOT NULL, ends_at TEXT NOT NULL,
      usage_limit INTEGER, used_count INTEGER NOT NULL DEFAULT 0, updated_at TEXT
    );
    CREATE TABLE flash_sales (
      id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL, starts_at TEXT NOT NULL, ends_at TEXT NOT NULL,
      stock_limit INTEGER, sold_count INTEGER NOT NULL DEFAULT 0, updated_at TEXT
    );
    CREATE TABLE order_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT NOT NULL, source TEXT NOT NULL,
      event_id TEXT NOT NULL, status TEXT NOT NULL, payload_json TEXT NOT NULL,
      UNIQUE(source, event_id)
    );
  `);
  return database;
}

test("wallet settlement commits one debit, payment state, promotion counters, and event", async () => {
  const database = createDatabase();
  const now = Date.now();
  database.prepare("INSERT INTO customer_users (id, balance) VALUES ('customer-1', 10000)").run();
  database.prepare("INSERT INTO wallet_transactions VALUES ('credit-1', 'customer-1', 'credit', 10000, 0, 10000, 'seed', 'Seed')").run();
  database.prepare("INSERT INTO orders (id, customer_id, payment_method) VALUES ('order-1', 'customer-1', 'wallet')").run();
  database.prepare("INSERT INTO discount_vouchers VALUES ('PROMO', 1, ?, ?, 1, 0, NULL)")
    .run(new Date(now - 60_000).toISOString(), new Date(now + 60_000).toISOString());
  database.prepare("INSERT INTO flash_sales VALUES (7, 1, ?, ?, 1, 0, NULL)")
    .run(new Date(now - 60_000).toISOString(), new Date(now + 60_000).toISOString());
  setRuntimeEnv({ DB: new TestD1(database) });

  const firstBalance = await settleWalletOrder({
    customerId: "customer-1", orderId: "order-1", amount: 6000,
    description: "Test order", fulfillmentType: "automatic", voucherCode: "PROMO", flashSaleId: 7,
  });
  assert.equal(firstBalance, 4000);
  assert.deepEqual(
    { ...database.prepare("SELECT payment_status, fulfillment_status FROM orders WHERE id = 'order-1'").get() },
    { payment_status: "paid", fulfillment_status: "processing" },
  );
  assert.equal(database.prepare("SELECT used_count FROM discount_vouchers WHERE code = 'PROMO'").get().used_count, 1);
  assert.equal(database.prepare("SELECT sold_count FROM flash_sales WHERE id = 7").get().sold_count, 1);
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM order_events").get().count, 1);

  const retryBalance = await settleWalletOrder({
    customerId: "customer-1", orderId: "order-1", amount: 6000,
    description: "Test order", fulfillmentType: "automatic", voucherCode: "PROMO", flashSaleId: 7,
  });
  assert.equal(retryBalance, 4000);
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM wallet_transactions WHERE direction = 'debit'").get().count, 1);
  assert.equal(database.prepare("SELECT used_count FROM discount_vouchers WHERE code = 'PROMO'").get().used_count, 1);
  assert.equal(database.prepare("SELECT sold_count FROM flash_sales WHERE id = 7").get().sold_count, 1);
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM order_events").get().count, 1);
  database.close();
});

test("wallet settlement does not debit when a limited promotion is exhausted", async () => {
  const database = createDatabase();
  const now = Date.now();
  database.prepare("INSERT INTO customer_users (id, balance) VALUES ('customer-1', 10000)").run();
  database.prepare("INSERT INTO wallet_transactions VALUES ('credit-1', 'customer-1', 'credit', 10000, 0, 10000, 'seed', 'Seed')").run();
  database.prepare("INSERT INTO orders (id, customer_id, payment_method) VALUES ('order-2', 'customer-1', 'wallet')").run();
  database.prepare("INSERT INTO discount_vouchers VALUES ('HABIS', 1, ?, ?, 1, 1, NULL)")
    .run(new Date(now - 60_000).toISOString(), new Date(now + 60_000).toISOString());
  setRuntimeEnv({ DB: new TestD1(database) });

  await assert.rejects(
    settleWalletOrder({
      customerId: "customer-1", orderId: "order-2", amount: 6000,
      description: "Test exhausted promo", fulfillmentType: "manual", voucherCode: "HABIS", flashSaleId: null,
    }),
    /Promo baru saja habis/,
  );
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM wallet_transactions WHERE direction = 'debit'").get().count, 0);
  assert.equal(database.prepare("SELECT payment_status FROM orders WHERE id = 'order-2'").get().payment_status, "pending");
  database.close();
});

test.after(async () => unregister());
