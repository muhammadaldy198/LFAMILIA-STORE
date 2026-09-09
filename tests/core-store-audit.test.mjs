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
    for (const match of source.matchAll(/["'\x60]\/api\/panel\/([a-z0-9/-]+)/gi)) {
      endpoints.add(match[1].replace(/\/$/, ""));
    }
  }
  for (const endpoint of endpoints) {
    if (endpoint.includes("/")) {
      assert.ok(
        panelRoute.includes(`"${endpoint}"`) || panelRoute.includes(`'${endpoint}'`),
        "Missing panel route: " + endpoint,
      );
    } else {
      const escaped = endpoint.replace(/[.*+?^$()|[\]\\]/g, "\\  for (const endpoint of endpoints) {
    const escaped = endpoint.replace(/[.*+?^$()|[\]\\]/g, "\\$&");
    const routePattern = new RegExp(
      '(?:["\\']' + escaped + '["\\']|\\\\b' + escaped + '\\\\b)\\\\s*:',
    );
    assert.match(panelRoute, routePattern, "Missing panel route: " + endpoint);
  }");
      assert.match(
        panelRoute,
        new RegExp("\\b" + escaped + "\\s*:"),
        "Missing panel route: " + endpoint,
      );
    }
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

test("an accidentally emptied catalog self-restores without overwriting existing products", () => {
  const source = read("lib/server/products.ts");
  assert.match(source, /SELECT COUNT\(\*\) AS count FROM products/);
  assert.match(source, /if \(Number\(catalogCount\?\.count \?\? 0\) === 0\)/);
  assert.match(source, /await seedFallbackProducts\(\)/);
  assert.match(source, /ON CONFLICT\(slug\) DO NOTHING/);
  assert.match(source, /ON CONFLICT\(sku\) DO NOTHING/);
});
