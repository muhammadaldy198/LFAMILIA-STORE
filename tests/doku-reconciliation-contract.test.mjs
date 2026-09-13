import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("DOKU reconciliation uses documented VA and e-wallet check status APIs", () => {
  const status = read("lib/server/doku-status.ts");
  assert.match(status, /\/orders\/v1\.0\/transfer-va\/status/);
  assert.match(status, /\/orders\/v1\.0\/debit\/status/);
  assert.match(status, /serviceCode: "55"/);
  assert.match(status, /originalPartnerReferenceNo/);
  assert.match(status, /originalExternalId/);
  assert.match(status, /partnerServiceId/);
  assert.match(status, /virtualAccountNo/);
  assert.doesNotMatch(status, /\/query-va|\/query-ewallet|\/payment\/status\/lookup/);
});

test("scheduled reconciliation uses stored environment, throttles checks, validates amount, and expires pending records", () => {
  const reconciliation = read("lib/server/doku-reconciliation.ts");
  const worker = read("worker/index.ts");

  assert.match(reconciliation, /doku_environment IN \('sandbox', 'production'\)/);
  assert.match(reconciliation, /created_at <= datetime\('now', '-60 seconds'\)/);
  assert.match(reconciliation, /doku_status_checked_at <= datetime\('now', '-60 seconds'\)/);
  assert.match(reconciliation, /query\.amount === order\.total/);
  assert.match(reconciliation, /applyDokuWalletTopup\(/);
  assert.match(reconciliation, /applyPendingDokuPaymentStatus\(order, "expired"\)/);
  assert.match(worker, /finalizeExpiredDokuPayments\(\)/);
});

test("DOKU order status transitions are atomic and terminal-safe across callback, polling, and expiry", () => {
  const transition = read("lib/server/doku-payment-transition.ts");
  const callback = read("app/api/payments/doku/callback/route.ts");
  const publicStatus = read("app/api/orders/status/route.ts");
  const reconciliation = read("lib/server/doku-reconciliation.ts");

  assert.match(transition, /WHERE id = \? AND payment_status = 'pending'/);
  assert.doesNotMatch(transition, /payment_status <> 'paid'/);
  assert.match(callback, /applyPendingDokuPaymentStatus\(order, status\)/);
  assert.match(publicStatus, /applyPendingDokuPaymentStatus\(order, "paid"\)/);
  assert.match(publicStatus, /applyPendingDokuPaymentStatus\(order, "expired"\)/);
  assert.match(reconciliation, /applyPendingDokuPaymentStatus\(order, "paid"\)/);
  assert.match(reconciliation, /applyPendingDokuPaymentStatus\(order, "failed"\)/);
});
