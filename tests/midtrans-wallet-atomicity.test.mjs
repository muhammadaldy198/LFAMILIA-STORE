import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const wallet = fs.readFileSync(path.join(root, "lib/server/wallet.ts"), "utf8");
const section = wallet.slice(
  wallet.indexOf("export async function applyMidtransWalletTopup"),
  wallet.indexOf("export async function createIpaymuWalletTopup"),
);

test("Midtrans wallet callback requires exact amount and transaction identity", () => {
  assert.match(section, /midtrans_transaction_id !== input\.transactionId/);
  assert.match(section, /!Number\.isFinite\(input\.callbackAmount\)/);
  assert.match(section, /input\.callbackAmount <= 0/);
  assert.match(section, /input\.callbackAmount !== topup\.amount/);
});

test("Midtrans wallet callback reports credit only from committed D1 writes", () => {
  assert.match(section, /const results = await db\.batch\(\[/);
  assert.match(section, /results\[0\]\?\.meta\.changes/);
  assert.match(section, /results\[1\]\?\.meta\.changes/);
  assert.match(section, /credited: inserted && approved/);
  assert.doesNotMatch(section, /credited: topup\.status === "pending"/);
});
