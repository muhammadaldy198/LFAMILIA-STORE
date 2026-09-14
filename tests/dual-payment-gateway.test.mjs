import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("migration adds provider-neutral payment routing without deleting transactions", () => {
  const migration = read("drizzle/0030_dual_payment_gateways.sql");
  for (const field of [
    "payment_gateway",
    "payment_gateway_environment",
    "gateway_request_id",
    "gateway_reference_no",
    "gateway_payment_no",
    "gateway_qr_content",
    "gateway_payment_url",
    "gateway_expired_at",
  ]) assert.match(migration, new RegExp(field));
  assert.match(migration, /payment_gateway_settings/);
  assert.match(migration, /gateway_config_json/);
  assert.doesNotMatch(migration, /DELETE FROM\s+(orders|wallet_topups|wallet_transactions|order_events)/i);
});

test("Midtrans BI-SNAP client signs token and transactional requests and requires relay", () => {
  const source = read("lib/server/midtrans.ts");
  assert.match(source, /\/v1\.0\/access-token\/b2b/);
  assert.match(source, /\/v1\.0\/transfer-va\/create-va/);
  assert.match(source, /RSA-SHA256/);
  assert.match(source, /createHmac\("sha512"/);
  assert.match(source, /x-external-id/);
  assert.match(source, /channel-id/);
  assert.match(source, /providerRelayRequest/);
  assert.match(source, /BI-SNAP membutuhkan outgoing IP statis/);
  assert.match(source, /expectedEnvironment\?: MidtransEnvironment/);
  assert.match(source, /getConfig\(input\.expectedEnvironment \?\? getMidtransEnvironment\(\)\)/);
});

test("Midtrans VA notification verifies signature, stored environment, VA identity, amount, gateway ownership, and BI-SNAP response contract", () => {
  const source = read("app/api/payments/midtrans/v1.0/transfer-va/payment/route.ts");
  assert.match(source, /verifyMidtransNotification/);
  assert.match(source, /const minifiedBody = JSON\.stringify\(body\)/);
  assert.match(source, /rawBody: minifiedBody/);
  assert.match(source, /"X-TIMESTAMP": responseTimestamp\(\)/);
  assert.match(source, /payment_gateway_environment/);
  assert.match(source, /getMidtransPartnerId\(expectedEnvironment\)/);
  assert.match(source, /expectedEnvironment,/);
  assert.match(source, /\/\^\\d\+\$\/\.test\(externalId\)/);
  assert.match(source, /gateway_payment_no/);
  assert.match(source, /virtualAccountNo !== `\$\{partnerServiceId\}\$\{customerNo\}`/);
  assert.match(source, /payment_gateway.*midtrans/);
  assert.match(source, /payment_gateway_mode.*bisnap/s);
  assert.match(source, /callbackAmount !== order\.total/);
  assert.match(source, /responseCode: "4042513"/);
  assert.match(source, /eventId: `notification-\$\{externalId\}`/);
  assert.match(source, /recordExternalPaymentEvent/);
  assert.match(source, /applyPaymentStatus/);
  assert.match(source, /fulfillAutomaticOrder/);
  assert.match(source, /responseCode: "2002500"/);
  assert.match(source, /responseMessage: "Successful"/);
});

test("Midtrans BI-SNAP VA callback also credits wallet topups through the generic ledger", () => {
  const source = read("app/api/payments/midtrans/v1.0/transfer-va/payment/route.ts");
  assert.match(source, /getExternalWalletTopup\(referenceId, "midtrans"\)/);
  assert.match(source, /callbackAmount !== walletTopup\.amount/);
  assert.match(source, /applyExternalWalletTopup\(\{/);
  assert.match(source, /gateway: "midtrans"/);
  assert.match(source, /notifyWalletTopupSuccessById/);
});

test("provider credentials are encrypted Admin-managed config, not relay environment secrets", () => {
  const integrations = read("lib/server/integration-config.ts");
  const relay = read("relay/server.mjs");
  assert.match(integrations, /"midtrans:direct"/);
  assert.match(integrations, /clientSecret/);
  assert.match(integrations, /privateKey/);
  assert.match(integrations, /encryptConfig/);
  assert.doesNotMatch(relay, /MIDTRANS_CLIENT_SECRET|MIDTRANS_PRIVATE_KEY|MIDTRANS_PARTNER_ID|MIDTRANS_MERCHANT_ID/);
});

test("customer-facing payment method API never returns gateway identity", () => {
  const source = read("app/api/payment-methods/route.ts");
  assert.match(source, /method: item\.method/);
  assert.match(source, /channel: item\.channel/);
  assert.match(source, /name: item\.name/);
  assert.doesNotMatch(source, /gateway: item\.gateway/);
  assert.doesNotMatch(source, /gatewayConfig: item\.gatewayConfig/);
});
