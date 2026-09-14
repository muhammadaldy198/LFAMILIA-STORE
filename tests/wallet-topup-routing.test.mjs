import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/account/topups/route.ts"), "utf8");
const account = fs.readFileSync(path.join(root, "components/customer-account.tsx"), "utf8");

test("wallet topup follows the Admin-selected gateway and mode on the server", () => {
  assert.match(route, /getPaymentChannel\(/);
  assert.match(route, /getConfiguredGatewayReadiness\(/);
  assert.match(route, /createConfiguredPayment\(/);
  assert.match(route, /gateway: managedChannel\.gateway/);
  assert.match(route, /mode: readiness\.mode/);
  assert.match(route, /updateExternalWalletTopup\(/);
  assert.match(route, /idempotencyKey/);
  assert.doesNotMatch(route, /paymentGateway: "doku"/);
  assert.doesNotMatch(route, /ipaymu|fallback/i);
});

test("customer topup UI does not ask which gateway to use", () => {
  assert.doesNotMatch(account, /setGateway\(|activeGateway/);
  assert.match(account, /Pilih metode pembayaran yang ingin digunakan/);
  assert.doesNotMatch(account, /DOKU|DigiFlazz|Melostore|payment gateway|provider/);
});

test("customer topup renders native artifacts and can follow a hosted payment URL", () => {
  assert.match(account, /QRCodeSVG/);
  assert.match(account, /payment\.paymentNo/);
  assert.match(account, /window\.location\.assign\(payment\.paymentUrl\)/);
  assert.doesNotMatch(account, /hosted payment|snap\.pay|midtrans|ipaymu/i);
});
