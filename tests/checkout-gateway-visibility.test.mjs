import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const source = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const router = fs.readFileSync(path.join(root, "lib/server/payment-gateway-router.ts"), "utf8");

test("checkout hides gateway/provider selector from customers", () => {
  assert.doesNotMatch(source, /Pilih gateway pembayaran/);
  assert.doesNotMatch(source, /activeCheckoutGateway/);
  assert.doesNotMatch(source, /setActiveCheckoutGateway/);
  assert.match(source, /Pilih metode pembayaran yang ingin digunakan/);
});

test("gateway routing is handled server-side", () => {
  assert.match(source, /\/api\/payments\/auto\/create/);
  assert.match(autoRoute, /routePaymentGateway\(/);
  assert.match(router, /candidates: RoutedPaymentGateway\[\]/);
  assert.match(autoRoute, /createIpaymuDirectPayment\(/);
  assert.match(autoRoute, /createMidtransPayment\(/);
  assert.match(autoRoute, /const identity = createOrderIdentity\(\)/);
  assert.doesNotMatch(autoRoute, /createIpaymuCheckout|createMidtransCheckout/);
});
