import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/account/topups/route.ts"), "utf8");
const account = fs.readFileSync(path.join(root, "components/customer-account.tsx"), "utf8");

test("wallet topup uses DOKU only on the server", () => {
  assert.match(route, /createDokuWalletTopup\(/);
  assert.match(route, /createDokuCheckoutPayment\(/);
  assert.match(route, /updateDokuWalletTopup\(/);
  assert.match(route, /paymentGateway: "doku"/);
  assert.doesNotMatch(route, /midtrans|ipaymu|fallback/i);
});

test("customer topup UI does not ask which gateway to use", () => {
  assert.doesNotMatch(account, /setGateway\(|activeGateway/);
  assert.match(account, /DOKU adalah satu-satunya payment gateway/);
});

test("customer topup opens the DOKU hosted payment page", () => {
  assert.match(account, /window\.location\.assign\(payment\.paymentUrl\)/);
  assert.match(account, /Bayar melalui DOKU/);
  assert.doesNotMatch(account, /snap\.pay|midtrans|ipaymu/i);
});
