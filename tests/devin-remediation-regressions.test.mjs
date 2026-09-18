import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("uncertain external payment dispatch stays pending and recoverable", () => {
  const route = read("app/api/payments/auto/create/route.ts");
  const orders = read("lib/server/orders.ts");
  const recovery = read("lib/server/external-payments.ts");
  const midtrans = read("lib/server/midtrans-reconciliation.ts");
  const worker = read("worker/index.ts");
  assert.match(route, /paymentGateway: managedChannel\.gateway/);
  assert.match(route, /paymentGatewayMode: readiness\.mode/);
  assert.match(route, /paymentGatewayEnvironment: readiness\.environment/);
  assert.match(orders, /payment_gateway, payment_gateway_mode, payment_gateway_environment, doku_environment/);
  assert.match(route, /75 \* 60_000/);
  assert.match(route, /paymentDispatchStarted = true/);
  assert.match(route, /if \(!paymentDispatchStarted\)/);
  assert.match(route, /Jangan bayar dua kali/);
  assert.match(midtrans, /reconcilePendingMidtransOrders/);
  assert.match(midtrans, /queryMidtransSnapStatus/);
  assert.match(midtrans, /orderId: order\.reference_id/);
  assert.match(recovery, /expireUninitializedExternalOrders/);
  assert.match(recovery, /created_at <= datetime\('now', '-70 minutes'\)/);
  assert.match(worker, /reconcilePendingMidtransOrders\(\)/);
  assert.match(worker, /expireUninitializedExternalOrders\(\)/);
});

test("uncertain DOKU dispatches stay out of normal status polling until initialized", () => {
  const doku = read("lib/server/doku-reconciliation.ts");
  assert.match(doku, /gateway_request_id IS NOT NULL OR doku_request_id IS NOT NULL/);
});

test("Midtrans scheduler queries provider status even after local expiry", () => {
  const midtrans = read("lib/server/midtrans-reconciliation.ts");
  const external = read("lib/server/external-payments.ts");
  const walletExternal = read("lib/server/wallet-external.ts");
  assert.match(midtrans, /reconcilePendingMidtransOrders/);
  assert.match(midtrans, /queryMidtransSnapStatus/);
  assert.doesNotMatch(midtrans, /reason: "stored_midtrans_expiry"/);
  assert.match(external, /payment_gateway IS NULL OR payment_gateway <> 'midtrans'/);
  assert.match(walletExternal, /payment_gateway IS NULL OR payment_gateway <> 'midtrans'/);
});

test("payment maintenance reconciles before ambiguous expiry and keeps pending promo reservations", () => {
  const worker = read("worker/index.ts");
  const promotions = read("lib/server/promotions.ts");
  assert.match(worker, /const paymentRecovery = Promise\.all\(/);
  assert.match(worker, /reconcilePendingMidtransOrders\(\)/);
  assert.match(worker, /reconcilePendingMidtransTopups\(\)/);
  assert.match(worker, /finalizeExpiredDokuPayments\(\)/);
  assert.match(worker, /\.then\(async \(\) => \{/);
  assert.match(worker, /expireUninitializedExternalOrders\(\)/);
  assert.match(worker, /releaseExpiredExternalPromotions\(\)/);
  assert.match(promotions, /orders\.payment_status = 'paid'/);
  assert.match(promotions, /SET status = 'consumed'/);
  assert.match(promotions, /orders\.payment_status IN \('pending', 'paid'\)/);
});

test("active promo reservations cannot be orphaned by admin edits", () => {
  const promotions = read("lib/server/promotions.ts");
  assert.match(promotions, /Kode voucher tidak dapat diubah saat masih memiliki reservasi/);
  assert.match(promotions, /Batas penggunaan tidak boleh lebih kecil dari penggunaan \+ reservasi aktif/);
  assert.match(promotions, /Produk\/nominal flash sale tidak dapat diganti saat masih memiliki reservasi/);
  assert.match(promotions, /Promo tidak dapat dihapus saat masih memiliki reservasi/);
});

test("DOKU overview only reports ready for a parseable RSA key and HTTPS endpoint", () => {
  const config = read("lib/server/payment-mode-config.ts");
  assert.match(config, /createPrivateKey/);
  assert.match(config, /apiUrl\.protocol !== "https:"/);
  assert.match(config, /privateKeyPassphrase/);
});
