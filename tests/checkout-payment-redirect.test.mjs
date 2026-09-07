import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const payment = fs.readFileSync(path.join(root, "app/payment/page.tsx"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const ipaymu = fs.readFileSync(path.join(root, "app/api/payments/ipaymu/create/route.ts"), "utf8");
const midtrans = fs.readFileSync(path.join(root, "app/api/payments/midtrans/create/route.ts"), "utf8");
const invoiceReminder = fs.readFileSync(path.join(root, "components/checkout-invoice-reminder.tsx"), "utf8");
const accountRoute = fs.readFileSync(path.join(root, "app/api/account/route.ts"), "utf8");

test("automatic checkout identifies the selected payment gateway", () => {
  assert.match(autoRoute, /paymentGateway: "ipaymu"/);
  assert.match(autoRoute, /paymentGateway: "midtrans"/);
});

test("legacy provider create endpoints cannot force a gateway", () => {
  for (const source of [ipaymu, midtrans]) {
    assert.match(source, /createAutomaticCheckout\(request\)/);
    assert.doesNotMatch(source, /createOrderIdentity|insertPendingOrder/);
  }
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
  for (const source of [autoRoute, invoiceReminder, accountRoute]) {
    assert.match(source, /if \(!clean\.includes\("-"\)\) return clean/);
  }
});
