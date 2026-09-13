import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const wallet = fs.readFileSync(path.join(root, "lib/server/wallet.ts"), "utf8");
const schema = fs.readFileSync(path.join(root, "db/schema.ts"), "utf8");
const section = wallet.slice(wallet.indexOf("export async function applyDokuWalletTopup"));

test("DOKU wallet callback requires exact amount and original request identity", () => {
  assert.match(section, /topup\.doku_request_id/);
  assert.match(section, /input\.originalRequestId/);
  assert.match(section, /input\.callbackAmount !== topup\.amount/);
  assert.match(section, /!Number\.isFinite\(input\.callbackAmount\)/);
});

test("DOKU wallet credit is derived from committed D1 writes", () => {
  assert.match(section, /const results = await db\.batch\(\[/);
  assert.match(section, /results\[0\]\?\.meta\.changes/);
  assert.match(section, /results\[1\]\?\.meta\.changes/);
  assert.match(section, /if \(inserted && approved\)/);
  assert.match(section, /EXISTS \(SELECT 1 FROM wallet_transactions WHERE reference = \?\)/);
});

test("late DOKU wallet paid status cannot credit an expired topup", () => {
  assert.match(section, /t\.doku_expired_at IS NULL OR datetime\(t\.doku_expired_at\) > datetime\('now'\)/);
  assert.match(section, /datetime\(doku_expired_at\) <= datetime\('now'\)/);
  assert.match(section, /ignored: "expired"/);
});

test("wallet ledger keeps a unique reference for callback idempotency", () => {
  assert.match(schema, /wallet_transactions_reference_unique/);
});
