import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const payment = fs.readFileSync(path.join(root, "app/payment/page.tsx"), "utf8");
const ipaymu = fs.readFileSync(path.join(root, "app/api/payments/ipaymu/create/route.ts"), "utf8");
const midtrans = fs.readFileSync(path.join(root, "app/api/payments/midtrans/create/route.ts"), "utf8");
const invoiceReminder = fs.readFileSync(path.join(root, "components/checkout-invoice-reminder.tsx"), "utf8");

test("provider create responses identify their payment gateway", () => {
  assert.match(ipaymu, /paymentGateway: "ipaymu"/);
  assert.match(midtrans, /paymentGateway: "midtrans"/);
});

test("external checkout always enters the LFAMILIA payment page", () => {
  assert.match(checkout, /\/payment\?invoice=/);
  assert.match(checkout, /encodeURIComponent\(invoice\)/);
  assert.doesNotMatch(checkout, /window\.location\.assign\(redirectUrl\.toString\(\)\)/);
});

test("iPaymu Direct payment is rendered by LFAMILIA", () => {
  assert.match(payment, /const isIpaymuQris/);
  assert.match(payment, /const isEmbeddedQr = isBisnapQris \|\| isIpaymuQris/);
  assert.match(payment, /order\.paymentMethod === "ewallet" && Boolean\(order\.paymentUrl\)/);
  assert.match(payment, /Detail pembayaran tetap ditampilkan di LFAMILIA/);
});

test("compact invoice references keep a single LF prefix", () => {
  for (const source of [ipaymu, midtrans, invoiceReminder]) {
    assert.match(source, /if \(!clean\.includes\("-"\)\) return clean/);
  }
});
