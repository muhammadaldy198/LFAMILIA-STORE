import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("voucher checkout is handled directly by React", () => {
  const checkout = read("app/checkout/page.tsx");
  const layout = read("app/checkout/layout.tsx");

  assert.match(checkout, /const INTERNAL_VOUCHER_DESTINATION = "00000000"/);
  assert.match(checkout, /const normalizedCategory = normalizeProductCategorySlug\(product\.category\)/);
  assert.match(checkout, /const isVoucherProduct = normalizedCategory === "voucher"/);
  assert.match(checkout, /!isVoucherProduct && \(/);
  assert.match(checkout, /number=\{isVoucherProduct \? "1" : "2"\}/);
  assert.match(checkout, /isVoucherProduct && index === 0/);
  assert.match(checkout, /Pastikan produk, nominal, dan pembayaran yang kamu pilih sudah sesuai/);
  assert.doesNotMatch(layout, /CheckoutVoucherMode/);
  assert.equal(
    fs.existsSync(path.join(root, "components/checkout-voucher-mode.tsx")),
    false,
  );
});

test("voucher checkout does not require hidden account fields", () => {
  const checkout = read("app/checkout/page.tsx");
  const confirmation = checkout.slice(
    checkout.indexOf("function requestConfirmation"),
    checkout.indexOf("async function submitOrder"),
  );
  const submitStart = checkout.indexOf("async function submitOrder");
  const submit = checkout.slice(
    submitStart,
    checkout.indexOf("\n  return (\n    <StoreLayout>", submitStart),
  );

  assert.match(confirmation, /!isVoucherProduct/);
  assert.match(submit, /!isVoucherProduct/);
  assert.match(submit, /destination: destination\.trim\(\)/);
  assert.match(submit, /INTERNAL_VOUCHER_DESTINATION/);
});
