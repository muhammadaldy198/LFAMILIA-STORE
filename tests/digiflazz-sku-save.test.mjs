import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("provider SKU can still be saved by the existing backend contract", () => {
  const route = read("app/api/admin/product-package-provider/route.ts");
  const products = read("lib/server/products.ts");
  assert.match(route, /updateProductPackageProvider/);
  assert.match(products, /UPDATE product_packages/);
  assert.match(products, /provider_sku = \?/);
});

test("admin creates products manually and imports DigiFlazz nominal SKUs", () => {
  const source = read("components/admin-product-manager.tsx");
  assert.match(source, /Tambah Produk Manual/);
  assert.match(source, /Import Nominal dari Digiflazz/);
  assert.match(source, /sku: item\.sku/);
  assert.match(source, /Produk tidak dibuat otomatis/);
  assert.doesNotMatch(source, /Import Produk dari Digiflazz/);
});
