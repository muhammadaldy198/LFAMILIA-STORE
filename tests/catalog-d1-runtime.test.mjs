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

test("hardcoded products remain available only for explicit admin seed", () => {
  const products = read("lib/server/products.ts");
  const seedRoute = read("app/api/admin/products/seed/route.ts");

  assert.match(products, /export function getFallbackProducts/);
  assert.match(products, /export async function seedFallbackProducts/);
  assert.match(seedRoute, /seedFallbackProducts\(/);
});


test("unconfigured automatic products stay automatic and checkout blocks them instead of pretending they are manual", () => {
  const hook = read("hooks/use-store-products.ts");
  const checkout = read("app/checkout/page.tsx");
  assert.doesNotMatch(hook, /normalizeProviderReadiness/);
  assert.doesNotMatch(hook, /fulfillmentType:\s*"manual"/);
  assert.match(checkout, /if \(!providerReady\)/);
  assert.match(checkout, /belum siap dijual karena provider\/SKU belum diatur/);
});
