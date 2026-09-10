import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("admin shell gives every enabled frontend button active feedback", () => {
  const dashboard = read("components/admin-dashboard.tsx");
  assert.match(dashboard, /onClick=\{confirmUiAction\}/);
  assert.match(dashboard, /closest\("button"\)/);
  assert.match(dashboard, /uiNotice/);
});

test("standalone admin controls remain interactive without a supplied handler", () => {
  const ui = read("components/admin-workspace-ui.tsx");
  const products = read("components/admin-product-manager.tsx");
  assert.match(ui, /setLocalValue/);
  assert.match(ui, /announceAdminAction/);
  assert.match(products, /onClick \?\? \(\(\) => announceAdminAction/);
});
