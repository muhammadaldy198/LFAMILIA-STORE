import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const read = (name) => fs.readFileSync(path.join(process.cwd(), "components", name), "utf8");
const dashboard = read("admin-dashboard.tsx");
const controls = read("admin-workspace-ui.tsx");
const products = read("admin-product-manager.tsx");

test("admin shell does not report button activity before an action completes", () => {
  assert.doesNotMatch(dashboard, /confirmUiAction|uiNotice|adminActionEvent/);
  assert.doesNotMatch(controls, /announceAdminAction|EmptyButton|localValue/);
  assert.match(controls, /onChange\(value: boolean\): void/);
  assert.doesNotMatch(products, /onClick \?\?|announceAdminAction/);
});
