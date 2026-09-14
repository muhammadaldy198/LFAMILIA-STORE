import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const payment = fs.readFileSync(path.join(root, "app/payment/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const router = fs.readFileSync(path.join(root, "lib/server/payment-router.ts"), "utf8");

test("automatic checkout uses Admin-selected gateway routing", () => {
  assert.match(autoRoute, /createConfiguredPayment\(/);
  assert.match(autoRoute, /managedChannel\.gateway/);
  assert.match(router, /createDokuCheckoutPayment\(/);
  assert.match(router, /createDokuDirectPayment\(/);
  assert.match(router, /createMidtransSnapPayment\(/);
  assert.match(router, /createMidtransVirtualAccount\(/);
  assert.doesNotMatch(autoRoute, /ipaymu/i);
});

test("external checkout first enters the LFAMILIA payment page", () => {
  assert.match(checkout, /\/payment\?invoice=/);
  assert.match(checkout, /encodeURIComponent\(invoice\)/);
});

test("external checkout retries reuse one idempotency key and one provider invoice", () => {
  const orders = fs.readFileSync(path.join(root, "lib/server/orders.ts"), "utf8");
  const migration = fs.readFileSync(path.join(root, "drizzle/0026_external_checkout_idempotency.sql"), "utf8");
  assert.match(checkout, /checkoutAttemptRef/);
  assert.match(checkout, /idempotencyKey: checkoutAttemptRef\.current\?\.key/);
  assert.match(autoRoute, /idempotencyKey: z\.string\(\)\.uuid\(\)/);
  assert.match(autoRoute, /getExternalOrderByCheckoutKey/);
  assert.match(autoRoute, /externalCheckoutKey: input\.idempotencyKey/);
  assert.match(autoRoute, /UNIQUE constraint failed\.\*external_checkout_key/);
  assert.match(orders, /external_checkout_key/);
  assert.match(migration, /orders_external_checkout_key_unique/);
});

test("LFAMILIA payment page owns native artifacts and can redirect to hosted flows", () => {
  assert.match(payment, /QRCodeSVG/);
  assert.match(payment, /order\.paymentNo/);
  assert.match(payment, /window\.location\.assign\(order\.paymentUrl\)/);
  assert.doesNotMatch(payment, /Pilih gateway pembayaran/i);
});

test("both Midtrans Snap and BI-SNAP notification routes exist", () => {
  assert.equal(fs.existsSync(path.join(root, "app/api/payments/midtrans/snap/notification/route.ts")), true);
  assert.equal(fs.existsSync(path.join(root, "app/api/payments/midtrans/v1.0/transfer-va/payment/route.ts")), true);
  for (const file of [
    "app/api/payments/ipaymu/create/route.ts",
    "app/api/payments/ipaymu/callback/route.ts",
  ]) {
    assert.equal(fs.existsSync(path.join(root, file)), false, file);
  }
});
