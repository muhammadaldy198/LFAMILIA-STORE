import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.resolve(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("DOKU Direct API and Midtrans Snap are the only active routable modes", () => {
  const router = read("lib/server/payment-router.ts");
  assert.match(router, /createDokuDirectPayment/);
  assert.match(router, /mode: "direct" as const/);
  assert.match(router, /createMidtransSnapPayment/);
  assert.match(router, /export type RoutedPaymentMode = "direct" \| "snap"/);
  assert.doesNotMatch(router, /createDokuCheckoutPayment|mode: "checkout"|createMidtransVirtualAccount|mode: "bisnap"/);
});

test("DOKU Direct status polling remains terminal-safe while fulfillment stays server-side", () => {
  const status = read("app/api/orders/status/route.ts");
  const callback = read("app/api/payments/midtrans/snap/notification/route.ts");
  assert.match(status, /artifacts\.mode === "direct"/);
  assert.doesNotMatch(status, /queryDokuCheckoutStatus|artifacts\.mode === "checkout"/);
  assert.match(status, /queryDokuQrisStatus/);
  assert.match(status, /queryDokuVaStatus/);
  assert.match(status, /queryDokuEwalletStatus/);
  assert.match(status, /dueForGatewayCheck\(order, 3_000\)/);
  assert.match(status, /fulfillAutomaticOrder\(order\.id, getPublicBaseUrl\(\)\)/);
  assert.match(callback, /applyPendingExternalPaymentStatus\(order, status\)/);
  assert.match(callback, /fulfillAutomaticOrder\(order\.id, getPublicBaseUrl\(\)\)/);
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
