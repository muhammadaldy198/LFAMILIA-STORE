import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const source = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const methodsRoute = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");

test("checkout methods are derived from all eligible gateways", () => {
  assert.match(source, /checkoutGatewayCandidates/);
  assert.match(source, /displayChannels/);
  assert.match(source, /for \(const gateway of checkoutGatewayCandidates\)/);
  assert.match(methodsRoute, /allChannels/);
});

test("method and channel selection no longer stores a gateway in browser state", () => {
  assert.match(source, /displayChannels\.find\(\(channel\) => channel\.method === method\)/);
  assert.doesNotMatch(source, /activeCheckoutGateway/);
  assert.doesNotMatch(source, /preferredGateways/);
  assert.doesNotMatch(source, /availableChannels/);
});

test("payment groups are controlled directly by checkout React state", () => {
  assert.equal(
    fs.existsSync(path.join(root, "components/checkout-ui-enhancer.tsx")),
    false,
  );
  assert.match(source, /checkoutGroups\.map\(\(group\)/);
  assert.match(source, /chooseMethod\(group\.code\)/);
  assert.doesNotMatch(source, /enhancePaymentGroups|expandedMethods|wiredHeaders/);
});

test("checkout explains unavailable gateways without leaving the payment section blank", () => {
  assert.match(source, /Pembayaran melalui gateway belum tersedia/);
  assert.match(source, /iPaymu tersedia mulai/);
});

test("checkout does not switch to wallet before payment methods finish loading", () => {
  assert.match(source, /paymentMethodsLoaded/);
  assert.match(source, /if \(!paymentMethodsLoaded\) return/);
  assert.match(source, /paymentMethod === "wallet"\s*\? Boolean\(account\)/);
  assert.match(source, /if \(account\) \{\s*chooseMethod\("wallet"\)/);
  assert.match(source, /Memuat metode pembayaran/);
});
