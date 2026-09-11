import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("admin shell only presents real action results", () => {
  const dashboard = read("components/admin-dashboard.tsx");
  assert.doesNotMatch(dashboard, /confirmUiAction|uiNotice|closest\("button"\)/);
  assert.match(dashboard, /submitGlobalSearch/);
});

test("shared controls require their owner to provide a real handler", () => {
  const ui = read("components/admin-workspace-ui.tsx");
  const products = read("components/admin-product-manager.tsx");
  assert.match(ui, /onChange\(value: boolean\): void/);
  assert.doesNotMatch(ui, /setLocalValue|announceAdminAction|EmptyButton/);
  assert.doesNotMatch(products, /onClick \?\?|announceAdminAction/);
});
