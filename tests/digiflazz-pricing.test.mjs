import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const pricing = fs.readFileSync(path.join(root, "lib/server/digiflazz-pricing.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-product-manager.tsx"), "utf8");

test("automatic DigiFlazz pricelist sync runs once per hour", () => {
  const worker = read("worker/index.ts");
  const wrangler = read("wrangler.jsonc");
  assert.match(wrangler, /"\*\/15 \* \* \* \*"/);
  assert.match(wrangler, /"5 \* \* \* \*"/);
  assert.match(worker, /event\.cron === "5 \* \* \* \*"/);
  assert.match(worker, /tasks\.push\(syncDigiflazzPrices\(\)/);
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
  assert.match(route, /catalog: await listDigiflazzPriceList|const \[settings, catalog, cache\]/);
  assert.match(route, /getDigiflazzPriceListCacheMeta/);
});

test("seller price refresh preserves LFAMILIA provider max price", () => {
  assert.match(pricing, /provider_max_price = COALESCE\(provider_max_price, \?\)/);
  assert.match(pricing, /const maxPrice = Number\(item\.provider_max_price\) > 0/);
  assert.match(pricing, /sale\(maxPrice, item\.margin_type, item\.margin_value\)/);
});

test("seller monitor clears stale success message before refresh", () => {
  assert.match(manager, /setMonitorRefreshing\(true\);\s*setError\(""\);\s*setMessage\(""\);/);
});
