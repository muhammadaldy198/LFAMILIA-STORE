import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/account/topups/route.ts"), "utf8");
const account = fs.readFileSync(path.join(root, "components/customer-account.tsx"), "utf8");
const routing = fs.readFileSync(path.join(root, "lib/server/payment-mode-config.ts"), "utf8");
const adminPayment = fs.readFileSync(path.join(root, "components/admin-payment-workspace.tsx"), "utf8");
const doku = fs.readFileSync(path.join(root, "lib/server/doku.ts"), "utf8");

test("wallet topup follows the dedicated Admin-selected gateway on the server", () => {
  assert.match(routing, /wallet_topup_gateway/);
  assert.match(routing, /walletTopupGateway/);
  assert.match(route, /getActivePaymentModes\(/);
  assert.match(route, /walletTopupGateway/);
  assert.match(route, /isPaymentGatewayActive\(walletTopupGateway\)/);
  assert.match(route, /getConfiguredGatewayReadiness\(/);
  assert.match(route, /gateway: walletTopupGateway/);
  assert.match(route, /createConfiguredPayment\(/);
  assert.match(route, /mode: readiness\.mode/);
  assert.match(route, /updateExternalWalletTopup\(/);
  assert.match(route, /idempotencyKey/);
  assert.doesNotMatch(route, /paymentGateway: "doku"/);
  assert.doesNotMatch(route, /ipaymu|fallback/i);
});

test("Admin payment UI has independent topup gateway selector and kill switches", () => {
  assert.match(adminPayment, /Gateway top up saldo/);
  assert.match(adminPayment, /walletTopupGateway/);
  assert.match(adminPayment, /DOKU Direct API/);
  assert.match(adminPayment, /Midtrans Snap/);
  assert.match(adminPayment, /Aktifkan top up saldo otomatis/);
  assert.match(adminPayment, /Gateway & Environment/);
  assert.match(adminPayment, /<Toggle checked=\{enabled\} onChange=\{onEnabled\}/);
  assert.doesNotMatch(adminPayment, /title="DOKU Checkout"/);
});

test("DOKU Direct e-wallet request sends the device ID header", () => {
  assert.match(doku, /deviceId: string/);
  assert.match(doku, /headers\["x-device-id"\] = input\.deviceId\.trim\(\)/);
  assert.match(doku, /deviceId: input\.deviceId/);
  assert.match(doku, /payment-host-to-host/);
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


test("public wallet settings shape matches customer topup UI and has no dummy checkout switch", () => {
  const publicWallet = fs.readFileSync(path.join(root, "app/api/wallet/route.ts"), "utf8");
  const walletServer = fs.readFileSync(path.join(root, "lib/server/wallet.ts"), "utf8");
  const adminWallet = fs.readFileSync(path.join(root, "app/api/admin/wallet/route.ts"), "utf8");
  const settingsWorkspace = fs.readFileSync(path.join(root, "components/admin-operations-workspaces.tsx"), "utf8");

  assert.match(publicWallet, /enabled: settings\.automaticTopupEnabled/);
  assert.match(account, /settings\?\.enabled/);
  assert.match(account, /settings\?\.minimumAmount/);
  assert.match(walletServer, /automaticTopupEnabled/);
  assert.doesNotMatch(walletServer, /dokuCheckoutEnabled/);
  assert.doesNotMatch(adminWallet, /dokuCheckoutEnabled|gatewayReadiness/);
  assert.doesNotMatch(settingsWorkspace, /tab === "Wallet"|Aktifkan checkout otomatis/);
  assert.match(adminPayment, /automaticTopupEnabled/);
});
