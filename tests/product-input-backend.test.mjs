import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/product-input/route.ts"), "utf8");
const panel = fs.readFileSync(path.join(root, "app/api/panel/[...path]/route.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-product-manager.tsx"), "utf8");

test("product input endpoint derives checkout fields and provider target on the server", () => {
  assert.match(route, /checkoutType: z\.enum\(\["id", "id-server"\]\)/);
  assert.match(route, /id: "destination"/);
  assert.match(route, /id: "server"/);
  assert.match(route, /needsServer \? "\{\{destination\}\}\{\{server\}\}" : "\{\{destination\}\}"/);
  assert.match(route, /requireAdminSession\(request, "owner"\)/);
  assert.doesNotMatch(route, /nicknameRequired|nicknameOptional/);
});

test("panel exposes product-input and editor persists real values", () => {
  assert.match(panel, /"product-input": \{ GET: productInput\.GET, PATCH: productInput\.PATCH \}/);
  assert.match(manager, /fetch\(`\/api\/panel\/product-input\?slug=/);
  assert.match(manager, /fetch\("\/api\/panel\/product-input", \{/);
  assert.match(manager, /method: "PATCH"/);
  assert.match(manager, /Checkout Type dan label input berhasil disimpan ke backend/);
});
