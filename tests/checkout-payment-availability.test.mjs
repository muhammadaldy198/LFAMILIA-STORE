import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const source = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const methodsRoute = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");

test("checkout methods come from the active DOKU gateway", () => {
  assert.doesNotMatch(methodsRoute, /code: "doku"|doku:/);
  assert.match(methodsRoute, /isDokuChannelSupported/);
  assert.match(source, /displayChannels/);
});

test("method and channel selection does not store a gateway in browser state", () => {
  assert.doesNotMatch(source, /activeCheckoutGateway|preferredGateways|setGateway\(/);
});

test("payment groups are controlled directly by checkout React state", () => {
  assert.equal(fs.existsSync(path.join(root, "components/checkout-ui-enhancer.tsx")), false);
  assert.match(source, /checkoutGroups\.map\(\(group\)/);
  assert.match(source, /chooseMethod\(group\.code\)/);
});

test("checkout shows a non-blank generic message when payment methods are unavailable", () => {
  assert.match(source, /Pembayaran otomatis belum tersedia/);
  assert.doesNotMatch(source, /Pembayaran melalui gateway belum tersedia/);
});

test("checkout waits for payment methods before defaulting to wallet", () => {
  assert.match(source, /paymentMethodsLoaded/);
  assert.match(source, /Memuat metode pembayaran/);
});


test("DOKU channel sync never enables merchant channels automatically", () => {
  const channels = fs.readFileSync(path.join(root, "lib/server/payment-channels.ts"), "utf8");
  const syncStart = channels.indexOf("export async function syncPaymentChannelsForGateways");
  const syncEnd = channels.indexOf("\nexport async function syncPaymentChannelsForGateway(", syncStart + 1);
  const syncSource = channels.slice(syncStart, syncEnd);
  assert.match(channels, /isActive: false/);
  assert.match(syncSource, /activationPolicy: "manual"/);
  assert.match(syncSource, /\.bind\([\s\S]*?item\.description,[\s\S]*?0,[\s\S]*?index/);
  assert.doesNotMatch(syncSource, /is_active = excluded\.is_active/);
});
