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

test("verified provider paid status can settle after local expiry without reopening arbitrary failures", () => {
  const transition = read("lib/server/payment-transition.ts");
  const wallet = read("lib/server/wallet-external.ts");
  const midtrans = read("lib/server/midtrans-reconciliation.ts");
  assert.match(transition, /authoritativePaid/);
  assert.match(transition, /payment_status IN \('pending', 'expired'\)/);
  assert.match(midtrans, /authoritativePaid: true/);
  assert.match(wallet, /authoritativePaid/);
  assert.match(wallet, /admin_notes IN \('Pembayaran kedaluwarsa\.', 'Pembayaran DOKU kedaluwarsa\.'\)/);
});

test("definitive Midtrans absence expires only after the uncertainty window", () => {
  const snap = read("lib/server/midtrans-snap.ts");
  const midtrans = read("lib/server/midtrans-reconciliation.ts");
  const external = read("lib/server/external-payments.ts");
  const wallet = read("lib/server/wallet-external.ts");
  assert.match(snap, /class MidtransTransactionNotFoundError/);
  assert.match(snap, /response\.status === 404/);
  assert.match(midtrans, /instanceof MidtransTransactionNotFoundError/);
  assert.match(midtrans, /expireConfirmedMissingMidtransOrder/);
  assert.match(midtrans, /expireConfirmedMissingMidtransTopup/);
  assert.match(external, /created_at <= datetime\('now', '-70 minutes'\)/);
  assert.match(wallet, /created_at <= datetime\('now', '-70 minutes'\)/);
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

test("DOKU reconciliation closes local expiry but keeps authoritative late-paid recovery", () => {
  const doku = read("lib/server/doku-reconciliation.ts");
  const transition = read("lib/server/doku-payment-transition.ts");
  assert.match(doku, /payment_status IN \('pending', 'expired'\)/);
  assert.match(doku, /expiredOrders/);
  assert.match(doku, /expiredWalletTopups/);
  assert.match(doku, /applyPendingDokuPaymentStatus\(order, "paid", \{/);
  assert.match(doku, /authoritativePaid: true/);
  assert.match(doku, /applyPendingDokuPaymentStatus\(order, "expired"\)/);
  assert.match(doku, /status = 'rejected' AND admin_notes IN/);
  assert.match(transition, /payment_status IN \('pending', 'expired'\)/);
});

test("product editor blocks save until every concurrent media upload completes", () => {
  const manager = read("components/admin-product-manager.tsx");
  assert.match(manager, /Record<"imageUrl" \| "bannerUrl", boolean>/);
  assert.match(manager, /mediaUploadActive\.current\[field\] = true/);
  assert.match(manager, /mediaUploadActive\.current\[field\] = false/);
  assert.match(manager, /mediaUploadActive\.current\.imageUrl \|\| mediaUploadActive\.current\.bannerUrl/);
  assert.match(manager, /uploading\.imageUrl/);
  assert.match(manager, /uploading\.bannerUrl/);
});

test("checkout normalizes legacy provider code and SKU exactly like the public catalog", () => {
  const orders = read("lib/server/orders.ts");
  const products = read("lib/server/products.ts");
  assert.match(orders, /providerCode: row\.provider_code\?\.trim\(\)\.toLowerCase\(\) \|\| null/);
  assert.match(orders, /providerSku: row\.provider_sku\?\.trim\(\) \|\| null/);
  assert.match(products, /providerCode: item\.provider_code\?\.trim\(\)\.toLowerCase\(\) \|\| undefined/);
  assert.match(products, /providerSku: item\.provider_sku\?\.trim\(\) \|\| undefined/);
});

test("historical provider casing is normalized through fulfillment and reconciliation", () => {
  const orders = read("lib/server/orders.ts");
  const digiflazz = read("lib/server/digiflazz-reconciliation.ts");
  const products = read("lib/server/products.ts");
  assert.match(orders, /const providerCode = order\.provider_code\?\.trim\(\)\.toLowerCase\(\) \|\| null/);
  assert.match(orders, /const providerSku = order\.provider_sku\?\.trim\(\) \|\| null/);
  assert.match(digiflazz, /lower\(trim\(provider_code\)\) = 'digiflazz'/);
  assert.match(products, /const normalizedProviderCode = item\.providerCode\?\.trim\(\)\.toLowerCase\(\) \|\| null/);
  assert.match(products, /const normalizedProviderSku = item\.providerSku\?\.trim\(\) \|\| null/);
});

test("DOKU expired-order polling updates the canonical throttle timestamp", () => {
  const doku = read("lib/server/doku-reconciliation.ts");
  assert.match(doku, /payment_status IN \('pending', 'expired'\)/);
  assert.match(doku, /gateway_status_checked_at = CURRENT_TIMESTAMP/);
});

test("DOKU overview only reports ready for a parseable RSA key and HTTPS endpoint", () => {
  const config = read("lib/server/payment-mode-config.ts");
  assert.match(config, /createPrivateKey/);
  assert.match(config, /apiUrl\.protocol !== "https:"/);
  assert.match(config, /privateKeyPassphrase/);
});
