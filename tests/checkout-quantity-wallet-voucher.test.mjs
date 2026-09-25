import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("Digiflazz imports compact nominal labels without game names", () => {
  const manager = read("components/admin-product-manager.tsx");

  assert.match(manager, /function digiflazzNominalLabel/);
  assert.match(manager, /name: digiflazzNominalLabel\(item\.productName, item\.brand\)/);
  assert.match(manager, /const tail = separated\.at\(-1\) \|\| value/);
  assert.match(manager, /return numericTail\?\.\[1\]\?\.trim\(\) \|\| value/);
});

test("checkout exposes LFAMILIA Cash, quantity control, and separate promo step", () => {
  const checkout = read("app/checkout/page.tsx");

  assert.match(checkout, /name: "LFAMILIA Cash"/);
  assert.match(checkout, /\/payment\/lfamilia-cash\.webp/);
  assert.match(checkout, /title="Jumlah Pembelian"/);
  assert.match(checkout, /function changeQuantity\(next: number\)/);\n  assert.match(checkout, /onClick=\{\(\) => changeQuantity\(quantity \+ 1\)\}/);
  assert.match(checkout, /title="Data Pembeli"/);
  assert.match(checkout, /title="Kode Promo"/);
  assert.doesNotMatch(checkout, /title="Data Pembeli & Voucher"/);
  assert.match(checkout, /quantity,/);
});

test("promotion and payment APIs validate and persist quantity", () => {
  const quote = read("app/api/promotions/quote/route.ts");
  const auto = read("app/api/payments/auto/create/route.ts");
  const wallet = read("app/api/payments/wallet/create/route.ts");
  const orders = read("lib/server/orders.ts");

  assert.match(quote, /quantity: z\.number\(\)\.int\(\)\.min\(1\)\.max\(5\)\.default\(1\)/);
  assert.match(auto, /quantity: z\.number\(\)\.int\(\)\.min\(1\)\.max\(5\)\.default\(1\)/);
  assert.match(wallet, /quantity: z\.number\(\)\.int\(\)\.min\(1\)\.max\(5\)\.default\(1\)/);
  assert.match(orders, /quantity INTEGER|quantity\) VALUES|customer_inputs_json, quantity/);
  assert.match(orders, /order_fulfillment_units/);
  assert.match(orders, /fulfillmentUnitReference/);
});

test("wallet public labels use LFAMILIA Cash consistently", () => {
  const publicPayment = read("lib/public-payment.ts");
  const paymentPage = read("app/payment/page.tsx");
  const wallet = read("app/api/payments/wallet/create/route.ts");

  assert.match(publicPayment, /return "LFAMILIA Cash"/);
  assert.match(paymentPage, /return "LFAMILIA Cash"/);
  assert.match(wallet, /paymentName: "LFAMILIA Cash"/);
});
