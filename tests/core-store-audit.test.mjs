import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("all literal Admin Panel API calls have a unified panel route", () => {
  const componentDir = path.join(root, "components");
  const panelRoute = read("app/api/panel/[...path]/route.ts");
  const files = fs.readdirSync(componentDir).filter((name) =>
    name.startsWith("admin-") && name.endsWith(".tsx")
  );
  const endpoints = new Set();
  for (const name of files) {
    const source = fs.readFileSync(path.join(componentDir, name), "utf8");
    for (const match of source.matchAll(/["'`]\/api\/panel\/([a-z0-9/-]+)/gi)) {
      endpoints.add(match[1].replace(/\/$/, ""));
    }
  }
  for (const endpoint of endpoints) {
    const quoted =
      panelRoute.includes(`"${endpoint}"`) ||
      panelRoute.includes(`'${endpoint}'`);
    const bare = /^[A-Za-z_$][A-Za-z0-9_$]*$/.test(endpoint)
      ? new RegExp("\\b" + endpoint + "\\s*:").test(panelRoute)
      : false;
    assert.ok(quoted || bare, "Missing panel route: " + endpoint);
  }
});

test("core customer account APIs require a customer session", () => {
  for (const file of [
    "app/api/account/route.ts",
    "app/api/account/membership/route.ts",
    "app/api/account/support/route.ts",
    "app/api/account/game-accounts/route.ts",
  ]) {
    assert.match(read(file), /requireCustomerSession\(request\)/, file);
  }
});

test("core mutation APIs reject cross-site requests", () => {
  for (const file of [
    "app/api/auth/login/route.ts",
    "app/api/auth/register/route.ts",
    "app/api/auth/logout/route.ts",
    "app/api/account/route.ts",
    "app/api/account/support/route.ts",
    "app/api/account/game-accounts/route.ts",
    "app/api/reviews/route.ts",
    "app/api/promotions/quote/route.ts",
  ]) {
    assert.match(read(file), /rejectCrossOriginMutation\(request\)/, file);
  }
});

test("essential storefront and operational pages remain present", () => {
  for (const file of [
    "app/page.tsx",
    "app/catalog/page.tsx",
    "app/checkout/page.tsx",
    "app/account/page.tsx",
    "app/track/page.tsx",
    "app/contact/page.tsx",
    "app/faq/page.tsx",
    "app/news/page.tsx",
    "app/promo/page.tsx",
    "app/leaderboard/page.tsx",
    "app/privacy/page.tsx",
    "app/terms/page.tsx",
    "app/refund/page.tsx",
    "app/tools/page.tsx",
    "app/tools/win-rate/page.tsx",
    "app/tools/magic-wheel/page.tsx",
    "app/tools/zodiac/page.tsx",
  ]) {
    assert.equal(fs.existsSync(path.join(root, file)), true, file);
  }
});

test("catalog exposes no reusable restore endpoint or admin control", () => {
  const products = read("lib/server/products.ts");
  const panelRoute = read("app/api/panel/[...path]/route.ts");
  const manager = read("components/admin-product-manager.tsx");

  assert.doesNotMatch(products, /seedFallbackProducts|getFallbackProducts/);
  assert.doesNotMatch(panelRoute, /products\/seed|productSeed/);
  assert.doesNotMatch(manager, /products\/seed|Lengkapi katalog utama|seedProducts|setSeeding/);
  assert.equal(fs.existsSync(path.join(root, "app/api/admin/products/seed/route.ts")), false);
});

test("requested catalog repopulation is permanently gated after its first successful run", () => {
  const products = read("lib/server/products.ts");
  assert.match(products, /catalog_repopulation_2026_09_09/);
  assert.match(products, /if \(completed\?\.completed_at\) return;/);
  assert.doesNotMatch(products, /SELECT COUNT\(\*\) AS count FROM products/);
  assert.doesNotMatch(products, /DELETE FROM one_time_operations/);
});

test("Staff content edits preserve the existing product description", () => {
  const products = read("lib/server/products.ts");
  const section = products.slice(
    products.indexOf("export async function saveProductContent"),
    products.indexOf("export async function updateProductPackageProvider"),
  );
  assert.doesNotMatch(section, /description = \?/);
  assert.doesNotMatch(section, /input\.description/);
});

test("product APIs accept only DigiFlazz and internal voucher stock providers", () => {
  const productsRoute = read("app/api/admin/products/route.ts");
  const packageRoute = read("app/api/admin/product-package-provider/route.ts");
  assert.match(productsRoute, /z\.enum\(\["digiflazz", "voucher-stock"\]\)\.optional\(\)/);
  assert.match(packageRoute, /z\.enum\(\["digiflazz", "voucher-stock"\]\)\.nullable\(\)/);
});

test("panel credential rows never appear as real customers or members", () => {
  const members = read("lib/server/member-tiers.ts");
  const summary = read("app/api/admin/summary/route.ts");
  assert.match(members, /email NOT LIKE '__lfadmin__:%'/);
  assert.match(summary, /email NOT LIKE '__lfadmin__:%'/);
});
