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

test("DOKU Checkout uses explicit sandbox and production endpoints with signed requests", () => {
  assert.match(doku, /https:\/\/api-sandbox\.doku\.com\/checkout\/v1\/payment/);
  assert.match(doku, /https:\/\/api\.doku\.com\/checkout\/v1\/payment/);
  assert.match(doku, /HMACSHA256=/);
  assert.match(doku, /Digest:/);
  assert.match(doku, /Client-Id:/);
  assert.match(doku, /Request-Target:/);
});

test("DOKU callback validates signature, amount, and request identity before fulfillment", () => {
  assert.match(callback, /validateDokuNotification\(/);
  assert.match(callback, /callbackAmount !== order\.total/);
  assert.match(callback, /order\.doku_request_id/);
  assert.match(callback, /originalRequestId/);
  assert.match(callback, /fulfillAutomaticOrder\(/);
});

test("checkout creates one DOKU payment without legacy gateway fallback", () => {
  assert.match(autoRoute, /createDokuCheckoutPayment\(/);
  assert.match(autoRoute, /paymentGateway: "doku"/);
  assert.doesNotMatch(autoRoute, /midtrans|ipaymu|fallback/i);
});

test("DigiFlazz is the only external fulfillment provider", () => {
  assert.match(providers, /digiflazzAdapter/);
  assert.doesNotMatch(providers, /vippayment|VIPPayment/i);
  assert.match(providerOptions, /code: "digiflazz"/);
  assert.doesNotMatch(providerOptions, /vippayment|VIPPayment/i);
});
