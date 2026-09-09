import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const orders = fs.readFileSync(path.join(root, "lib/server/orders.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-product-manager.tsx"), "utf8");
const route = fs.readFileSync(path.join(root, "app/api/admin/products/route.ts"), "utf8");

test("all custom customer fields can be mapped into provider customer_no", () => {
  assert.match(orders, /tokens\.set\(input\.id\.trim\(\)\.toLowerCase\(\), input\.value\.trim\(\)\)/);
  assert.match(orders, /replace\(\/\\\{\\\{\(\[a-z0-9-\]\+\)\\\}\\\}\/gi/);
  assert.match(orders, /input\.customerInputs/);
});

test("admin automatically builds a provider target from every custom field", () => {
  assert.match(manager, /inputFields\.map\(\(item\) => `\{\{\$\{item\.id\}\}\}`\)\.join\(" "\)|inputFields\.map\(\(item\) => `\{\{\$\{item\.id\}\}\}`\)\.join\(""/);
  assert.match(manager, /Format tujuan DigiFlazz/);
  assert.match(manager, /DigiFlazz Buyer menerima satu customer_no/);
});

test("unknown provider target tokens are rejected by admin API", () => {
  assert.match(route, /Token tujuan provider/);
  assert.match(route, /allowedTokens/);
});
