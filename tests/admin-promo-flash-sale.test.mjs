import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-operations-workspaces.tsx"), "utf8");

test("flash sale can be managed from the admin promo workspace", () => {
  for (const label of ["Tambah Flash Sale", "Daftar Flash Sale", "Simpan Flash Sale"]) {
    assert.ok(source.includes(label), `missing flash sale control: ${label}`);
  }
  assert.match(source, /kind: "flash"/);
  assert.match(source, /setEditingFlash/);
  assert.match(source, /kind=flash/);
});
