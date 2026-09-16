import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("product price sync scopes the cached refresh to the selected product", () => {
  const route = read("app/api/admin/digiflazz-pricing/route.ts");
  const service = read("lib/server/digiflazz-pricing.ts");
  const productEditor = read("components/admin-product-manager.tsx");
  assert.match(route, /syncDigiflazzProduct/);
  assert.match(route, /input\.productId\s*\?\s*await syncDigiflazzProduct/);
  assert.match(service, /syncDigiflazzProduct\(productId: number\)/);
  assert.match(service, /providerSku\?: string/);
  assert.match(productEditor, /JSON\.stringify\(\{ productId: product\.raw\.dbId \}\)/);
  assert.doesNotMatch(productEditor, /syncProductId/);
});

test("per-nominal sync matches DigiFlazz provider_sku instead of LFAMILIA internal sku", () => {
  const service = read("lib/server/digiflazz-pricing.ts");
  const productEditor = read("components/admin-product-manager.tsx");
  assert.match(service, /target\.providerSku \? " AND p\.provider_sku = \?"/);
  assert.doesNotMatch(service, /target\.(?:providerSku|packageSku) \? " AND p\.sku = \?"/);
  assert.match(productEditor, /packageSku: nominal\.sku/);
});
