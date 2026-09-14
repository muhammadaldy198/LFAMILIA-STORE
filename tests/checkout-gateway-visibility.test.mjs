import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const publicMethods = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");

test("checkout hides payment gateway selection from customers", () => {
  assert.doesNotMatch(checkout, /Pilih gateway pembayaran/);
  assert.doesNotMatch(checkout, /activeCheckoutGateway|setActiveCheckoutGateway/);
  assert.match(checkout, /Pilih metode pembayaran yang ingin digunakan/);
});

test("automatic checkout routes internally through the Admin-selected gateway mode", () => {
  assert.match(autoRoute, /getPaymentChannel\(/);
  assert.match(autoRoute, /getConfiguredGatewayReadiness\(/);
  assert.match(autoRoute, /createConfiguredPayment\(/);
  assert.match(autoRoute, /gateway: managedChannel\.gateway/);
  assert.match(autoRoute, /mode: payment\.mode/);
  assert.match(autoRoute, /const identity = createOrderIdentity\(\)/);
  assert.doesNotMatch(autoRoute, /iPaymu/i);
});

test("public method response strips gateway identity and private gateway config", () => {
  assert.match(publicMethods, /name: item\.name/);
  assert.match(publicMethods, /description: item\.description/);
  assert.doesNotMatch(publicMethods, /gateway: item\.gateway/);
  assert.doesNotMatch(publicMethods, /gatewayConfig: item\.gatewayConfig/);
});
