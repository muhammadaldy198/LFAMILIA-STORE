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
  assert.match(autoRoute, /paymentGateway: "doku"/);
  assert.doesNotMatch(autoRoute, /midtrans|ipaymu|fallback/i);
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
