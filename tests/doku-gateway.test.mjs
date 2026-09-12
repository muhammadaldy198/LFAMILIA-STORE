import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const doku = fs.readFileSync(path.join(root, "lib/server/doku.ts"), "utf8");
const callback = fs.readFileSync(path.join(root, "app/api/payments/doku/callback/route.ts"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const providers = fs.readFileSync(path.join(root, "lib/server/providers/index.ts"), "utf8");
const providerOptions = fs.readFileSync(path.join(root, "lib/provider-options.ts"), "utf8");

test("DOKU Direct API uses SNAP token and payment endpoints with asymmetric and symmetric signatures", () => {
  assert.match(doku, /authorization\/v1\/access-token\/b2b/);
  assert.match(doku, /snap-adapter\/b2b\/v1\.0\/qr\/qr-mpm-generate/);
  assert.match(doku, /direct-debit\/core\/v1\/debit\/payment-host-to-host/);
  assert.match(doku, /virtual-accounts\/bi-snap-va\/v1\.1\/transfer-va\/create-va/);
  assert.match(doku, /RSA-SHA256/);
  assert.match(doku, /hmacBase64\("sha512"/);
  assert.match(doku, /x-signature/);
  assert.match(doku, /x-partner-id/);
});

test("DOKU callback validates signature, amount, and request identity before fulfillment", () => {
  assert.match(callback, /validateDokuNotification\(/);
  assert.match(callback, /callbackAmount !== order\.total/);
  assert.match(callback, /order\.doku_request_id/);
  assert.match(callback, /originalRequestId/);
  assert.match(callback, /fulfillAutomaticOrder\(/);
});

test("checkout creates one DOKU payment without legacy gateway fallback", () => {
  assert.match(autoRoute, /createDokuDirectPayment\(/);
  assert.doesNotMatch(autoRoute, /paymentGateway: "doku"/);
  assert.doesNotMatch(autoRoute, /midtrans|ipaymu|fallback/i);
});

test("DOKU database readiness includes current Direct API artifacts", () => {
  const preparation = fs.readFileSync(path.join(root, "lib/server/doku-database-preparation.ts"), "utf8");
  for (const column of [
    "doku_reference_no",
    "doku_payment_no",
    "doku_qr_content",
    "doku_payment_name",
    "doku_status_checked_at",
  ]) {
    assert.match(preparation, new RegExp(column));
  }
});

test("DOKU database preparation is schema-only and non-destructive", () => {
  const preparation = fs.readFileSync(path.join(root, "lib/server/doku-database-preparation.ts"), "utf8");
  assert.match(preparation, /ALTER TABLE/);
  assert.doesNotMatch(preparation, /DELETE FROM (orders|wallet_transactions|wallet_topups|order_events|voucher_deliveries)/);
});

test("DigiFlazz is the only external fulfillment provider", () => {
  assert.match(providers, /digiflazzAdapter/);
  assert.doesNotMatch(providers, /vippayment|VIPPayment/i);
  assert.match(providerOptions, /code: "digiflazz"/);
  assert.doesNotMatch(providerOptions, /vippayment|VIPPayment/i);
});

test("DOKU callback replay fallback and customer invoice expiry are deterministic", () => {
  const status = fs.readFileSync(path.join(root, "app/api/orders/status/route.ts"), "utf8");
  assert.match(callback, /hashHex\("sha256", rawBody\)/);
  assert.match(status, /async function expirePendingInvoice/);
  assert.match(status, /order = await expirePendingInvoice\(order\)/);
});

test("production DOKU and DigiFlazz calls are guarded in automated tests", () => {
  const runtime = fs.readFileSync(path.join(root, "lib/server/runtime-env.ts"), "utf8");
  const digiflazz = fs.readFileSync(path.join(root, "lib/server/providers/digiflazz.ts"), "utf8");
  assert.match(runtime, /isAutomatedTestRuntime/);
  assert.match(doku, /DOKU production dinonaktifkan saat automated test/);
  assert.match(digiflazz, /DigiFlazz production dinonaktifkan saat automated test/);
});
