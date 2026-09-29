import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-operations-workspaces.tsx"), "utf8");

test("Promo panel manages vouchers and customer Popular Now instead of obsolete Flash Sale UI", () => {
  assert.match(source, /title="Voucher Diskon"/);
  assert.match(source, /title="Populer sekarang"/);
  assert.match(source, /Prioritas Populer/);
  assert.match(source, /body: JSON\.stringify\(\{ \.\.\.item, popular \}\)/);
  assert.doesNotMatch(source, /Tambah Flash Sale|Daftar Flash Sale|Simpan Flash Sale/);
  assert.doesNotMatch(source, /function FlashSaleModal/);
  assert.doesNotMatch(source, /kind: "flash"/);
});
