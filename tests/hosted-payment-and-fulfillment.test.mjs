import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.resolve(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("only hosted DOKU Checkout and Midtrans Snap are routable", () => {
  const router = read("lib/server/payment-router.ts");
  assert.match(router, /createDokuCheckoutPayment/);
  assert.match(router, /createMidtransSnapPayment/);
  assert.doesNotMatch(router, /Direct|bisnap|createMidtransVirtualAccount/);
});

test("hosted status polling is fast and paid fulfillment remains server-side", () => {
  const status = read("app/api/orders/status/route.ts");
  const callback = read("app/api/payments/midtrans/snap/notification/route.ts");
  assert.match(status, /artifacts\.mode === "checkout" && dueForGatewayCheck\(order, 3_000\)/);
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
