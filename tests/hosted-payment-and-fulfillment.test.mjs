import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.resolve(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("DOKU Checkout and Midtrans Snap are the active hosted payment modes", () => {
  const router = read("lib/server/payment-router.ts");
  assert.match(router, /createDokuCheckoutPayment/);
  assert.match(router, /mode: "checkout" as const/);
  assert.match(router, /createMidtransSnapPayment/);
  assert.match(router, /export type RoutedPaymentMode = "checkout" \| "snap"/);
});

test("DOKU Checkout status polling remains terminal-safe while fulfillment stays server-side", () => {
  const status = read("app/api/orders/status/route.ts");
  const callback = read("app/api/payments/doku/callback/route.ts");
  const doku = read("lib/server/doku-checkout.ts");
  assert.match(status, /artifacts\.mode === "checkout"/);
  assert.match(status, /queryDokuCheckoutStatus/);
  assert.match(status, /dueForGatewayCheck\(order, 60_000\)/);
  assert.match(status, /fulfillAutomaticOrder\(order\.id, getPublicBaseUrl\(\)\)/);
  assert.match(callback, /validateDokuCheckoutNotification/);
  assert.match(callback, /authoritativePaid: status === "paid"/);
  assert.match(callback, /fulfillAutomaticOrder\(order\.id, getPublicBaseUrl\(\)\)/);
  assert.match(doku, /\/checkout\/v1\/payment/);
  assert.match(doku, /\/orders\/v1\/status\//);
});


test("only hosted DOKU Checkout implementation remains in the active source tree", () => {
  assert.equal(fs.existsSync(path.join(root, "lib/server/doku.ts")), false);
  assert.equal(fs.existsSync(path.join(root, "lib/server/doku-status.ts")), false);
  assert.equal(fs.existsSync(path.join(root, "lib/server/doku-payment-transition.ts")), false);
  const config = read("lib/server/payment-mode-config.ts");
  assert.doesNotMatch(config, /PRIVATE_KEY|VA_CONFIG_JSON/);
});

test("DOKU custom payment types cannot cross payment-method families", () => {
  const doku = read("lib/server/doku-checkout.ts");
  assert.match(doku, /const DOKU_CHECKOUT_TYPES_BY_METHOD/);
  assert.match(doku, /canonicalDokuCheckoutPaymentType\(method, custom\)/);
  assert.match(doku, /qris: \["QRIS"\]/);
  assert.match(doku, /value\.toLowerCase\(\) === normalized/);
});

test("DOKU Checkout always persists a usable local expiry", () => {
  const doku = read("lib/server/doku-checkout.ts");
  assert.match(doku, /const paymentDueMinutes = 60/);
  assert.match(doku, /checkoutExpiry\(payload\.response\?\.payment\?\.expired_date\)/);
  assert.match(doku, /new Date\(Date\.now\(\) \+ paymentDueMinutes \* 60_000\)\.toISOString\(\)/);
});

test("customer invoice code is compact while preserving a database uniqueness guard", () => {
  const orders = read("lib/server/orders.ts");
  const status = read("app/api/orders/status/route.ts");
  assert.match(orders, /slice\(0, 14\)/);
  assert.match(orders, /referenceId: `LF\$\{date\}\$\{referenceToken\}`/);
  assert.match(status, /\[A-F0-9\]\{14\}/);
  const schema = read("db/schema.ts");
  assert.match(schema, /orders_reference_id_unique/);
});
