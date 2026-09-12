import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("Stage 2 dashboard separates Super Admin, Admin, and Staff navigation", () => {
  const source = read("components/admin-dashboard.tsx");
  assert.match(source, /const roleRank = \{ staff: 0, admin: 1, super_admin: 2 \}/);
  assert.match(source, /minimumRole: "staff" \| "admin" \| "super_admin"/);
  assert.match(source, /value: "team"[\s\S]*minimumRole: "super_admin"/);
  assert.match(source, /value: "products"[\s\S]*minimumRole: "admin"/);
  assert.match(source, /initialSession\.role === "staff" && <StaffProductContentWorkspace/);
});

test("Staff product-content is safe and routed through the panel proxy", () => {
  const route = read("app/api/admin/product-content/route.ts");
  const proxy = read("app/api/panel/[...path]/route.ts");
  const workspace = read("components/staff-product-content-workspace.tsx");
  assert.match(route, /export async function GET\(request: Request\)/);
  assert.match(route, /requireAdminSession\(request, "staff"\)/);
  assert.match(route, /products: products\.map/);
  assert.doesNotMatch(route, /providerCode|providerSku|supplierPrice|marginValue/);
  assert.match(proxy, /"product-content": \{ GET: productContent\.GET, PUT: productContent\.PUT \}/);
  assert.match(workspace, /fetch\("\/api\/panel\/product-content"/);
  assert.match(workspace, /method: "PUT"/);
});

test("super-admin-only controls remain backend guarded", () => {
  for (const file of ["balances", "doku-database", "integrations", "team", "wallet"]) {
    assert.match(read(`app/api/admin/${file}/route.ts`), /requireAdminSession\(request, "owner"\)/);
  }
  assert.match(read("app/api/admin/members/route.ts"), /requireAdminSession\(request, "admin"\)/);
  assert.match(read("app/api/admin/members/route.ts"), /access\.role !== "super_admin"/);
});

test("public customer APIs omit provider metadata and environment", () => {
  const catalog = read("app/api/products/route.ts");
  const methods = read("app/api/payment-methods/route.ts");
  assert.doesNotMatch(catalog, /providerCode: pkg\.providerCode/);
  assert.doesNotMatch(catalog, /providerSku/);
  assert.doesNotMatch(catalog, /targetTemplate:/);
  assert.doesNotMatch(methods, /environment:/);
  assert.doesNotMatch(methods, /getDokuEnvironment/);
});
