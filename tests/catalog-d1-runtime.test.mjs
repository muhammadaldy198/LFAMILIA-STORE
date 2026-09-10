import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("public catalog never falls back to hardcoded products", () => {
  const route = read("app/api/products/route.ts");
  const hook = read("hooks/use-store-products.ts");

  assert.doesNotMatch(route, /getFallbackProducts/);
  assert.match(route, /products: \[\], databaseReady: false/);
  assert.doesNotMatch(hook, /fallbackProducts|normalizedFallbackProducts/);
  assert.match(hook, /useState<StoreProduct\[\]>\(\[\]\)/);
});

test("public catalog strips supplier pricing and provider SKU metadata", () => {
  const route = read("app/api/products/route.ts");
  const checkout = read("app/checkout/page.tsx");

  assert.match(route, /providerConfigured: Boolean\(pkg\.providerCode && pkg\.providerSku\)/);
  assert.doesNotMatch(route, /supplierPrice: pkg\./);
  assert.doesNotMatch(route, /marginValue: pkg\./);
  assert.doesNotMatch(route, /marginType: pkg\./);
  assert.doesNotMatch(route, /pricingMode: pkg\./);
  assert.doesNotMatch(route, /providerSku: pkg\./);
  assert.doesNotMatch(route, /\.\.\.item/);
  assert.match(checkout, /selectedPackage\?\.providerConfigured/);
  assert.doesNotMatch(checkout, /selectedPackage\?\.providerSku/);
});

test("checkout only accepts products and packages that exist in D1", () => {
  const orders = read("lib/server/orders.ts");
  const resolveSection = orders.slice(
    orders.indexOf("export async function resolvePurchasableItem"),
    orders.indexOf("export function renderCustomerNo"),
  );

  assert.doesNotMatch(resolveSection, /getFallbackProducts/);
  assert.doesNotMatch(resolveSection, /fallback/);
  assert.match(resolveSection, /if \(row\)/);
  assert.match(resolveSection, /return null;/);
});

test("catalog has no reusable seed endpoint and returns to normal CRUD after the one-time repopulation", () => {
  const products = read("lib/server/products.ts");
  const panelRoute = read("app/api/panel/[...path]/route.ts");
  const manager = read("components/admin-product-manager.tsx");

  assert.doesNotMatch(products, /seedFallbackProducts|getFallbackProducts/);
  assert.doesNotMatch(panelRoute, /products\/seed|productSeed/);
  assert.doesNotMatch(manager, /products\/seed|Lengkapi katalog utama|seedProducts/);
  assert.equal(fs.existsSync(path.join(root, "app/api/admin/products/seed/route.ts")), false);
  assert.match(products, /if \(completed\?\.completed_at\) return;/);
  assert.doesNotMatch(products, /SELECT COUNT\(\*\) AS count FROM products/);
});

test("unconfigured automatic products stay automatic and checkout blocks them instead of pretending they are manual", () => {
  const hook = read("hooks/use-store-products.ts");
  const checkout = read("app/checkout/page.tsx");
  assert.doesNotMatch(hook, /normalizeProviderReadiness/);
  assert.doesNotMatch(hook, /fulfillmentType:\s*"manual"/);
  assert.match(checkout, /if \(!providerReady\)/);
  assert.match(checkout, /Produk otomatis ini belum siap dijual\. Hubungi admin\./);
});
