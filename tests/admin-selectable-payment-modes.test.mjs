import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("gateway modes are persisted in Admin-managed integration settings", () => {
  const source = read("lib/server/payment-mode-config.ts");
  assert.match(source, /doku_payment_mode/);
  assert.match(source, /midtrans_payment_mode/);
  assert.match(source, /"checkout" \| "direct"/);
  assert.match(source, /"snap" \| "bisnap"/);
  assert.match(source, /saveHostedGatewayProfile/);
});

test("per-channel gateway selection is stored in D1 and sync does not overwrite it", () => {
  const source = read("lib/server/payment-channels.ts");
  assert.match(source, /gateway = excluded\.gateway/);
  assert.match(source, /gateway_config_json/);
  const syncBlock = source.slice(source.indexOf("export async function syncPaymentChannelsForGateways"));
  assert.doesNotMatch(syncBlock, /gateway = excluded\.gateway/);
  const route = read("app/api/admin/payment-methods/route.ts");
  assert.doesNotMatch(route, /known\.gateway !== input\.gateway/);
});

test("order checkout dispatches through runtime-selected gateway mode", () => {
  const route = read("app/api/payments/auto/create/route.ts");
  const router = read("lib/server/payment-router.ts");
  assert.match(route, /createConfiguredPayment/);
  for (const call of [
    "createDokuCheckoutPayment",
    "createDokuDirectPayment",
    "createMidtransSnapPayment",
    "createMidtransVirtualAccount",
  ]) assert.match(router, new RegExp(`${call}\\(`));
});

test("wallet topup uses the same Admin-selected channel routing as checkout", () => {
  const route = read("app/api/account/topups/route.ts");
  assert.match(route, /getPaymentChannel\(/);
  assert.match(route, /managedChannel\.gateway/);
  assert.match(route, /createConfiguredPayment\(/);
  assert.match(route, /insertExternalWalletTopup/);
  assert.doesNotMatch(route, /createDokuDirectPayment\(/);
});

test("hosted gateway clients restrict each payment to the Admin-selected channel", () => {
  const doku = read("lib/server/doku-checkout.ts");
  const midtrans = read("lib/server/midtrans-snap.ts");
  assert.match(doku, /payment_method_types: \[paymentType\]/);
  assert.match(midtrans, /enabled_payments: \[enabledPayment\]/);
});

test("both hosted callbacks and future Direct\/BI-SNAP callbacks remain present", () => {
  assert.equal(fs.existsSync(path.join(root, "app/api/payments/doku/callback/route.ts")), true);
  assert.equal(fs.existsSync(path.join(root, "app/api/payments/midtrans/snap/notification/route.ts")), true);
  assert.equal(fs.existsSync(path.join(root, "app/api/payments/midtrans/v1.0/transfer-va/payment/route.ts")), true);
});

test("migration 0031 adds gateway mode identity to orders and wallet topups without deleting data", () => {
  const migration = read("drizzle/0031_admin_selectable_payment_modes.sql");
  for (const field of [
    "payment_gateway_mode",
    "payment_gateway",
    "gateway_environment",
    "gateway_request_id",
    "gateway_payment_url",
    "gateway_expired_at",
  ]) assert.match(migration, new RegExp(field));
  assert.doesNotMatch(migration, /DELETE FROM/i);
});

test("Super Admin UI keeps routing inside existing Payment and Integration workspaces", () => {
  const payment = read("components/admin-payment-workspace.tsx");
  const integration = read("components/admin-integration-workspace.tsx");
  const hosted = read("components/admin-hosted-gateway-integration.tsx");
  const dashboard = read("components/admin-dashboard.tsx");

  assert.match(payment, /Gateway & Environment/);
  assert.match(payment, /Checkout Biasa/);
  assert.match(payment, /Direct API/);
  assert.match(payment, /Snap/);
  assert.match(payment, /BI-SNAP/);
  assert.match(payment, /Tambah Metode/);
  assert.match(payment, /Hapus/);
  assert.match(payment, /Top up saldo memakai daftar metode dan routing gateway yang sama/);
  assert.match(payment, /Sandbox/);
  assert.match(payment, /Production/);

  assert.match(integration, /DOKU Checkout/);
  assert.match(integration, /DOKU Direct API/);
  assert.match(integration, /Midtrans Snap/);
  assert.match(integration, /Midtrans BI-SNAP/);
  assert.match(hosted, /Sandbox/);
  assert.match(hosted, /Production/);

  assert.doesNotMatch(dashboard, /AdminGatewayRoutingPanel/);
  assert.doesNotMatch(dashboard, /AdminHostedGatewayCredentialsPanel/);
  assert.match(dashboard, /<AdminPaymentWorkspace \/>/);
  assert.match(dashboard, /<AdminIntegrationWorkspace \/>/);
});
