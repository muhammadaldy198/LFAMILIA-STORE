import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const source = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const enhancer = fs.readFileSync(path.join(root, "components/checkout-ui-enhancer.tsx"), "utf8");
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

test("legacy DOM controller no longer hides or toggles payment groups", () => {
  assert.doesNotMatch(enhancer, /enhancePaymentGroups/);
  assert.doesNotMatch(enhancer, /expandedMethods/);
  assert.doesNotMatch(enhancer, /wiredHeaders/);
  assert.doesNotMatch(enhancer, /fetch\("\/api\/payment-methods"/);
});

test("checkout shows a diagnostic instead of a blank payment section", () => {
  assert.match(source, /Metode pembayaran otomatis belum tersedia/);
});

test("checkout does not switch to wallet before payment methods finish loading", () => {
  assert.match(source, /paymentMethodsLoaded/);
  assert.match(source, /if \(!paymentMethodsLoaded\) return/);
  assert.match(source, /paymentMethod === "wallet"\s*\? Boolean\(account\)/);
  assert.match(source, /if \(account\) \{\s*chooseMethod\("wallet"\)/);
  assert.match(source, /Memuat metode pembayaran/);
});
