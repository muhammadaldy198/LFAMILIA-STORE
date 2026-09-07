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
  assert.match(route, /gateway === "ipaymu"/);
  assert.match(route, /createMidtransPayment\(/);
});

test("customer topup UI does not ask which gateway to use", () => {
  assert.doesNotMatch(account, /setGateway\(/);
  assert.doesNotMatch(account, /mode: activeGateway/);
  assert.match(account, /Gateway dipilih otomatis berdasarkan nominal dan metode pembayaran/);
});
