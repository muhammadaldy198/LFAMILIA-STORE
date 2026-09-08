import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const doku = fs.readFileSync(path.join(root, "lib/server/doku.ts"), "utf8");

test("checkout hides payment gateway selection from customers", () => {
  assert.doesNotMatch(checkout, /Pilih gateway pembayaran/);
  assert.doesNotMatch(checkout, /activeCheckoutGateway|setActiveCheckoutGateway/);
  assert.match(checkout, /Pilih metode pembayaran yang ingin digunakan/);
});

test("automatic checkout uses DOKU as the only external gateway", () => {
  assert.match(autoRoute, /createDokuDirectPayment\(/);
  assert.match(autoRoute, /paymentGateway: "doku"/);
  assert.match(autoRoute, /const identity = createOrderIdentity\(\)/);
  assert.doesNotMatch(autoRoute, /routePaymentGateway|Midtrans|iPaymu/i);
  assert.match(doku, /authorization\/v1\/access-token\/b2b/);\n  assert.match(doku, /qr-mpm-generate/);
});
