import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

function publicPaymentMap(source) {
  const start = source.indexOf(".map(({ item }) => ({");
  const end = source.indexOf("}));", start);
  assert.ok(start >= 0 && end > start);
  return source.slice(start, end);
}

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
  const responseMap = publicPaymentMap(methods);
  assert.doesNotMatch(catalog, /providerCode: pkg\.providerCode/);
  assert.doesNotMatch(catalog, /providerSku: pkg\.providerSku/);
  assert.doesNotMatch(catalog, /targetTemplate:/);
  assert.doesNotMatch(responseMap, /environment:/);
  assert.doesNotMatch(responseMap, /gateway:/);
  assert.doesNotMatch(responseMap, /doku:/);
});

test("Staff cannot read provider input templates, toggle packages, promotions, or provider order details", () => {
  assert.match(read("app/api/admin/product-input/route.ts"), /requireAdminSession\(request, "admin"\)/);
  assert.match(read("app/api/admin/product-package-status/route.ts"), /requireAdminSession\(request, "admin"\)/);
  assert.match(read("app/api/admin/promotions/route.ts"), /requireAdminSession\(request, "admin"\)/);
  const orders = read("app/api/admin/orders/route.ts");
  assert.match(orders, /if \(role !== "staff"\) return snapshot/);
  const visible = orders.slice(orders.indexOf("function visibleOrder"), orders.indexOf("export async function GET"));
  assert.match(visible, /delivery_mode: deliveryMode\(order\)/);
  assert.doesNotMatch(visible, /provider_code|provider_status|provider_message|provider_serial_number/);
});
