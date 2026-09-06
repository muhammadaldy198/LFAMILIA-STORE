import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("provider SKU can be saved directly without DigiFlazz sync", () => {
  const route = read("app/api/admin/product-package-provider/route.ts");
  const products = read("lib/server/products.ts");
  assert.match(route, /updateProductPackageProvider/);
  assert.match(products, /UPDATE product_packages/);
  assert.match(products, /provider_sku = \?/);
});

test("admin panel exposes direct Simpan SKU action", () => {
  const source = read("components/admin-product-manager.tsx");
  assert.match(source, /\/api\/panel\/product-package-provider/);
  assert.match(source, /Simpan SKU/);
  assert.match(source, /berhasil disimpan tanpa sync DigiFlazz/);
});

test("DigiFlazz sync requires an already-entered provider SKU", () => {
  const source = read("components/admin-product-manager.tsx");
  assert.match(source, /Boolean\(entry\.providerSku\?\.trim\(\)\)/);
  assert.match(source, /Isi SKU DigiFlazz lalu tekan Simpan SKU/);
});
