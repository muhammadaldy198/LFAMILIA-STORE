import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const payment = fs.readFileSync(path.join(root, "app/payment/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");

test("automatic checkout identifies DOKU only", () => {
  assert.match(autoRoute, /paymentGateway: "doku"/);
  assert.doesNotMatch(autoRoute, /midtrans|ipaymu/i);
});

test("external checkout first enters the LFAMILIA payment page", () => {
  assert.match(checkout, /\/payment\?invoice=/);
  assert.match(checkout, /encodeURIComponent\(invoice\)/);
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
