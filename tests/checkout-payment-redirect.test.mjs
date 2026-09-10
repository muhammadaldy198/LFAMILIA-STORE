import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const payment = fs.readFileSync(path.join(root, "app/payment/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");

test("automatic checkout identifies DOKU only", () => {
  assert.match(autoRoute, /createDokuDirectPayment\(/);
  assert.doesNotMatch(autoRoute, /paymentGateway: "doku"/);
  assert.doesNotMatch(autoRoute, /midtrans|ipaymu/i);
});

test("external checkout first enters the LFAMILIA payment page", () => {
  assert.match(checkout, /\/payment\?invoice=/);
  assert.match(checkout, /encodeURIComponent\(invoice\)/);
});

test("external checkout retries reuse one idempotency key and one DOKU invoice", () => {
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

test("LFAMILIA payment page owns QRIS and VA rendering while e-wallet can redirect", () => {
  assert.match(payment, /QRCodeSVG/);
  assert.match(payment, /order\.paymentNo/);
  assert.match(payment, /window\.location\.assign\(order\.paymentUrl\)/);
  assert.doesNotMatch(payment, /DOKU Checkout|snap\.pay|midtrans|ipaymu/i);
});

test("legacy payment endpoints stay deleted", () => {
  for (const file of [
    "app/api/payments/midtrans/create/route.ts",
    "app/api/payments/midtrans/callback/route.ts",
    "app/api/payments/midtrans/client-config/route.ts",
    "app/api/payments/ipaymu/create/route.ts",
    "app/api/payments/ipaymu/callback/route.ts",
  ]) {
    assert.equal(fs.existsSync(path.join(root, file)), false, file);
  }
});
