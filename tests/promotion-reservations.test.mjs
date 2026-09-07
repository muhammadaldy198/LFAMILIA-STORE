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

  async all() {
    return { results: this.statement.all(...this.values) };
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
const {
  applyPaymentStatus,
  getOrderById,
  insertPendingOrder,
  markPaymentCreationFailed,
} = await import("../lib/server/orders.ts");

function createDatabase() {
  const database = new DatabaseSync(":memory:");
  database.exec(`
    CREATE TABLE orders (
      id TEXT PRIMARY KEY, customer_id TEXT, wallet_checkout_key TEXT, reference_id TEXT UNIQUE,
      product_slug TEXT, product_name TEXT, package_sku TEXT, package_label TEXT,
      provider_code TEXT, provider_sku TEXT, fulfillment_type TEXT, target_template TEXT,
      destination TEXT, server TEXT, nickname TEXT, customer_no TEXT, buyer_name TEXT,
      buyer_email TEXT, buyer_phone TEXT, customer_notes TEXT, customer_inputs_json TEXT,
      base_subtotal INTEGER, subtotal INTEGER, discount_amount INTEGER, voucher_code TEXT,
      voucher_id INTEGER, flash_sale_id INTEGER, promotion_reservation_status TEXT NOT NULL DEFAULT 'legacy',
      promotion_reserved_until TEXT,
      admin_fee INTEGER, total INTEGER, payment_method TEXT, payment_channel TEXT,
      payment_status TEXT NOT NULL DEFAULT 'pending', fulfillment_status TEXT NOT NULL DEFAULT 'waiting_payment',
      midtrans_transaction_id TEXT, midtrans_payment_url TEXT,
      ipaymu_transaction_id TEXT, ipaymu_payment_url TEXT,
      provider_message TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE discount_vouchers (
      id INTEGER PRIMARY KEY, code TEXT UNIQUE, is_active INTEGER NOT NULL, starts_at TEXT NOT NULL, ends_at TEXT NOT NULL,
      usage_limit INTEGER, used_count INTEGER NOT NULL DEFAULT 0, updated_at TEXT
    );
    CREATE TABLE flash_sales (
      id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL, starts_at TEXT NOT NULL, ends_at TEXT NOT NULL,
      stock_limit INTEGER, sold_count INTEGER NOT NULL DEFAULT 0, updated_at TEXT
    );
  `);
  return database;
}

function seedPromotions(database) {
  const now = Date.now();
  database.prepare("INSERT INTO discount_vouchers VALUES (1, 'PROMO', 1, ?, ?, 1, 0, NULL)")
    .run(new Date(now - 60_000).toISOString(), new Date(now + 60_000).toISOString());
  database.prepare("INSERT INTO flash_sales VALUES (7, 1, ?, ?, 1, 0, NULL)")
    .run(new Date(now - 60_000).toISOString(), new Date(now + 60_000).toISOString());
}

function orderInput(id, referenceId) {
  return {
    id,
    referenceId,
    item: {
      productSlug: "manual-product",
      productName: "Manual Product",
      needsServer: false,
      fulfillmentType: "manual",
      targetTemplate: "{{destination}}",
      inputFields: [],
      manualInstructions: null,
      packageSku: "PACKAGE-1",
      packageLabel: "Package 1",
      price: 10_000,
      providerCode: null,
      providerSku: null,
    },
    destination: "customer-destination",
    server: null,
    nickname: null,
    buyerName: "Customer",
    buyerEmail: "customer@example.com",
    buyerPhone: "081234567890",
    customerNotes: null,
    customerInputs: [],
    paymentMethod: "qris",
    paymentChannel: "mpm",
    customerId: null,
    promotion: {
      basePrice: 10_000,
      sellingPrice: 8_000,
      discountAmount: 1_000,
      finalPrice: 7_000,
      voucherCode: "PROMO",
      voucherId: 1,
      flashSaleId: 7,
      flashSaleEndsAt: null,
      memberTier: null,
      memberDiscountPercent: 0,
      memberDiscountAmount: 0,
      discountSource: "voucher",
    },
  };
}

test("external checkout reserves capped promotions and releases them exactly once", async () => {
  const database = createDatabase();
  seedPromotions(database);
  setRuntimeEnv({ DB: new TestD1(database) });

  await insertPendingOrder(orderInput("order-1", "REF-1"));
  assert.deepEqual(
    {
      voucher: database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE code = 'PROMO'").get().count,
      flash: database.prepare("SELECT sold_count AS count FROM flash_sales WHERE id = 7").get().count,
      state: database.prepare("SELECT promotion_reservation_status AS state FROM orders WHERE id = 'order-1'").get().state,
    },
    { voucher: 1, flash: 1, state: "reserved" },
  );

  await assert.rejects(
    insertPendingOrder(orderInput("order-2", "REF-2")),
    /baru saja habis/,
  );
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM orders").get().count, 1);

  const first = await getOrderById("order-1");
  await applyPaymentStatus(first, "failed");
  await applyPaymentStatus(first, "failed");
  assert.deepEqual(
    {
      voucher: database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE code = 'PROMO'").get().count,
      flash: database.prepare("SELECT sold_count AS count FROM flash_sales WHERE id = 7").get().count,
      state: database.prepare("SELECT promotion_reservation_status AS state FROM orders WHERE id = 'order-1'").get().state,
    },
    { voucher: 0, flash: 0, state: "released" },
  );

  await insertPendingOrder(orderInput("order-2", "REF-2"));
  const second = await getOrderById("order-2");
  assert.equal(await applyPaymentStatus(second, "paid"), true);
  assert.equal(await applyPaymentStatus(second, "paid"), false);
  assert.deepEqual(
    {
      voucher: database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE code = 'PROMO'").get().count,
      flash: database.prepare("SELECT sold_count AS count FROM flash_sales WHERE id = 7").get().count,
      state: database.prepare("SELECT promotion_reservation_status AS state FROM orders WHERE id = 'order-2'").get().state,
    },
    { voucher: 1, flash: 1, state: "consumed" },
  );
  database.close();
});

test("gateway creation failure releases a reservation", async () => {
  const database = createDatabase();
  seedPromotions(database);
  setRuntimeEnv({ DB: new TestD1(database) });

  await insertPendingOrder(orderInput("order-failed", "REF-FAILED"));
  await markPaymentCreationFailed("REF-FAILED", "Gateway unavailable");
  await markPaymentCreationFailed("REF-FAILED", "Repeated failure");

  assert.deepEqual(
    {
      payment: database.prepare("SELECT payment_status AS value FROM orders WHERE id = 'order-failed'").get().value,
      state: database.prepare("SELECT promotion_reservation_status AS value FROM orders WHERE id = 'order-failed'").get().value,
      voucher: database.prepare("SELECT used_count AS value FROM discount_vouchers WHERE code = 'PROMO'").get().value,
      flash: database.prepare("SELECT sold_count AS value FROM flash_sales WHERE id = 7").get().value,
    },
    { payment: "failed", state: "released", voucher: 0, flash: 0 },
  );
  database.close();
});

test("voucher rename cannot orphan a reservation", async () => {
  const database = createDatabase();
  seedPromotions(database);
  setRuntimeEnv({ DB: new TestD1(database) });

  await insertPendingOrder(orderInput("order-rename", "REF-RENAME"));
  database.prepare("UPDATE discount_vouchers SET code = 'RENAMED' WHERE id = 1").run();
  await markPaymentCreationFailed("REF-RENAME", "Gateway unavailable");

  assert.equal(database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE id = 1").get().count, 0);
  assert.equal(database.prepare("SELECT promotion_reservation_status AS state FROM orders WHERE id = 'order-rename'").get().state, "released");
  database.close();
});

test("scheduled recovery releases an abandoned reservation atomically and idempotently", async () => {
  const database = createDatabase();
  seedPromotions(database);
  setRuntimeEnv({ DB: new TestD1(database) });

  await insertPendingOrder(orderInput("order-abandoned", "REF-ABANDONED"));
  database.prepare(
    "UPDATE orders SET promotion_reserved_until = datetime('now', '-1 minute') WHERE id = 'order-abandoned'",
  ).run();
  const { releaseExpiredPromotionReservation } = await import("../lib/server/orders.ts");
  assert.equal(await releaseExpiredPromotionReservation("order-abandoned"), true);
  assert.equal(await releaseExpiredPromotionReservation("order-abandoned"), false);
  assert.deepEqual(
    {
      payment: database.prepare("SELECT payment_status AS value FROM orders WHERE id = 'order-abandoned'").get().value,
      state: database.prepare("SELECT promotion_reservation_status AS value FROM orders WHERE id = 'order-abandoned'").get().value,
      voucher: database.prepare("SELECT used_count AS value FROM discount_vouchers WHERE id = 1").get().value,
      flash: database.prepare("SELECT sold_count AS value FROM flash_sales WHERE id = 7").get().value,
    },
    { payment: "failed", state: "released", voucher: 0, flash: 0 },
  );
  database.close();
});

test("expired attached payment releases quota and a late success consumes it once", async () => {
  const database = createDatabase();
  seedPromotions(database);
  setRuntimeEnv({ DB: new TestD1(database) });
  const { releaseExpiredPromotionReservation } = await import("../lib/server/orders.ts");

  await insertPendingOrder(orderInput("order-attached", "REF-ATTACHED"));
  database.prepare(
    "UPDATE orders SET promotion_reserved_until = datetime('now', '-1 minute'), midtrans_payment_url = 'https://pay.example.test' WHERE id = 'order-attached'",
  ).run();
  assert.equal(await releaseExpiredPromotionReservation("order-attached"), true);
  assert.equal(database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE id = 1").get().count, 0);
  assert.equal(database.prepare("SELECT sold_count AS count FROM flash_sales WHERE id = 7").get().count, 0);

  const released = await getOrderById("order-attached");
  assert.equal(await applyPaymentStatus(released, "paid"), true);
  assert.equal(await applyPaymentStatus(released, "paid"), false);
  assert.equal(await releaseExpiredPromotionReservation("order-attached"), false);
  assert.equal(database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE id = 1").get().count, 1);
  assert.equal(database.prepare("SELECT sold_count AS count FROM flash_sales WHERE id = 7").get().count, 1);
  database.close();
});

test("paid callback wins safely when it races expiration cleanup", async () => {
  const database = createDatabase();
  seedPromotions(database);
  setRuntimeEnv({ DB: new TestD1(database) });
  const { releaseExpiredPromotionReservation } = await import("../lib/server/orders.ts");

  await insertPendingOrder(orderInput("order-paid-first", "REF-PAID-FIRST"));
  database.prepare(
    "UPDATE orders SET promotion_reserved_until = datetime('now', '-1 minute') WHERE id = 'order-paid-first'",
  ).run();
  const pending = await getOrderById("order-paid-first");
  assert.equal(await applyPaymentStatus(pending, "paid"), true);
  assert.equal(await releaseExpiredPromotionReservation("order-paid-first"), false);
  assert.equal(database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE id = 1").get().count, 1);
  assert.equal(database.prepare("SELECT sold_count AS count FROM flash_sales WHERE id = 7").get().count, 1);
  database.close();
});

test("reservation batch rolls back the order and all counters on a write failure", async () => {
  const database = createDatabase();
  seedPromotions(database);
  database.exec(`
    CREATE TRIGGER reject_flash_reservation BEFORE UPDATE OF sold_count ON flash_sales
    BEGIN SELECT RAISE(ABORT, 'flash reservation write failed'); END;
  `);
  setRuntimeEnv({ DB: new TestD1(database) });

  await assert.rejects(
    insertPendingOrder(orderInput("order-rollback", "REF-ROLLBACK")),
    /flash reservation write failed/,
  );
  assert.equal(database.prepare("SELECT COUNT(*) AS count FROM orders").get().count, 0);
  assert.equal(database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE code = 'PROMO'").get().count, 0);
  assert.equal(database.prepare("SELECT sold_count AS count FROM flash_sales WHERE id = 7").get().count, 0);
  database.close();
});

test("legacy pending orders consume promotions once when paid", async () => {
  const database = createDatabase();
  seedPromotions(database);
  database.prepare(
    `INSERT INTO orders (
      id, reference_id, fulfillment_type, voucher_code, voucher_id, flash_sale_id,
      promotion_reservation_status, payment_method, payment_channel
    ) VALUES ('legacy-order', 'LEGACY-REF', 'manual', 'PROMO', NULL, 7, 'legacy', 'qris', 'mpm')`,
  ).run();
  setRuntimeEnv({ DB: new TestD1(database) });

  const order = await getOrderById("legacy-order");
  assert.equal(await applyPaymentStatus(order, "paid"), true);
  assert.equal(await applyPaymentStatus(order, "paid"), false);
  assert.equal(database.prepare("SELECT used_count AS count FROM discount_vouchers WHERE code = 'PROMO'").get().count, 1);
  assert.equal(database.prepare("SELECT sold_count AS count FROM flash_sales WHERE id = 7").get().count, 1);
  assert.equal(database.prepare("SELECT promotion_reservation_status AS state FROM orders WHERE id = 'legacy-order'").get().state, "consumed");
  database.close();
});

test.after(async () => unregister());
