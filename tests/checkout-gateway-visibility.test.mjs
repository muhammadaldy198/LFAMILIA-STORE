import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "app/checkout/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(process.cwd(), "app/api/payments/auto/create/route.ts"), "utf8");

test("checkout hides gateway/provider selector from customers", () => {
  assert.doesNotMatch(source, /Pilih gateway pembayaran/);
  assert.doesNotMatch(source, /activeCheckoutGateway/);
  assert.doesNotMatch(source, /setActiveCheckoutGateway/);
  assert.match(source, /Pilih metode pembayaran yang ingin digunakan/);
});

test("gateway routing is handled server-side", () => {
  assert.match(source, /\/api\/payments\/auto\/create/);
  assert.match(autoRoute, /canUseIpaymu/);
  assert.match(autoRoute, /createIpaymuCheckout\(ipaymuRequest\)/);
  assert.match(autoRoute, /fallbackAllowed/);
  assert.match(autoRoute, /createMidtransCheckout\(midtransRequest\)/);
});
