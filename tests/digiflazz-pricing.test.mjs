import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const pricing = read("lib/server/digiflazz-pricing.ts");
const availability = read("lib/server/availability.ts");
const provider = read("lib/server/providers/digiflazz.ts");
const manager = read("components/admin-product-manager.tsx");
const workspace = read("components/admin-digiflazz-workspace.tsx");

test("retired Worker has no cron and Laravel schedules reconciliation", () => {
  const wrangler = read("wrangler.jsonc");
  const scheduler = read("laravel/routes/console.php");
  assert.match(wrangler, /"crons": \[\]/);
  assert.match(wrangler, /"workers_dev": false/);
  assert.match(wrangler, /"preview_urls": false/);
  assert.match(scheduler, /Schedule::command\('lfamilia:reconcile'\)/);
  assert.match(scheduler, /->everyMinute\(\)/);
});

test("DigiFlazz price parser requires data array before filtering", () => {
  assert.match(pricing, /Array\.isArray\(payload\.data\)/);
  assert.match(pricing, /DigiFlazz menolak price list/);
  assert.match(pricing, /providerError\?\.rc/);
});

test("admin product import reads the last cached pricelist instead of calling DigiFlazz", () => {
  const route = read("app/api/admin/digiflazz-pricing/route.ts");
  assert.match(pricing, /CREATE TABLE IF NOT EXISTS digiflazz_pricelist_cache/);
  assert.match(pricing, /FROM digiflazz_pricelist_cache/);
  assert.match(pricing, /writePriceListCache\(items, lock\.token\)/);
  assert.match(route, /listDigiflazzPriceList/);
  assert.match(route, /getDigiflazzPriceListCacheMeta/);
});

test("LFAMILIA selling price uses configured Digiflazz Max Price plus margin", () => {
  assert.match(pricing, /const maxPrice = Number\(item\.provider_max_price\) > 0/);
  assert.match(pricing, /const sellingPrice = sale\(maxPrice, item\.margin_type, item\.margin_value\)/);
  assert.match(pricing, /const sellingPrice = Math\.max\(1, sale\(maxPrice, input\.marginType, marginValue\)\)/);
  assert.doesNotMatch(pricing, /sale\(Number\(sourceItem\.price\), item\.margin_type, item\.margin_value\)/);
  assert.match(pricing, /provider_max_price = COALESCE\(provider_max_price, \?\)/);
});

test("Digiflazz fulfillment still uses the provider price guard while the panel shows current cost", () => {
  assert.doesNotMatch(provider, /max_price\s*:/);
  assert.doesNotMatch(availability, /currentPrice\s*>\s*maxPrice/);
  assert.match(workspace, /Harga DigiFlazz/);
  assert.doesNotMatch(workspace, /Max Price Digiflazz|marginType|marginValue|Harga Jual = Max Price/);
});

test("Digiflazz panel filters product and sorts numeric nominal while Produk owns selling price", () => {
  const route = read("app/api/admin/digiflazz-pricing/route.ts");
  const proxy = read("app/api/panel/[...path]/route.ts");
  assert.match(route, /export async function PUT/);
  assert.match(route, /updateDigiflazzPackagePricing/);
  assert.match(proxy, /digiflazzPricing\.PUT/);
  assert.match(workspace, /Semua Produk/);
  assert.match(workspace, /item\.productName === product/);
  assert.match(workspace, /nominalNumber\(left\.packageLabel\) - nominalNumber\(right\.packageLabel\)/);
  assert.match(workspace, /Harga jual dan margin dikelola pada menu Produk/);
});

test("product save cannot overwrite existing Digiflazz price control", () => {
  const route = read("app/api/admin/products/route.ts");
  const providerRoute = read("app/api/admin/product-package-provider/route.ts");
  assert.match(route, /captureDigiflazzPricing/);
  assert.match(route, /removePricingAuthorityFromProduct/);
  assert.match(route, /restoreDigiflazzPricing/);
  assert.match(route, /providerMaxPrice: null/);
  assert.match(providerRoute, /Product management owns the SKU mapping only/);
  assert.doesNotMatch(providerRoute, /SKU dan Max Price DigiFlazz wajib diisi/);
});

test("seller monitor clears stale success message before refresh in legacy product editor", () => {
  assert.match(manager, /setMonitorRefreshing\(true\);\s*setError\(""\);\s*setMessage\(""\);/);
});
