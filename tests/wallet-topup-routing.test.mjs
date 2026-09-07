import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/account/topups/route.ts"), "utf8");
const account = fs.readFileSync(path.join(root, "components/customer-account.tsx"), "utf8");

test("wallet topup gateway is selected on the server", () => {
  assert.match(route, /routePaymentGateway\(/);
  assert.doesNotMatch(route, /mode: z\.enum\(\["midtrans", "ipaymu"\]\)/);
  assert.match(route, /primary === "ipaymu"/);
  assert.match(route, /createMidtransPayment\(/);
});

test("wallet topup reuses one record across safe gateway fallback", () => {
  assert.equal((route.match(/createAutomaticWalletTopup\(/g) ?? []).length, 1);
  assert.match(route, /IpaymuProviderError/);
  assert.match(route, /error\.safeToFallback/);
  assert.match(route, /switchAutomaticWalletTopupGateway\(/);
  assert.match(route, /fallback !== "midtrans"/);
  assert.doesNotMatch(route, /createIpaymuWalletTopup|createMidtransWalletTopup/);
});

test("customer topup UI does not ask which gateway to use", () => {
  assert.doesNotMatch(account, /setGateway\(/);
  assert.doesNotMatch(account, /mode: activeGateway/);
  assert.match(account, /Gateway dipilih otomatis berdasarkan nominal dan metode pembayaran/);
});

test("wallet topup stays on LFAMILIA payment UI", () => {
  assert.doesNotMatch(account, /if \(data\.paymentUrl.*window\.location\.assign/s);
  assert.match(account, /payment\.paymentGateway === "ipaymu"/);
  assert.match(account, /payment\.paymentMethod === "qris"/);
  assert.match(account, /snap\.pay\(token/);
  assert.match(account, /QRIS dan Virtual Account tetap ditampilkan di LFAMILIA/);
});
