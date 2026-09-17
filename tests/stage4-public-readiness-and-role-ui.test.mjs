import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

test("public product contract exposes neutral delivery mode and readiness", () => {
  const route = read("app/api/products/route.ts");
  const checkout = read("app/checkout/page.tsx");
  const availability = read("lib/server/availability.ts");

  assert.match(route, /const fulfillmentMode = item\.fulfillmentType === "manual"/);
  assert.match(route, /pkg\.providerCode === "voucher-stock"/);
  assert.match(route, /const fulfillmentReady = item\.fulfillmentType === "manual" \|\| Boolean\(pkg\.providerCode && pkg\.providerSku\)/);
  assert.match(route, /fulfillmentAvailable/);
  assert.match(route, /voucherStockKeys\.has\(pkg\.providerSku\)/);
  assert.match(route, /digiflazzAvailability\.get\(pkg\.dbId\) === true/);
  assert.match(availability, /start_cut_off/);
  assert.match(availability, /end_cut_off/);
  assert.match(availability, /isDigiflazzSnapshotAvailable\(/);
  assert.match(availability, /currentMinutes\(date, "Asia\/Jakarta"\)/);
  assert.doesNotMatch(route, /provider(Code|Sku|Configured):/);
  assert.match(checkout, /selectedPackage\?\.fulfillmentMode/);
  assert.match(checkout, /isVoucherStock = fulfillmentMode === "voucher_stock"/);
  assert.doesNotMatch(checkout, /isVoucherStock = isVoucherProduct/);
  assert.doesNotMatch(checkout, /selectedPackage\?\.provider(Code|Sku|Configured)/);
});

test("dashboard search and Staff stay inside their permitted workspace", () => {
  const dashboard = read("components/admin-dashboard.tsx");
  const overview = read("components/admin-overview.tsx");

  assert.match(dashboard, /const visibleNavigation = navigation\.filter/);
  assert.match(dashboard, /visibleNavigation\.find\(\(item\) => item\.value === preferred\)/);
  assert.doesNotMatch(dashboard, /setActiveTab\("products"\)/);
  assert.match(overview, /const visibleFeatureCards = featureCards\.filter/);
  assert.match(overview, /minimumRole: "admin"/);
  assert.match(overview, /minimumRole: "super_admin"/);
  assert.match(overview, /role === "super_admin" \? "team" : "orders"/);
});

test("Admin dashboard does not render backend-restricted finance data or integrations navigation", () => {
  const overview = read("components/admin-overview.tsx");

  assert.match(overview, /const canViewFinance = summary\?\.canViewFinance === true/);
  assert.match(overview, /\{canViewFinance && <MetricCard Icon=\{WalletCards\} label="Omzet Hari Ini"/);
  assert.match(overview, /\{canViewFinance && <MetricCard Icon=\{CircleDollarSign\} label="Saldo Digiflazz"/);
  assert.match(overview, /\{canViewFinance && <Panel>/);
  assert.match(overview, /\{canViewFinance && <th[^>]*>Total<\/th>\}/);
  assert.match(overview, /role === "super_admin" && <button type="button" onClick=\{\(\) => onNavigate\?\.\("integrations"\)\}/);
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
