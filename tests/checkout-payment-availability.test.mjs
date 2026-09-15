import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const source = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const methodsRoute = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");
const channelsSource = fs.readFileSync(path.join(root, "lib/server/payment-channels.ts"), "utf8");

test("checkout methods are filtered by active and ready internal gateways without exposing them", () => {
  assert.match(methodsRoute, /listPaymentGatewaySettings/);
  assert.match(methodsRoute, /getDokuReadiness/);
  assert.match(methodsRoute, /getMidtransReadiness/);
  assert.match(methodsRoute, /isProviderRelayConfigured\("midtrans"\)/);
  assert.match(methodsRoute, /\.map\(\(item\) => \(\{/);
  assert.doesNotMatch(methodsRoute, /gateway: item\.gateway/);
  assert.doesNotMatch(methodsRoute, /gatewayConfig: item\.gatewayConfig/);
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

test("gateway channel sync never enables merchant channels automatically", () => {
  const syncStart = channelsSource.indexOf("export async function syncPaymentChannelsForGateways");
  const syncEnd = channelsSource.indexOf("\nexport async function syncPaymentChannelsForGateway(", syncStart + 1);
  const syncSource = channelsSource.slice(syncStart, syncEnd);
  assert.match(channelsSource, /isActive: false/);
  assert.match(syncSource, /activationPolicy: "manual"/);
  assert.match(syncSource, /VALUES \(\?, \?, \?, \?, NULL, 0,/);
  assert.doesNotMatch(syncSource, /is_active = excluded\.is_active/);
});

test("routing uses the gateway assignment and per-channel provider code persisted in D1", () => {
  assert.match(channelsSource, /gateway: item\.gateway/);
  assert.match(channelsSource, /isGatewayChannelSupported\(input\.gateway, input\.method, input\.channel, input\.gatewayConfig\)/);
  assert.match(channelsSource, /gateway_config_json/);
  assert.match(channelsSource, /gateway = excluded\.gateway/);
  assert.doesNotMatch(channelsSource, /method !== "va" && isDokuChannelSupported/);
});
