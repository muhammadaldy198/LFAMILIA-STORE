import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const provider = fs.readFileSync(
  path.join(root, "lib/server/providers/digiflazz.ts"),
  "utf8",
);
const orders = fs.readFileSync(
  path.join(root, "lib/server/orders.ts"),
  "utf8",
);
const admin = fs.readFileSync(
  path.join(root, "components/admin-product-manager.tsx"),
  "utf8",
);

test("DigiFlazz Buyer request follows the official topup contract", () => {
  for (const field of [
    "username",
    "buyer_sku_code",
    "customer_no",
    "ref_id",
    "sign",
    "testing",
    "max_price",
    "cb_url",
  ]) {
    assert.match(provider, new RegExp(field));
  }
  assert.match(provider, /allow_dot/);
  assert.match(provider, /order\.customerNo\.includes\("\."\)/);
});

test("custom customer fields are rendered into one DigiFlazz customer_no", () => {
  assert.match(orders, /customerInputs: CustomerInputValue\[\]/);
  assert.match(orders, /tokens\.set\(input\.id\.trim\(\)\.toLowerCase\(\), input\.value\.trim\(\)\)/);
  assert.match(orders, /replace\(\/\\\{\\\{\(\[a-z0-9-\]\+\)\\\}\\\}\/gi/);
  assert.match(orders, /input\.customerInputs/);
});

test("admin can configure DigiFlazz customer_no using dynamic input tokens", () => {
  assert.match(admin, /Format customer_no/);
  assert.match(admin, /Backend menggabungkan data ini saat mengirim pesanan otomatis/);
  assert.match(admin, /targetTemplate/);
});
