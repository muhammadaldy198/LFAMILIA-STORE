import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("pricelist refresh is protected by D1 lock and cooldown", () => {
  const pricing = read("lib/server/digiflazz-pricing.ts");
  const migration = read("drizzle/0035_digiflazz_sync_guard.sql");
  assert.match(pricing, /digiflazz_pricelist_sync_state/);
  assert.match(pricing, /lock_token/);
  assert.match(pricing, /locked_until/);
  assert.match(pricing, /DIGIFLAZZ_SYNC_COOLDOWN_SECONDS = 60/);
  assert.match(pricing, /sync_in_progress/);
  assert.match(pricing, /cooldown/);
  assert.match(migration, /digiflazz_pricelist_sync_state/);
});

test("valid signed DigiFlazz webhook ping is acknowledged before ref_id validation", () => {
  const callback = read("app/api/fulfillment/digiflazz/callback/route.ts");
  const signature = callback.indexOf("x-hub-signature");
  const ping = callback.indexOf("isPing");
  const ref = callback.indexOf("!data?.ref_id");
  assert.ok(signature >= 0 && ping > signature && ref > ping);
  assert.match(callback, /x-digiflazz-event/);
  assert.match(callback, /event: "ping"/);
});

test("Digiflazz relay only forwards the three endpoints LFAMILIA needs", () => {
  const relay = read("relay/server.mjs");
  assert.match(relay, /const digiflazzAllowedPaths = new Set/);
  for (const endpoint of ["/v1/transaction", "/v1/price-list", "/v1/cek-saldo"]) {
    assert.match(relay, new RegExp(endpoint.replaceAll("/", "\\/")));
  }
  assert.match(relay, /provider\.name === "digiflazz"\) return digiflazzAllowedPaths\.has\(path\)/);
});

test("saving a product refreshes DigiFlazz seller snapshots from cached pricelist only", () => {
  const route = read("app/api/admin/products/route.ts");
  assert.match(route, /syncDigiflazzProduct/);
  assert.match(route, /refreshSavedDigiflazzSnapshots/);
  assert.match(route, /digiflazzSyncWarning/);
  assert.doesNotMatch(route, /syncDigiflazzPrices/);
});

test("active DigiFlazz configuration changes are serialized against sync and checkout", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const guard = read("lib/server/digiflazz-config-guard.ts");
  const migration = read("drizzle/0035_digiflazz_sync_guard.sql");

  assert.match(route, /withDigiflazzConfigurationGuard/);
  assert.match(route, /acquireDigiflazzConfigurationGuard/);
  assert.match(route, /invalidateDigiflazzOperationalCache/);
  assert.match(route, /releaseDigiflazzConfigurationGuard/);
  assert.match(guard, /digiflazz_runtime_state/);
  assert.match(guard, /digiflazz_pricelist_sync_state/);
  assert.match(guard, /payment_status = 'pending'/);
  assert.match(guard, /fulfillment_status NOT IN \('success', 'failed', 'cancelled'\)/);
  assert.match(guard, /DIGIFLAZZ_CONFIG_MAINTENANCE/);
  assert.match(migration, /CREATE TRIGGER IF NOT EXISTS `digiflazz_order_maintenance_guard`/);
  assert.doesNotMatch(route, /lock_token = NULL, locked_until = NULL, last_success_at = NULL/);
});

test("forward migration replaces the legacy case-sensitive DigiFlazz maintenance trigger", () => {
  const migration = read("drizzle/0038_digiflazz_normalize_maintenance_guard.sql");
  assert.match(migration, /DROP TRIGGER IF EXISTS `digiflazz_order_maintenance_guard`/);
  assert.match(migration, /CREATE TRIGGER `digiflazz_order_maintenance_guard`/);
  assert.match(migration, /lower\(trim\(NEW\.`provider_code`\)\) = 'digiflazz'/);
});

test("failed active DigiFlazz configuration changes preserve the previous cache and rollback on atomic invalidation failure", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const guard = read("lib/server/digiflazz-config-guard.ts");
  const integration = read("lib/server/integration-config.ts");
  const action = route.indexOf("await action(token)");
  const invalidate = route.indexOf("await invalidateDigiflazzOperationalCache(token)");

  assert.ok(action >= 0 && invalidate > action);
  assert.match(route, /captureIntegrationProfileSnapshot/);
  assert.match(route, /restoreIntegrationProfileSnapshot/);
  assert.match(route, /captureIntegrationSettingSnapshot/);
  assert.match(route, /restoreIntegrationSettingSnapshot/);
  assert.match(route, /if \(actionCommitted && rollbackPlan && committed !== null\)/);
  assert.match(route, /rollbackPlan\.rollback\(token, committed\)/);
  assert.doesNotMatch(route, /syncDigiflazzPrices/);
  assert.match(guard, /const results = await db\.batch\(statements\)/);
  assert.match(guard, /Guard konfigurasi DigiFlazz kedaluwarsa sebelum cache dapat diinvalidasi/);
  assert.match(integration, /export async function captureIntegrationProfileSnapshot/);
  assert.match(integration, /export async function restoreIntegrationProfileSnapshot/);
  assert.match(integration, /export async function captureIntegrationSettingSnapshot/);
  assert.match(integration, /export async function restoreIntegrationSettingSnapshot/);
  assert.match(integration, /createdAt: string \| null/);
  assert.match(integration, /updatedAt: string \| null/);
  assert.match(integration, /RETURNING encrypted_config, created_at, updated_at/);
  assert.match(integration, /AND updated_at = \?/);
  assert.match(integration, /maintenance_token = \?/);
  assert.match(integration, /lock_token = \?/);
  assert.doesNotMatch(route, /captureCommitted/);
  assert.match(route, /committed = await action\(token\)/);
  assert.match(route, /Rollback dibatalkan karena guard kedaluwarsa atau konfigurasi DigiFlazz sudah berubah/);
});

test("targeted DigiFlazz product and package syncs cannot repopulate stale seller availability", () => {
  const pricing = read("lib/server/digiflazz-pricing.ts");
  const monitor = read("lib/server/digiflazz-monitor.ts");
  assert.match(pricing, /acquireTargetedPriceListSyncLock/);
  assert.match(pricing, /withTargetedPriceListSyncLock/);
  assert.match(pricing, /syncDigiflazzProduct[\s\S]*withTargetedPriceListSyncLock/);
  assert.match(pricing, /syncDigiflazzPackage[\s\S]*withTargetedPriceListSyncLock/);
  assert.match(pricing, /Lock sync DigiFlazz kedaluwarsa sebelum snapshot seller dapat disimpan/);
  assert.match(monitor, /syncLockToken/);
  assert.match(monitor, /digiflazz_pricelist_sync_state/);
  assert.match(monitor, /digiflazz_runtime_state/);
});

test("full DigiFlazz pricelist writes renew and verify the sync lease before every cache batch", () => {
  const pricing = read("lib/server/digiflazz-pricing.ts");
  assert.match(pricing, /async function renewPriceListSyncLock/);
  assert.match(pricing, /Lock sync DigiFlazz kedaluwarsa sebelum cache pricelist selesai ditulis/);
  assert.match(pricing, /await renewPriceListSyncLock\(syncLockToken\)/);
  assert.match(pricing, /writePriceListCache\(items, lock\.token\)/);
});

test("DigiFlazz profile activity is decided only after acquiring the configuration guard", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const helperStart = route.indexOf("async function withDigiflazzConfigurationGuard");
  const acquire = route.indexOf("await acquireDigiflazzConfigurationGuard()", helperStart);
  const decision = route.indexOf("await shouldInvalidateOperationalCache()", helperStart);
  const profileBranch = route.indexOf('input.provider === "digiflazz"');
  const guardedSave = route.indexOf("await withDigiflazzConfigurationGuard(", profileBranch);
  const activeRead = route.indexOf("const activeEnvironment = (await getIntegrationOverview())", guardedSave);

  assert.ok(acquire >= 0 && decision > acquire);
  assert.ok(guardedSave >= 0 && activeRead > guardedSave);
  assert.match(route, /return hasCredentialMutation && input\.environment === activeEnvironment/);
  assert.doesNotMatch(
    route.slice(profileBranch, guardedSave),
    /const activeEnvironment = \(await getIntegrationOverview\(\)\)/,
  );
});

test("inactive DigiFlazz profile saves cannot commit after configuration lease expiry", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const integration = read("lib/server/integration-config.ts");
  assert.match(route, /action: \(guardToken: string\) => Promise<T>/);
  assert.match(route, /committed = await action\(token\)/);
  assert.match(route, /saveIntegrationProfile\(input, guardToken\)/);
  assert.match(integration, /guardToken\?: string/);
  assert.match(integration, /SELECT \?, \?, \?, \?, strftime/);
  assert.match(integration, /guardToken \? `WHERE \$\{guardedOwnershipClause\(\)\}` : ""/);
  assert.match(integration, /Guard konfigurasi DigiFlazz kedaluwarsa sebelum profile dapat disimpan/);
});

test("DigiFlazz environment selection cannot commit after configuration lease expiry", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const integration = read("lib/server/integration-config.ts");
  assert.match(route, /saveIntegrationSelections\(input\.selections, guardToken\)/);
  assert.match(integration, /Guard konfigurasi DigiFlazz kedaluwarsa sebelum environment dapat disimpan/);
  assert.match(integration, /SELECT 'digiflazz_environment', \?, strftime/);
});

test("same-value DigiFlazz environment saves cannot report success during guarded maintenance", () => {
  const route = read("app/api/admin/integrations/route.ts");
  const guard = read("lib/server/digiflazz-config-guard.ts");
  assert.match(route, /await assertDigiflazzConfigurationIdle\(\)/);
  assert.match(guard, /export async function assertDigiflazzConfigurationIdle/);
});

test("DigiFlazz transaction response is JSON-safe and requires exact LFAMILIA correlation", () => {
  const provider = read("lib/server/providers/digiflazz.ts");
  assert.match(provider, /response\.json\(\)\.catch\(\(\) => null\)/);
  assert.match(provider, /data\.ref_id !== order\.referenceId/);
  assert.match(provider, /data\.buyer_sku_code !== order\.providerSku/);
  assert.match(provider, /externalId: data\.ref_id/);
  assert.doesNotMatch(provider, /data\.ref_id && data\.ref_id !== order\.referenceId/);
  assert.doesNotMatch(provider, /data\.buyer_sku_code && data\.buyer_sku_code !== order\.providerSku/);
});

test("DigiFlazz dispatch uses the official endpoint and fails closed on provider response codes", () => {
  const provider = read("lib/server/providers/digiflazz.ts");
  assert.match(provider, /https:\/\/api\.digiflazz\.com\/v1\/transaction/);
  assert.match(provider, /parsed\.hostname !== "api\.digiflazz\.com"/);
  assert.match(provider, /code === "00"/);
  assert.match(provider, /code === "03" \|\| code === "99"/);
  assert.match(provider, /if \(code\) return "failed"/);
  assert.match(provider, /\[RC \$\{rc\}\]/);
  assert.match(provider, /SKU DigiFlazz order kosong/);
  assert.match(provider, /Customer No DigiFlazz order kosong/);
});

test("automatic paid-order recovery runs frequently enough for retryable DigiFlazz dispatches", () => {
  const wrangler = read("wrangler.jsonc");
  const worker = read("worker/index.ts");
  const orders = read("lib/server/orders.ts");
  const reconciliation = read("lib/server/digiflazz-reconciliation.ts");

  assert.match(wrangler, /"\*\/5 \* \* \* \*"/);
  assert.match(worker, /recoverStaleAutomaticOrders\(publicBaseUrl\)/);
  assert.match(worker, /reconcileStaleDigiflazzProcessing\(publicBaseUrl\)/);
  assert.match(orders, /provider_status = 'retryable_error'/);
  assert.match(reconciliation, /updated_at <= datetime\('now', '-2 minutes'\)/);
  assert.match(reconciliation, /created_at >= datetime\('now', '-89 days'\)/);
});
