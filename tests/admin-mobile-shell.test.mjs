import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const dashboard = fs.readFileSync(path.join(root, "components/admin-dashboard.tsx"), "utf8");
const ui = fs.readFileSync(path.join(root, "components/admin-workspace-ui.tsx"), "utf8");

test("admin shell provides a mobile drawer without removing the desktop layout", () => {
  assert.match(dashboard, /mobileNavigationOpen/);
  assert.match(dashboard, /aria-label="Buka menu admin"/);
  assert.match(dashboard, /lg:grid-cols-\[230px_minmax\(0,1fr\)\]/);
  assert.match(dashboard, /-translate-x-full/);
  assert.match(dashboard, /translate-x-0/);
  assert.match(dashboard, /max-width: 1023px/);
});

test("shared admin controls adapt to narrow screens", () => {
  assert.match(ui, /flex-col items-start justify-between/);
  assert.match(ui, /overflow-x-auto border-b/);
  assert.match(ui, /place-items-end/);
  assert.match(ui, /rounded-t-xl/);
});
