import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("customer payment fee supports fixed fee and percentage gross-up", () => {
  const source = read("lib/payment-fees.ts");
  assert.match(source, /customerFeeEnabled/);
  assert.match(source, /customerFeeBps/);
  assert.match(source, /customerFeeFixed/);
  assert.match(source, /10_000 - config\.customerFeeBps/);
  assert.match(source, /base = amount \+ config\.customerFeeFixed/);

  const grossUp = (amount, bps, fixed) =>
    Math.ceil(((amount + fixed) * 10_000) / (10_000 - bps)) - amount;
  assert.equal(grossUp(100_000, 70, 0), 705);
  assert.equal(grossUp(100_000, 0, 4_000), 4_000);
  assert.equal(grossUp(100_000, 150, 2_000), 3_554);
});

test("checkout and wallet topup both charge only the customer-facing final total", () => {
  const checkout = read("app/api/payments/auto/create/route.ts");
  const topup = read("app/api/account/topups/route.ts");
  assert.match(checkout, /calculateCustomerPaymentFee\(/);
  assert.match(checkout, /paymentTotal = promotion\.finalPrice \+ paymentFee/);
  assert.match(checkout, /adminFee: paymentFee/);
  assert.match(checkout, /amount: paymentTotal/);
  assert.match(topup, /calculateCustomerPaymentFee\(input\.amount, topupGatewayConfig\)/);
  assert.match(topup, /paymentTotal = input\.amount \+ paymentFee/);
  assert.match(topup, /amount: input\.amount/);
  assert.match(topup, /paymentFee,/);
  assert.match(topup, /paymentTotal,/);
});

test("public payment APIs expose fee details without exposing gateway identity", () => {
  for (const file of ["app/api/payment-methods/route.ts", "app/api/wallet/route.ts"]) {
    const source = read(file);
    assert.match(source, /publicCustomerPaymentFee/);
    assert.doesNotMatch(source, /gateway:\s*item\.gateway/);
    assert.doesNotMatch(source, /gatewayConfig:\s*item\.gatewayConfig/);
  }
});

test("admin can toggle and configure percent plus fixed customer fee per channel", () => {
  const ui = read("components/admin-payment-workspace.tsx");
  const route = read("app/api/admin/payment-methods/route.ts");
  for (const field of ["customerFeeEnabled", "customerFeeBps", "customerFeeFixed"]) {
    assert.ok(ui.includes(field), field);
    assert.ok(route.includes(field), field);
  }
  assert.match(ui, /Bebankan biaya gateway ke customer/);
  assert.match(ui, /gross-up/);
  assert.match(route, /99,99%/);
});

test("customer sees fee and total before checkout and before wallet topup payment", () => {
  const checkout = read("app/checkout/page.tsx");
  const account = read("components/customer-account.tsx");
  assert.match(checkout, /estimatedPaymentFee/);
  assert.match(checkout, /Biaya Pembayaran/);
  assert.match(checkout, /Total Bayar/);
  assert.match(account, /estimatedFee/);
  assert.match(account, /Saldo yang masuk/);
  assert.match(account, /Biaya pembayaran/);
  assert.match(account, /Total bayar/);
});
