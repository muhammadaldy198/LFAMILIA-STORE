import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("public reads warm runtime and schema repair outside the critical response path", () => {
  const worker = read("worker/index.ts");
  const panel = read("app/api/panel/[...path]/route.ts");

  assert.match(worker, /RUNTIME_HYDRATION_TTL_MS = 15_000/);
  assert.match(worker, /requestNeedsHydratedRuntime/);
  assert.match(worker, /ctx\.waitUntil\(\s*hydrateRuntime\(env\)\.catch/);
  assert.match(worker, /if \(isReadOnlyRequest\(request\)\)[\s\S]*ctx\.waitUntil\([\s\S]*ensureLegacyDatabaseColumns/);
  assert.match(worker, /else \{\s*await ensureLegacyDatabaseColumns\(\)\.catch/);
  assert.match(panel, /if \(!\["GET", "HEAD", "OPTIONS"\]\.includes\(method\)\) \{\s*await ensureLegacyDatabaseColumns\(\)/);
});

test("payment runtime initialization and readiness avoid repeated D1 work", () => {
  const modes = read("lib/server/payment-mode-config.ts");
  const publicMethods = read("app/api/payment-methods/route.ts");
  const adminMethods = read("app/api/admin/payment-methods/route.ts");
  const router = read("lib/server/payment-router.ts");

  assert.match(modes, /let schemaPromise: Promise<void> \| null = null/);
  assert.match(modes, /schemaPromise = db\.batch\(/);
  assert.match(router, /getConfiguredGatewayBaseReadiness/);
  assert.match(publicMethods, /activeGatewayNames/);
  assert.match(publicMethods, /getConfiguredGatewayBaseReadiness/);
  assert.doesNotMatch(publicMethods, /getConfiguredGatewayReadiness/);
  assert.match(publicMethods, /public, max-age=10, s-maxage=10, stale-while-revalidate=20/);
  assert.match(adminMethods, /gatewayState/);
  assert.doesNotMatch(adminMethods, /channels\.map\(async/);
});

test("storefront and dashboard reads use bounded cache and provider latency", () => {
  const storefrontHook = read("hooks/use-storefront.ts");
  const storefrontRoute = read("app/api/storefront/route.ts");
  const digiflazz = read("lib/server/providers/digiflazz.ts");
  const summary = read("app/api/admin/summary/route.ts");

  assert.doesNotMatch(storefrontHook, /fetch\("\/api\/storefront", \{ cache: "no-store" \}\)/);
  assert.match(storefrontRoute, /public, max-age=30, s-maxage=60, stale-while-revalidate=120/);
  assert.match(digiflazz, /getDigiflazzBalance\(options: \{ timeoutMs\?: number \} = \{\}\)/);
  assert.match(summary, /getDigiflazzBalance\(\{ timeoutMs: 1_500 \}\)/);
  assert.match(summary, /const commonPromise = db\.batch\(/);
  assert.match(summary, /const \[common, finance, attentionRows, synced, activities, balance\] = await Promise\.all/);
});

test("public storefront content reuses short browser caches instead of forcing reloads", () => {
  const publicClients = [
    ["components/home-banner-carousel.tsx", "/api/home-content"],
    ["components/global-home-popup.tsx", "/api/home-content"],
    ["components/home-news-preview.tsx", "/api/news"],
    ["components/news-browser.tsx", "/api/news"],
    ["components/home-reviews-preview.tsx", "/api/reviews?featured=1"],
    ["components/promotion-showcase.tsx", "/api/promotions"],
    ["app/checkout/page.tsx", "/api/payment-methods"],
    ["components/customer-account.tsx", "/api/wallet"],
    ["app/payment/page.tsx", "/api/payment-page-settings"],
  ];

  for (const [file, endpoint] of publicClients) {
    const source = read(file);
    assert.ok(source.includes('fetch("' + endpoint + '"'), file);
    assert.ok(!source.includes('fetch("' + endpoint + '", { cache: "no-store" })'), file);
  }

  assert.match(read("app/api/payment-page-settings/route.ts"), /public, max-age=30, s-maxage=60, stale-while-revalidate=120/);
  assert.match(read("app/api/news/route.ts"), /s-maxage=120, stale-while-revalidate=180/);
  assert.match(read("app/api/promotions/route.ts"), /s-maxage=60, stale-while-revalidate=120/);
  assert.match(read("app/api/reviews/route.ts"), /s-maxage=120, stale-while-revalidate=180/);
});

test("Admin overview and notification bell share one short-lived summary request", () => {
  const helper = read("lib/client/admin-summary.ts");
  const overview = read("components/admin-overview.tsx");
  const notifications = read("components/admin-notifications.tsx");

  assert.match(helper, /SUMMARY_CACHE_TTL_MS = 5_000/);
  assert.match(helper, /summaries\.get\(key\)/);
  assert.match(helper, /api\/panel\/summary\?range=/);
  assert.match(overview, /fetchAdminSummary<Summary>\(range\)/);
  assert.match(notifications, /fetchAdminSummary<Summary>\("7d", \{ force \}\)/);
  assert.ok(!overview.includes('fetch(`/api/panel/summary'));
  assert.ok(!notifications.includes('fetch("/api/panel/summary'));
});

test("wallet and compatibility helpers keep read paths lightweight", () => {
  const wallet = read("lib/server/wallet.ts");
  const publicWallet = read("app/api/wallet/route.ts");
  const nickname = read("lib/server/nickname-config.ts");
  const members = read("lib/server/member-tiers.ts");
  const monitor = read("lib/server/digiflazz-monitor.ts");

  assert.match(wallet, /options: \{ repairSchema\?: boolean \} = \{\}/);
  assert.match(publicWallet, /readWalletSettings\(\{ repairSchema: false \}\)/);
  assert.match(publicWallet, /getConfiguredGatewayBaseReadiness/);
  assert.doesNotMatch(publicWallet, /candidates\.map\(async/);
  assert.match(nickname, /options: \{ repairSchema\?: boolean \} = \{\}/);
  assert.doesNotMatch(members, /ensureLegacyDatabaseColumns/);
  assert.match(members, /await db\.batch\(/);
  assert.match(monitor, /monitorSchemaPromise/);
});
