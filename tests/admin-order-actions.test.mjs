import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("admin order detail uses real persisted events and status-aware actions", () => {
  const ui = read("components/admin-order-manager.tsx");

  assert.match(ui, /\/api\/panel\/orders\?id=/);
  assert.match(ui, /events\?: ApiOrderEvent\[\]/);
  assert.match(ui, /eventLabel\(event\.status\)/);
  assert.match(ui, /Cek Status Pembayaran/);
  assert.match(ui, /Cek Ulang DigiFlazz/);
  assert.match(ui, /Status Provider/);
  assert.match(ui, /providerSerialNumber/);
  assert.match(ui, /paymentStatus === "pending"/);

  const copyBlock = ui.match(/async function copyInvoice\(\)[\s\S]*?\n  }/)?.[0] || "";
  assert.doesNotMatch(copyBlock, /onClose\(\)/);
});

test("admin fulfillment refresh never marks payment paid and reconciles DigiFlazz with the same reference", () => {
  const route = read("app/api/admin/orders/route.ts");
  const reconciliation = read("lib/server/digiflazz-reconciliation.ts");

  assert.match(route, /action: z\.literal\("refresh_fulfillment"\)/);
  assert.match(route, /order\.payment_status !== "paid"/);
  assert.match(route, /reconcileDigiflazzOrder\(order\.id, getPublicBaseUrl\(\), \{ force: true \}\)/);
  assert.doesNotMatch(route, /SET payment_status = 'paid'/);

  assert.match(reconciliation, /referenceId: order\.reference_id/);
  assert.match(reconciliation, /provider_status = \?/);
  assert.match(reconciliation, /provider_status IN \('processing', 'retryable_error', 'retry_exhausted', 'error', 'unknown'\)/);
  assert.match(reconciliation, /provider_status = 'dispatching' AND updated_at <= datetime\('now', '-2 minutes'\)/);
  assert.match(reconciliation, /fulfillment_status NOT IN \('success', 'failed', 'cancelled'\)/);
});
