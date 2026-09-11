import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const components = [
  "admin-customer-workspace.tsx",
  "admin-digiflazz-workspace.tsx",
  "admin-experience-manager.tsx",
  "admin-integration-workspace.tsx",
  "admin-notifications.tsx",
  "admin-operations-workspaces.tsx",
  "admin-order-manager.tsx",
  "admin-overview.tsx",
  "admin-payment-workspace.tsx",
  "admin-product-manager.tsx",
];

test("every admin panel endpoint used by the frontend is dispatched by the secured panel adapter", () => {
  const adapter = read("app/api/panel/[...path]/route.ts");
  const used = new Set();
  for (const component of components) {
    const source = read(path.join("components", component));
    for (const match of source.matchAll(/\/api\/panel\/([a-z-]+(?:\/[a-z-]+)?)/g)) used.add(match[1]);
  }
  assert.ok(used.size > 0, "no panel endpoints found in admin components");
  for (const endpoint of used) {
    const key = /[-/]/.test(endpoint) ? `"${endpoint}"` : endpoint;
    assert.match(adapter, new RegExp(`\\n  ${key.replace("/", "\\/")}: \\{`), `missing panel adapter route: ${endpoint}`);
  }
  assert.match(adapter, /requireAdminSession|recordAdminActivity|rejectCrossOriginMutation/);
});
