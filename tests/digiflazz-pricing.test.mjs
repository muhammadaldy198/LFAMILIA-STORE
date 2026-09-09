import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const pricing = fs.readFileSync(path.join(root, "lib/server/digiflazz-pricing.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-product-manager.tsx"), "utf8");

test("automatic DigiFlazz price sync runs daily instead of every recovery cron", () => {
  const worker = read("worker/index.ts");
  const wrangler = read("wrangler.jsonc");
  assert.match(wrangler, /"\*\/15 \* \* \* \*"/);
  assert.match(wrangler, /"15 2 \* \* \*"/);
  assert.match(worker, /event\.cron === "15 2 \* \* \*"/);
  assert.match(worker, /tasks\.push\(syncDigiflazzPrices\(\)/);
});

test("DigiFlazz price parser requires data array before filtering", () => {
  assert.match(pricing, /Array\.isArray\(payload\.data\)/);
  assert.match(pricing, /DigiFlazz menolak price list/);
  assert.match(pricing, /providerError\?\.rc/);
});

test("seller monitor clears stale success message before refresh", () => {
  assert.match(manager, /setMonitorRefreshing\(true\);\s*setError\(""\);\s*setMessage\(""\);/);
});
