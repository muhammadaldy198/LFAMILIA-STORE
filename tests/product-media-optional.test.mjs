import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const media = fs.readFileSync(path.join(root, "lib/media-url.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-product-manager.tsx"), "utf8");

test("product media validation accepts blank and local product assets", () => {
  assert.match(media, /if \(!value\) return true/);
  assert.match(media, /\(\?:brand\|products\)/);
});

test("admin labels product images as optional", () => {
  assert.match(manager, /Gambar produk \(opsional, rasio 1:1\)/);
  assert.match(manager, /Boleh dikosongkan dan ditambahkan nanti/);
  assert.match(manager, /Banner halaman produk \(opsional\)/);
});
