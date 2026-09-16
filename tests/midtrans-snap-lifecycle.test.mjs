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

test("Midtrans Snap status mapping only treats settled or accepted capture as paid", () => {
  const source = read("lib/server/midtrans-snap.ts");
  assert.match(source, /status === "settlement"\) return "paid"/);
  assert.match(source, /status === "capture"\) return fraud === "deny" \? "failed"[^\n]+: "paid"/);
  assert.match(source, /status === "pending" \|\| status === "authorize"/);
  assert.match(source, /status === "expire"\) return "expired"/);
  assert.match(source, /status === "cancel" \|\| status === "deny" \|\| status === "failure"/);
});
