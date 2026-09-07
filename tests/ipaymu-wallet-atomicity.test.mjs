import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const wallet = fs.readFileSync(path.join(root, "lib/server/wallet.ts"), "utf8");
const ipaymuSection = wallet.slice(wallet.indexOf("export async function applyIpaymuWalletTopup"));

test("iPaymu wallet callback requires the exact top-up amount", () => {
  assert.match(ipaymuSection, /!Number\.isFinite\(input\.callbackAmount\)/);
  assert.match(ipaymuSection, /input\.callbackAmount <= 0/);
  assert.match(ipaymuSection, /input\.callbackAmount !== topup\.amount/);
});

test("iPaymu wallet callback reports credit only from committed D1 writes", () => {
  assert.match(ipaymuSection, /const results = await db\.batch\(\[/);
  assert.match(ipaymuSection, /results\[0\]\?\.meta\.changes/);
  assert.match(ipaymuSection, /results\[1\]\?\.meta\.changes/);
  assert.match(ipaymuSection, /credited: inserted && approved/);
  assert.doesNotMatch(ipaymuSection, /credited: topup\.status === "pending"/);
});

test("wallet ledger keeps a unique reference for callback idempotency", () => {
  const schema = fs.readFileSync(path.join(root, "db/schema.ts"), "utf8");
  assert.match(schema, /wallet_transactions_reference_unique/);
});
