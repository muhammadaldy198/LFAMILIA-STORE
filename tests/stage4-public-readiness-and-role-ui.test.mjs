import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

test("public product contract exposes only server-derived fulfillment readiness", () => {
  const route = read("app/api/products/route.ts");
  const checkout = read("app/checkout/page.tsx");

  assert.match(route, /fulfillmentReady: item\.fulfillmentType === "manual" \|\| Boolean\(pkg\.providerCode && pkg\.providerSku\)/);
  assert.doesNotMatch(route, /provider(Code|Sku|Configured):/);
  assert.match(route, /Cache-Control": "no-store"/);
  assert.match(checkout, /selectedPackage\?\.fulfillmentReady/);
  assert.match(checkout, /item\.fulfillmentReady/);
  assert.doesNotMatch(checkout, /selectedPackage\?\.provider(Code|Sku|Configured)/);
  assert.doesNotMatch(checkout, /item\.provider(Code|Sku)/);
});

test("dashboard and search keep staff inside their permitted workspace", () => {
  const dashboard = read("components/admin-dashboard.tsx");
  const overview = read("components/admin-overview.tsx");

  assert.match(dashboard, /const visibleNavigation = navigation\.filter/);
  assert.match(dashboard, /visibleNavigation\.find\(\(item\) => item\.value === preferred\)/);
  assert.doesNotMatch(dashboard, /setActiveTab\("products"\)/);
  assert.match(overview, /const visibleFeatureCards = featureCards\.filter/);
  assert.match(overview, /minimumRole: "admin"/);
  assert.match(overview, /minimumRole: "super_admin"/);
  assert.match(overview, /\{!isStaff && <Panel className="min-h-\[282px\]">/);
  assert.match(overview, /role === "super_admin" \? "team" : "orders"/);
});

test("operator copy does not advertise unavailable capabilities or e-wallets", () => {
  const overview = read("components/admin-overview.tsx");
  const checkout = read("app/checkout/page.tsx");
  const payments = read("components/admin-payment-workspace.tsx");
  const login = read("components/panel-login.tsx");

  for (const forbidden of [/Blacklist/i, /Cashback/i, /Live chat/i, /SLA & prioritas/i, /Export CSV \/ PDF/i, /Permission matrix/i, /\bOVO\b/, /\bGoPay\b/]) {
    assert.doesNotMatch(overview, forbidden);
  }
  assert.doesNotMatch(checkout, /\bOVO\b|\bGoPay\b/);
  assert.doesNotMatch(payments, /id: "gopay"|id: "ovo"/);
  assert.match(login, /yang dibuat oleh Super Admin/);
});
