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

test("switching DigiFlazz development and production invalidates operational cache", () => {
  const route = read("app/api/admin/integrations/route.ts");
  assert.match(route, /invalidateDigiflazzOperationalCache/);
  assert.match(route, /DELETE FROM digiflazz_pricelist_cache/);
  assert.match(route, /DELETE FROM digiflazz_seller_monitor/);
  assert.match(route, /last_success_at = NULL/);
  assert.match(route, /before !== input\.selections\.digiflazzEnvironment/);
});

test("DigiFlazz transaction response is JSON-safe and correlated to LFAMILIA order", () => {
  const provider = read("lib/server/providers/digiflazz.ts");
  assert.match(provider, /response\.json\(\)\.catch\(\(\) => null\)/);
  assert.match(provider, /data\.ref_id && data\.ref_id !== order\.referenceId/);
  assert.match(provider, /data\.buyer_sku_code && data\.buyer_sku_code !== order\.providerSku/);
});
