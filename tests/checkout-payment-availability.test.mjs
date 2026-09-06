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

test("checkout enhancer reads combined gateway channels", () => {
  assert.match(enhancer, /data\.allChannels/);
  assert.match(enhancer, /data\.gateways\?\.flatMap/);
});

test("checkout shows a diagnostic instead of a blank payment section", () => {
  assert.match(source, /Metode pembayaran otomatis belum tersedia/);
});
