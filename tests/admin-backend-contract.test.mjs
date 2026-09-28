[Reading 33 lines from start (total: 33 lines, 0 remaining)]

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

test("every admin endpoint used by the VPS frontend is exposed by Laravel", () => {
  const routes = read("laravel/routes/api.php");
  const used = new Set();
  for (const component of components) {
    const source = read(path.join("components", component));
    for (const match of source.matchAll(/\/api\/admin\/([a-z-]+(?:\/[a-z-]+)?)/g)) used.add(match[1]);
  }
  assert.ok(used.size > 0, "no Laravel admin endpoints found in admin components");
  for (const endpoint of used) {
    assert.ok(routes.includes("/admin/" + endpoint), "missing Laravel admin route: " + endpoint);
  }
  assert.match(routes, /AdminSessionController/);
});

[executed on device: server.lfamiliastore.my.id (6e813ea5-0449-4fcd-b21d-fddea1cea590)]