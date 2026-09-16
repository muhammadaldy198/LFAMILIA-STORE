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

test("DigiFlazz transaction response is JSON-safe and requires exact LFAMILIA correlation", () => {
  const provider = read("lib/server/providers/digiflazz.ts");
  assert.match(provider, /response\.json\(\)\.catch\(\(\) => null\)/);
  assert.match(provider, /data\.ref_id !== order\.referenceId/);
  assert.match(provider, /data\.buyer_sku_code !== order\.providerSku/);
  assert.match(provider, /externalId: data\.ref_id/);
  assert.doesNotMatch(provider, /data\.ref_id && data\.ref_id !== order\.referenceId/);
  assert.doesNotMatch(provider, /data\.buyer_sku_code && data\.buyer_sku_code !== order\.providerSku/);
});
