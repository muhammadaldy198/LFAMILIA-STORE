import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const publicMethods = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");

function publicPaymentMap(source) {
  const activeChannels = source.indexOf("const activeChannels = channels");
  const start = source.indexOf(".map((item) => ({", activeChannels);
  const end = source.indexOf("}));", start);
  assert.ok(activeChannels >= 0 && start >= 0 && end > start);
  return source.slice(start, end);
}

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
  const responseMap = publicPaymentMap(publicMethods);
  assert.match(responseMap, /name: item\.name/);
  assert.match(responseMap, /description: item\.description/);
  assert.doesNotMatch(responseMap, /gateway:/);
  assert.doesNotMatch(responseMap, /gatewayConfig/);
});
