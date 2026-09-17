import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("DOKU Direct reconciliation uses documented VA and e-wallet check status APIs", () => {
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

test("DOKU Checkout reconciliation uses official Non-SNAP Check Status by invoice", () => {
  const checkout = read("lib/server/doku-checkout-status.ts");
  assert.match(checkout, /\/orders\/v1\/status\/\$\{encodeURIComponent\(input\.referenceId\)\}/);
  assert.match(checkout, /method: "GET"/);
  assert.match(checkout, /Request-Target:/);
  assert.doesNotMatch(checkout, /Digest:/);
  assert.match(checkout, /transactionStatus === "SUCCESS"/);
  assert.match(checkout, /ORDER_EXPIRED/);
  assert.match(checkout, /FAILED\/TIMEOUT\/REDIRECT are not final/);
});

test("scheduled reconciliation keeps DOKU Checkout and Direct mode-aware, throttled, amount-safe, and terminal-safe", () => {
  const reconciliation = read("lib/server/doku-reconciliation.ts");
  const worker = read("worker/index.ts");

  assert.match(reconciliation, /payment_gateway_mode === "checkout"/);
  assert.match(reconciliation, /payment_gateway_mode !== "direct"/);
  assert.match(reconciliation, /COALESCE\(payment_gateway_environment, doku_environment\) IN \('sandbox', 'production'\)/);
  assert.match(reconciliation, /created_at <= datetime\('now', '-60 seconds'\)/);
  assert.match(reconciliation, /COALESCE\(gateway_status_checked_at, doku_status_checked_at\)/);
  assert.match(reconciliation, /query\.amount === order\.total/);
  assert.match(reconciliation, /applyDokuWalletTopup\(/);
  assert.match(reconciliation, /applyPendingDokuPaymentStatus\(order, "expired"\)/);
  assert.match(worker, /finalizeExpiredDokuPayments\(\)/);
});

test("DOKU public polling reconciles Checkout and Direct before local expiry", () => {
  const publicStatus = read("app/api/orders/status/route.ts");
  assert.match(publicStatus, /queryDokuCheckoutStatus/);
  assert.match(publicStatus, /queryDokuQrisStatus/);
  assert.match(publicStatus, /queryDokuVaStatus/);
  assert.match(publicStatus, /queryDokuEwalletStatus/);
  assert.match(publicStatus, /order = await refreshDokuStatus\(order\)/);
  assert.match(publicStatus, /order = await expirePendingInvoice\(order\)/);
  assert.ok(
    publicStatus.indexOf("refreshDokuStatus(order)") < publicStatus.indexOf("expirePendingInvoice(order)"),
    "provider reconciliation must happen before local expiry",
  );
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
  assert.match(reconciliation, /applyPendingDokuPaymentStatus\(order, query\.status\)/);
});
