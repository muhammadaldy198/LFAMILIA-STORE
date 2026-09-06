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
    "app/api/payments/midtrans/create/route.ts",
    "app/api/payments/ipaymu/create/route.ts",
    "app/api/reviews/route.ts",
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
    ["app/api/payments/midtrans/create/route.ts", "midtrans-checkout"],
    ["app/api/payments/ipaymu/create/route.ts", "ipaymu-checkout"],
    ["app/api/reviews/route.ts", "customer-review"],
  ]);
  for (const [file, scope] of expected) assert.ok(read(file).includes(`allowRequest(request, "${scope}"`), `${file} missing ${scope}`);
});

test("saved game accounts enforce ownership on writes", () => {
  const source = read("app/api/account/game-accounts/route.ts");
  assert.match(source, /WHERE id = \? AND customer_id = \?/);
  assert.match(source, /DELETE FROM customer_game_accounts WHERE id = \? AND customer_id = \?/);
});

test("Worker applies baseline browser security headers", () => {
  const source = read("worker/index.ts");
  for (const header of ["X-Content-Type-Options","X-Frame-Options","Referrer-Policy","Permissions-Policy","Content-Security-Policy","Strict-Transport-Security"]) assert.ok(source.includes(header), header);
});

test("customer sessions are bounded and stale rate-limit buckets are cleaned", () => {
  assert.match(read("lib/server/customer-auth.ts"), /oldSessions\.results\.slice\(4\)/);
  assert.match(read("worker/index.ts"), /cleanupSecurityRateLimits/);
});
