import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const read = (file) => fs.readFileSync(path.join(process.cwd(), file), "utf8");

test("panel authorization supports Super Admin, Admin, and Staff", () => {
  assert.match(read("lib/server/admin-auth.ts"), /AdminRole = "super_admin" \| "admin" \| "staff"/);
  assert.match(read("lib/server/admin.ts"), /hasMinimumAdminRole\(session\.role, minimumRole\)/);
  const rules = read("lib/server/final-audit-rules.ts");
  assert.match(rules, /role === "super_admin" \|\| role === "admin"/);
  assert.match(rules, /minimumRole === "owner" \|\| minimumRole === "super_admin"/);
  assert.match(read("app/api/admin/team/route.ts"), /z\.enum\(\["super_admin", "admin", "staff"\]\)/);
});

test("Digiflazz callbacks are paid-order-only, replay-safe, and monotonic", () => {
  const callback = read("lib/server/orders.ts").slice(read("lib/server/orders.ts").indexOf("export async function applyProviderWebhook"));
  assert.match(callback, /order\.payment_status !== "paid"/);
  assert.match(callback, /Number\(recorded\.meta\.changes \?\? 0\) === 0/);
  assert.match(callback, /order\.fulfillment_status === "success"/);
});

test("rate-limit identity only trusts Cloudflare connecting IP", () => {
  const security = read("lib/server/security.ts");
  assert.match(security, /cf-connecting-ip/);
  assert.doesNotMatch(security, /x-forwarded-for/);
});
