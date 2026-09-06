import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const ipaymuRoute = fs.readFileSync(path.join(root, "app/api/payments/ipaymu/create/route.ts"), "utf8");

test("LFAMILIA does not hard-code an iPaymu minimum amount", () => {
  assert.doesNotMatch(checkout, /IPAYMU_MIN_CHECKOUT_AMOUNT|isIpaymuAmountSupported/);
  assert.doesNotMatch(ipaymuRoute, /IPAYMU_MIN_CHECKOUT_AMOUNT|isIpaymuAmountSupported/);
});

test("checkout sends external payments directly to iPaymu", () => {
  assert.match(checkout, /\/api\/payments\/ipaymu\/create/);
  assert.doesNotMatch(checkout, /\/api\/payments\/auto\/create/);
});

test("provider errors are returned to the customer without gateway fallback", () => {
  assert.match(ipaymuRoute, /IpaymuProviderError/);
  assert.doesNotMatch(ipaymuRoute, /fallbackAllowed/);
  assert.doesNotMatch(ipaymuRoute, /Midtrans/i);
});
