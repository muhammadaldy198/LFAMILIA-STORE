import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const router = fs.readFileSync(path.join(root, "lib/server/payment-gateway-router.ts"), "utf8");
const limits = fs.readFileSync(path.join(root, "lib/payment-limits.ts"), "utf8");

test("iPaymu checkout minimum is centralized", () => {
  assert.match(limits, /IPAYMU_MIN_CHECKOUT_AMOUNT = 10_000/);
  assert.match(limits, /isIpaymuAmountSupported/);
});

test("checkout hides iPaymu-only channels below the minimum", () => {
  assert.match(checkout, /gateway\.code !== "ipaymu"/);
  assert.match(checkout, /isIpaymuAmountSupported\(subtotal\)/);
});

test("shared router prefers iPaymu when eligible and keeps Midtrans fallback", () => {
  assert.match(router, /const ipaymuEligible/);
  assert.match(router, /isIpaymuAmountSupported\(input\.amount\)/);
  assert.match(router, /const midtransEligible/);
  assert.match(router, /if \(ipaymuEligible\) candidates\.push\("ipaymu"\)/);
  assert.match(router, /if \(midtransEligible\) candidates\.push\("midtrans"\)/);
});

test("checkout uses shared routing and reuses one order across fallback", () => {
  assert.match(autoRoute, /routePaymentGateway\(/);
  assert.match(autoRoute, /const identity = createOrderIdentity\(\)/);
  assert.match(autoRoute, /await insertPendingOrder\(/);
  assert.match(autoRoute, /createIpaymuDirectPayment\(/);
  assert.match(autoRoute, /fallback !== "midtrans"/);
  assert.match(autoRoute, /createMidtransPayment\(/);
  assert.doesNotMatch(autoRoute, /createIpaymuCheckout|createMidtransCheckout/);
});

test("below-minimum checkout explains the iPaymu limit without requiring Midtrans", () => {
  assert.match(autoRoute, /Pilih nominal lain atau gunakan Koin LFAMILIA/);
  assert.match(router, /isIpaymuAmountSupported\(input\.amount\)/);
  assert.match(checkout, /iPaymu tersedia mulai/);
});

test("automatic checkout creates exactly one order identity", () => {
  assert.equal((autoRoute.match(/createOrderIdentity\(\)/g) ?? []).length, 1);
  assert.equal((autoRoute.match(/await insertPendingOrder\(/g) ?? []).length, 1);
});
