import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("late Midtrans callbacks cannot reopen or fulfil terminal invoices", () => {
  const source = read("lib/server/orders.ts");
  const start = source.indexOf("export async function applyPaymentStatus");
  const end = source.indexOf("export async function", start + 10);
  const applyPaymentStatus = source.slice(start, end > start ? end : undefined);

  assert.match(applyPaymentStatus, /if \(status === "pending"\) return false/);
  assert.match(applyPaymentStatus, /payment_status = 'paid'[\s\S]*payment_status = 'pending'/);
  assert.match(applyPaymentStatus, /UPDATE orders SET payment_status = \?[\s\S]*payment_status = 'pending'/);
  assert.doesNotMatch(applyPaymentStatus, /payment_status <> 'paid'/);
});

test("Midtrans Snap status mapping treats fraud challenge as pending until accepted", () => {
  const source = read("lib/server/midtrans-status.mjs");
  assert.match(source, /status === "settlement" \|\| status === "capture"/);
  assert.match(source, /fraud === "deny"\) return "failed"/);
  assert.match(source, /fraud && fraud !== "accept"\) return "pending"/);
  assert.match(source, /return "paid"/);
  assert.match(source, /status === "pending" \|\| status === "authorize"/);
  assert.match(source, /status === "expire"\) return "expired"/);
  assert.match(source, /status === "cancel" \|\| status === "deny" \|\| status === "failure"/);
});

test("pending Midtrans Snap orders reconcile against authenticated Get Status API", () => {
  const snap = read("lib/server/midtrans-snap.ts");
  const statusRoute = read("app/api/orders/status/route.ts");

  assert.match(snap, /export async function queryMidtransSnapStatus/);
  assert.match(snap, /\/v2\/\$\{encodeURIComponent\(input\.orderId\)\}\/status/);
  assert.match(snap, /authorization: `Basic \$\{Buffer\.from\(`\$\{serverKey\}:`\)\.toString\("base64"\)\}`/);
  assert.match(statusRoute, /artifacts\.gateway === "midtrans"/);
  assert.match(statusRoute, /artifacts\.mode === "snap"/);
  assert.match(statusRoute, /gateway_status_checked_at = CURRENT_TIMESTAMP/);
  assert.match(statusRoute, /query\.amount !== order\.total/);
  assert.match(statusRoute, /const firstPaid = await applyPendingExternalPaymentStatus\(order, "paid", \{/);
  assert.match(statusRoute, /authoritativePaid: true/);
  assert.match(statusRoute, /await fulfillAutomaticOrder\(order\.id, getPublicBaseUrl\(\)\)/);
  assert.match(statusRoute, /order = await refreshMidtransSnapStatus\(order\)/);
});
