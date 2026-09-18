import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
function read(file) { return fs.readFileSync(path.join(root, file), "utf8"); }

test("critical public mutation routes reject cross-site requests", () => {
  const routes = [
    "app/api/auth/login/route.ts",
    "app/api/auth/register/route.ts",
    "app/api/auth/logout/route.ts",
    "app/api/nickname/route.ts",
    "app/api/orders/status/route.ts",
    "app/api/promotions/quote/route.ts",
    "app/api/account/route.ts",
    "app/api/account/support/route.ts",
    "app/api/account/game-accounts/route.ts",
    "app/api/account/topups/route.ts",
    "app/api/payments/wallet/create/route.ts",
    "app/api/payments/auto/create/route.ts",
    "app/api/reviews/route.ts",
    "app/admin/panel/auth/login/route.ts",
    "app/staff/panel/auth/login/route.ts",
    "app/api/admin/auth/setup/route.ts",
    "app/api/admin/media/route.ts",
    "app/admin/panel/auth/logout/route.ts",
    "app/staff/panel/auth/logout/route.ts",
  ];
  for (const file of routes) assert.match(read(file), /rejectCrossOriginMutation\(request\)/, file);
});

test("abuse-prone public endpoints are rate limited", () => {
  const expected = new Map([
    ["app/api/auth/login/route.ts", "customer-login"],
    ["app/api/auth/register/route.ts", "customer-register"],
    ["app/api/nickname/route.ts", "nickname-lookup"],
    ["app/api/orders/status/route.ts", "order-status"],
    ["app/api/promotions/quote/route.ts", "promotion-quote"],
    ["app/api/account/support/route.ts", "customer-support"],
    ["app/api/account/game-accounts/route.ts", "saved-game-account"],
    ["app/api/account/topups/route.ts", "wallet-topup"],
    ["app/api/payments/wallet/create/route.ts", "wallet-checkout"],
    ["app/api/payments/auto/create/route.ts", "automatic-checkout"],
    ["app/api/reviews/route.ts", "customer-review"],
  ]);
  for (const [file, scope] of expected) assert.ok(read(file).includes(`allowRequest(request, "${scope}"`), `${file} missing ${scope}`);
});

test("all unified panel mutations reject cross-site requests centrally", () => {
  const source = read("app/api/panel/[...path]/route.ts");
  assert.match(source, /rejectCrossOriginMutation\(request\)/);
  assert.match(source, /if \(originBlock\) return originBlock/);
});

test("saved game accounts enforce ownership on writes", () => {
  const source = read("app/api/account/game-accounts/route.ts");
  assert.match(source, /WHERE id = \? AND customer_id = \?/);
  assert.match(source, /DELETE FROM customer_game_accounts WHERE id = \? AND customer_id = \?/);
});

test("Cloudflare Access diagnostics escape unverified JWT claim text", () => {
  const source = read("worker/index.ts");
  assert.match(source, /function escapeHtml\(value: string \| undefined \| null\)/);
  assert.match(source, /escapeHtml\(receivedAudience\)/);
  assert.match(source, /escapeHtml\(receivedIssuer\)/);
});

test("Worker applies baseline browser security headers", () => {
  const source = read("worker/index.ts");
  for (const header of ["X-Content-Type-Options","X-Frame-Options","Referrer-Policy","Permissions-Policy","Content-Security-Policy","Strict-Transport-Security"]) assert.ok(source.includes(header), header);
});

test("Worker runtime retains the configured R2 media binding", () => {
  const source = read("worker/index.ts");
  assert.match(source, /BUCKET\?: object/);
  const integrations = read("lib/server/integration-config.ts");
  assert.match(integrations, /const target: Record<string, unknown> = \{ \.\.\.systemOnly \}/);
});

test("Admin and Staff API responses are centrally non-cacheable", () => {
  const panel = read("app/api/panel/[...path]/route.ts");
  const worker = read("worker/index.ts");
  assert.match(panel, /Cloudflare-CDN-Cache-Control", "no-store"/);
  assert.match(panel, /Cache-Control", "no-store, no-cache, must-revalidate"/);
  assert.match(worker, /isSensitiveAdminApi/);
  assert.match(worker, /url\.pathname\.startsWith\("\/api\/admin\/"\)/);
});

test("authenticated customer data is explicitly non-cacheable", () => {
  for (const file of [
    "app/api/account/route.ts",
    "app/api/account/membership/route.ts",
    "app/api/account/support/route.ts",
    "app/api/account/game-accounts/route.ts",
    "app/api/reviews/route.ts",
  ]) {
    assert.match(read(file), /"Cache-Control": "no-store"/, file);
  }
  assert.match(read("lib/server/customer-auth.ts"), /status: 401, headers: \{ "Cache-Control": "no-store" \}/);
});

test("customer sessions are bounded and stale rate-limit buckets are cleaned", () => {
  assert.match(read("lib/server/customer-auth.ts"), /oldSessions\.results\.slice\(4\)/);
  assert.match(read("worker/index.ts"), /cleanupSecurityRateLimits/);
});


test("customer login and registration support Cloudflare Turnstile", () => {
  for (const file of ["app/api/auth/login/route.ts", "app/api/auth/register/route.ts"]) {
    const source = read(file);
    assert.match(source, /verifyTurnstile\(request, input\.turnstileToken\)/);
  }
  assert.match(read("lib/server/turnstile.ts"), /turnstile\/v0\/siteverify/);
});


test("public and phone-only order search keep invoices masked", () => {
  const source = read("app/api/orders/search/route.ts");
  const getStart = source.indexOf("export async function GET");
  const postStart = source.indexOf("export async function POST");
  const publicFeed = source.slice(getStart, postStart);
  assert.match(publicFeed, /SELECT reference_id, product_name/);
  assert.match(source, /const referenceId = revealInvoice \? row\.reference_id \?\? null : null/);
  assert.match(source, /session\?\.phoneVerified/);
  assert.match(source, /normalizeWhatsappPhone\(phone\) === normalizeWhatsappPhone\(session\.phone\)/);
  assert.doesNotMatch(source, /mapSummary\(row, true\)/);
  assert.match(source, /maskedReferenceId: referenceId \|\| maskedInvoice\(row\.reference_id\)/);
  assert.match(source, /allowRequest\(request, "order-phone-search", 5, 600\)/);
});

test("new invoices use an independent compact random token", () => {
  const source = read("lib/server/orders.ts");
  assert.match(source, /crypto\.randomUUID\(\)\.replaceAll\("-", ""\)\.slice\(0, 14\)\.toUpperCase\(\)/);
  assert.match(source, /referenceId: `LF\$\{date\}\$\{referenceToken\}`/);
});

test("admin authorization uses the shared cross-origin mutation guard", () => {
  const source = read("lib/server/admin.ts");
  assert.match(source, /rejectCrossOriginMutation\(request\)/);
  assert.doesNotMatch(source, /origin !== new URL\(request\.url\)\.origin/);
});

test("owner setup trusts only Worker-injected Access identity", () => {
  const source = read("lib/server/admin.ts");
  assert.match(source, /x-lfamilia-admin-email/);
  assert.doesNotMatch(source, /cf-access-authenticated-user-email/);
});

test("Worker cryptographically validates Cloudflare Access assertions", () => {
  const source = read("worker/index.ts");
  assert.match(source, /verifyCloudflareAccess\(request, env\)/);
  assert.doesNotMatch(source, /ctx\.access|getIdentity\(\)/);
  assert.doesNotMatch(source, /cf-access-authenticated-user-email/);

  const verifier = read("lib/server/cloudflare-access.ts");
  for (const requirement of [
    "createRemoteJWKSet",
    "jwtVerify",
    'algorithms: ["RS256"]',
    "POLICY_AUD",
    "TEAM_DOMAIN",
    "issuer: teamDomain",
    "audience",
  ]) {
    assert.ok(verifier.includes(requirement), requirement);
  }
});


test("managed storefront links reject javascript and protocol-relative URLs", () => {
  const helper = read("lib/navigation-url.ts");
  const contentRoute = read("app/api/admin/content/route.ts");
  const storefrontRoute = read("app/api/admin/storefront/route.ts");
  const contentServer = read("lib/server/content.ts");
  const storefrontServer = read("lib/server/storefront.ts");
  assert.match(helper, /url\.protocol === "https:" \|\| url\.protocol === "http:"/);
  assert.match(helper, /!normalized\.startsWith\("\/\/"\)/);
  assert.match(contentRoute, /isAllowedNavigationUrl/);
  assert.match(storefrontRoute, /isAllowedNavigationUrl/);
  assert.match(contentServer, /safeNavigationUrl/);
  assert.match(storefrontServer, /safeNavigationUrl/);
  assert.match(storefrontRoute, /isAllowedHttpUrl/);
  assert.match(storefrontServer, /safeHttpUrl/);
});
