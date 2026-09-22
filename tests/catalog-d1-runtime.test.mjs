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

  assert.match(route, /const fulfillmentMode = item\.fulfillmentType === "manual"/);
  assert.match(route, /pkg\.providerCode === "voucher-stock"/);
  assert.match(route, /\? "voucher_stock"/);
  assert.match(route, /const fulfillmentReady = item\.fulfillmentType === "manual" \|\| Boolean\(pkg\.providerCode && pkg\.providerSku\)/);
  assert.match(route, /fulfillmentAvailable/);
  assert.match(route, /voucherStockKeys\.has\(pkg\.providerSku\)/);
  assert.match(route, /digiflazzAvailability\.get\(pkg\.dbId\) === true/);
  assert.doesNotMatch(route, /providerConfigured:/);
  assert.doesNotMatch(route, /providerCode: pkg\.providerCode/);
  assert.doesNotMatch(route, /supplierPrice: pkg\./);
  assert.doesNotMatch(route, /marginValue: pkg\./);
  assert.doesNotMatch(route, /marginType: pkg\./);
  assert.doesNotMatch(route, /pricingMode: pkg\./);
  assert.doesNotMatch(route, /providerSku: pkg\./);
  assert.doesNotMatch(route, /\.\.\.item/);
  assert.match(checkout, /selectedPackage\?\.fulfillmentReady/);
  assert.match(checkout, /selectedPackage\?\.fulfillmentAvailable === true/);
  assert.match(checkout, /selectedPackage\?\.fulfillmentMode/);
  assert.match(checkout, /isVoucherStock = fulfillmentMode === "voucher_stock"/);
  assert.match(checkout, /item\.fulfillmentReady/);
  assert.doesNotMatch(checkout, /selectedPackage\?\.providerSku/);
  assert.doesNotMatch(checkout, /selectedPackage\?\.providerCode/);
  assert.doesNotMatch(checkout, /item\.provider(Code|Sku)/);
  assert.match(route, /public, max-age=10, s-maxage=10, stale-while-revalidate=20/);
  assert.match(route, /readProducts\(false, \{ repairSchema: false \}\)/);
  assert.match(route, /readDigiflazzPackageAvailability\(\{ repairSchema: false \}\)/);
  assert.match(route, /\.filter\(\(pkg\) => pkg\.fulfillmentAvailable\)/);
  assert.match(route, /\.filter\(\(item\) => item\.packages\.length > 0\)/);
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
  assert.match(resolveSection, /providerCode: row\.provider_code\?\.trim\(\)\.toLowerCase\(\) \|\| null/);
  assert.match(resolveSection, /providerSku: row\.provider_sku\?\.trim\(\) \|\| null/);
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
  assert.match(checkout, /Nominal ini sedang cut-off otomatis/);
  assert.match(checkout, /disabled=\{!available\}/);
});
