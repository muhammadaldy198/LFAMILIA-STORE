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
  assert.doesNotMatch(router, /createDokuDirectPayment|mode: "direct"/);
});

test("DOKU Checkout status polling remains terminal-safe while fulfillment stays server-side", () => {
  const status = read("app/api/orders/status/route.ts");
  const callback = read("app/api/payments/doku/callback/route.ts");
  const doku = read("lib/server/doku-checkout.ts");
  assert.match(status, /artifacts\.mode === "checkout"/);
  assert.match(status, /queryDokuCheckoutStatus/);
  assert.doesNotMatch(status, /queryDokuQrisStatus|queryDokuVaStatus|queryDokuEwalletStatus/);
  assert.match(status, /dueForGatewayCheck\(order, 3_000\)/);
  assert.match(status, /fulfillAutomaticOrder\(order\.id, getPublicBaseUrl\(\)\)/);
  assert.match(callback, /validateDokuCheckoutNotification/);
  assert.match(callback, /authoritativePaid: notification\.status === "paid"/);
  assert.match(callback, /fulfillAutomaticOrder\(order\.id, getPublicBaseUrl\(\)\)/);
  assert.match(doku, /\/checkout\/v1\/payment/);
  assert.match(doku, /\/orders\/v1\/status\//);
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
