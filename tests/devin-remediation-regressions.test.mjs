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

test("ambiguous DOKU Checkout dispatches reconcile by merchant reference while Direct still requires artifacts", () => {
  const doku = read("lib/server/doku-reconciliation.ts");
  assert.match(doku, /payment_gateway_mode = 'checkout'/);
  assert.match(doku, /payment_gateway_mode = 'direct'[\s\S]*gateway_request_id IS NOT NULL OR doku_request_id IS NOT NULL/);
  assert.match(doku, /queryDokuCheckoutStatus\(\{[\s\S]*referenceId: order\.reference_id/);
  assert.match(doku, /queryDokuCheckoutStatus\(\{[\s\S]*referenceId: topup\.reference_id/);
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

test("active promo reservations cannot race admin edits", () => {
  const promotions = read("lib/server/promotions.ts");
  assert.match(promotions, /AND \(code = \? OR reserved_count = 0\)/);
  assert.match(promotions, /AND \(\? IS NULL OR \? >= used_count \+ reserved_count\)/);
  assert.match(promotions, /AND \(\(product_slug = \? AND package_sku = \?\) OR reserved_count = 0\)/);
  assert.match(promotions, /AND \(\? IS NULL OR \? >= sold_count \+ reserved_count\)/);
  assert.match(promotions, /DELETE FROM \$\{table\} WHERE id = \? AND reserved_count = 0/);
});

test("DOKU reconciliation closes local expiry but keeps authoritative late-paid recovery", () => {
  const doku = read("lib/server/doku-reconciliation.ts");
  const transition = read("lib/server/doku-payment-transition.ts");
  assert.match(doku, /payment_status = 'expired'/);
  assert.match(doku, /datetime\('now', '-24 hours'\)/);
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

test("stale DigiFlazz reconciliation normalizes legacy provider casing and notifies once", () => {
  const source = read("lib/server/digiflazz-reconciliation.ts");
  assert.match(source, /lower\(trim\(provider_code\)\) = 'digiflazz'/);
  assert.match(source, /const batch = await db\.batch/);
  assert.match(source, /const persisted = Number\(batch\[0\]\?\.meta\.changes \?\? 0\) > 0/);
  assert.match(source, /if \(persisted && result\.status === "success"\)/);
});

test("wallet topup only uses uncertainty hold after gateway dispatch begins", () => {
  const route = read("app/api/account/topups/route.ts");
  const wallet = read("lib/server/wallet-external.ts");
  assert.match(route, /let paymentDispatchStarted = false/);
  assert.match(route, /paymentDispatchStarted = true;[\s\S]*createConfiguredPayment/);
  assert.match(route, /if \(paymentDispatchStarted\)/);
  assert.match(route, /rejectExternalWalletTopupPreDispatch/);
  assert.match(wallet, /export async function rejectExternalWalletTopupPreDispatch/);
  assert.match(wallet, /SET status = 'rejected'/);
});

test("expired DOKU Checkout recovery is bounded while retryable FAILED stays non-terminal", () => {
  const doku = read("lib/server/doku-reconciliation.ts");
  const checkout = read("lib/server/doku-checkout.ts");
  assert.match(doku, /datetime\('now', '-24 hours'\)/);
  assert.match(checkout, /FAILED must not finalize the merchant order/);
  assert.match(checkout, /return "pending"/);
  assert.doesNotMatch(doku, /Pembayaran DOKU gagal terkonfirmasi\./);
});

test("legacy provider casing remains retryable across automatic fulfillment recovery", () => {
  const orders = read("lib/server/orders.ts");
  const guard = read("lib/server/digiflazz-config-guard.ts");
  assert.match(orders, /if \(providerCode === "digiflazz" \|\| providerCode === "voucher-stock"\)/);
  assert.match(orders, /lower\(trim\(provider_code\)\) IN \('digiflazz', 'voucher-stock'\)/);
  assert.match(orders, /lower\(trim\(coalesce\(provider_code, ''\)\)\) NOT IN \('digiflazz', 'voucher-stock'\)/);
  assert.match(guard, /lower\(trim\(provider_code\)\) = 'digiflazz'/);
});

test("active DigiFlazz credential changes mutate under guard, then atomically invalidate cache with rollback support", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const guard = read("lib/server/digiflazz-config-guard.ts");
  const action = route.indexOf("await action(token)");
  const invalidate = route.indexOf("await invalidateDigiflazzOperationalCache(token)");
  assert.ok(action >= 0 && invalidate > action);
  assert.match(route, /if \(actionCommitted && rollbackPlan && committed !== null\)/);
  assert.match(route, /rollbackPlan\.rollback\(token, committed\)/);
  assert.match(route, /Rollback dibatalkan karena guard kedaluwarsa atau konfigurasi DigiFlazz sudah berubah/);
  assert.match(guard, /const results = await db\.batch\(statements\)/);
});

test("DigiFlazz profile saves serialize environment selection before deciding cache invalidation", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const profileBranch = route.indexOf('input.provider === "digiflazz"');
  const guardedSave = route.indexOf("await withDigiflazzConfigurationGuard(", profileBranch);
  const activeRead = route.indexOf("const activeEnvironment = (await getIntegrationOverview())", guardedSave);
  assert.ok(profileBranch >= 0 && guardedSave > profileBranch && activeRead > guardedSave);
  assert.match(route, /shouldInvalidateOperationalCache/);
  assert.match(route, /if \(invalidateOperationalCache\)/);
});

test("guarded DigiFlazz profile writes fail closed when their lease is no longer owned", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const integration = read("lib/server/integration-config.ts");
  assert.match(route, /saveIntegrationProfile\(input, guardToken\)/);
  assert.match(integration, /const ownership = guardToken \? `WHERE \$\{guardedOwnershipClause\(\)\}` : ""/);
  assert.match(integration, /if \(guardToken\) args\.push\(guardToken, guardToken\)/);
});

test("guarded DigiFlazz environment writes fail closed when their lease is no longer owned", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const integration = read("lib/server/integration-config.ts");
  assert.match(route, /saveIntegrationSelections\(input\.selections, guardToken\)/);
  assert.match(integration, /INSERT INTO integration_settings[\s\S]*SELECT 'digiflazz_environment'/);
});

test("storefront keeps fallback categories when API response fails or omits categories", () => {
  const storefront = read("hooks/use-storefront.ts");
  assert.match(storefront, /if \(!response\.ok\) throw new Error/);
  assert.match(storefront, /if \(Array\.isArray\(data\.categories\)\)/);
  assert.doesNotMatch(storefront, /canonicalCategories\(data\.categories \?\? \[\], true\)/);
});

test("Checkout and legacy Direct DOKU credentials use separate runtime namespaces", () => {
  const config = read("lib/server/payment-mode-config.ts");
  const checkout = read("lib/server/doku-checkout.ts");
  const direct = read("lib/server/doku.ts");
  assert.match(config, /DOKU_CHECKOUT_\$\{environmentName\}_/);
  assert.match(config, /const directPrefix = `DOKU_\$\{environmentName\}_`/);
  assert.match(checkout, /DOKU_CHECKOUT_SANDBOX_CLIENT_ID/);
  assert.match(checkout, /DOKU_CHECKOUT_PRODUCTION_SECRET_KEY/);
  assert.match(direct, /DOKU_SANDBOX_CLIENT_ID/);
  assert.doesNotMatch(direct, /DOKU_CHECKOUT_SANDBOX_CLIENT_ID/);
});

test("DOKU Checkout overview only reports ready with Client ID, Secret Key, and HTTPS endpoint", () => {
  const config = read("lib/server/payment-mode-config.ts");
  assert.match(config, /values\?\.clientId/);
  assert.match(config, /values\.secretKey/);
  assert.match(config, /new URL\(values\.apiUrl\)\.protocol === "https:"/);
  assert.doesNotMatch(config, /createPrivateKey/);
});
