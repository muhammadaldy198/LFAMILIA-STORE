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

test("automatic DigiFlazz recovery runs every five minutes while pricelist sync stays hourly", () => {
  const worker = read("worker/index.ts");
  const wrangler = read("wrangler.jsonc");
  assert.match(wrangler, /"\*\/5 \* \* \* \*"/);
  assert.match(wrangler, /"5 \* \* \* \*"/);
  assert.match(worker, /event\.cron === "5 \* \* \* \*"/);
  assert.match(worker, /tasks\.push\(syncDigiflazzPrices\(\)/);
  assert.match(worker, /recoverStaleAutomaticOrders\(publicBaseUrl\)/);
  assert.match(worker, /reconcileStaleDigiflazzProcessing\(publicBaseUrl\)/);
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
  assert.match(pricing, /writePriceListCache\(items\)/);
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

test("Digiflazz dashboard owns the Max Price guard, not LFAMILIA fulfillment", () => {
  assert.doesNotMatch(provider, /max_price\s*:/);
  assert.doesNotMatch(provider, /Max Price DigiFlazz pada order tidak valid/);
  assert.doesNotMatch(availability, /currentPrice\s*>\s*maxPrice/);
  assert.doesNotMatch(availability, /provider_max_price/);
  assert.doesNotMatch(workspace, /blockedByMaxPrice/);
  assert.match(workspace, /Guard transaksi tetap dikelola oleh Digiflazz/);
});

test("Digiflazz workspace owns LFAMILIA max price and margin controls", () => {
  const route = read("app/api/admin/digiflazz-pricing/route.ts");
  const proxy = read("app/api/panel/[...path]/route.ts");
  assert.match(route, /export async function PUT/);
  assert.match(route, /updateDigiflazzPackagePricing/);
  assert.match(proxy, /digiflazzPricing\.PUT/);
  assert.match(workspace, /Price Control LFAMILIA/);
  assert.match(workspace, /Max Price Digiflazz/);
  assert.match(workspace, /Harga Digiflazz/);
  assert.match(workspace, /Harga Jual/);
  assert.match(workspace, /Rumus: Max Price \+ margin/);
  assert.match(workspace, /method: "PUT"/);
});

test("Digiflazz filters use provider category and brand as subcategory", () => {
  assert.match(workspace, /item\.category/);
  assert.match(workspace, /item\.brand/);
  assert.match(workspace, /Semua Subkategori/);
  assert.match(workspace, /Kategori → Subkategori \/ Brand → SKU/);
  assert.doesNotMatch(workspace, /Steam Wallet.*Google Play/);
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
