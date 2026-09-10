import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-product-manager.tsx"), "utf8");

test("products page matches the supplied desktop list reference", () => {
  for (const label of [
    "Kelola semua produk top up, voucher, dan layanan digital.",
    "Tambah Produk Manual",
    "Import Nominal Digiflazz",
    "Semua Kategori",
    "Semua Provider",
    "Semua Status",
    "Total Nominal",
    "Harga Mulai",
    "Ditampilkan",
    "Terakhir Update",
  ]) assert.ok(source.includes(label), `missing products label: ${label}`);
});

test("product creation is manual and DigiFlazz imports nominal only", () => {
  assert.match(source, /Semua produk dibuat sendiri/);
  assert.match(source, /Produk tetap dibuat manual\. Hanya nominal terpilih yang diambil dari Digiflazz/);
  assert.match(source, /provider: "Digiflazz" as const/);
  assert.doesNotMatch(source, /Import Produk dari Digiflazz/);
  assert.doesNotMatch(source, /fetch\s*\(/);
});

test("editor includes nominal, separators, reordering and store preview", () => {
  for (const label of [
    "Daftar Nominal",
    "Tambah dari Digiflazz",
    "Tambah Manual",
    "Upload Gambar Nominal",
    "Atur Urutan",
    "Sync Harga",
    "Atur Margin Massal",
    "Tabel Pemisah Nominal",
    "Tambah Tabel Pemisah",
    "Preview Tampilan di Toko",
    "Grup / Tabel",
  ]) assert.ok(source.includes(label), `missing editor label: ${label}`);

  assert.match(source, /draggable/);
  assert.match(source, /moveNominal/);
  assert.match(source, /moveSection/);
  assert.match(source, /moveBefore/);
  assert.match(source, /aria-label="Naik"|label="Naik"/);
  assert.match(source, /aria-label="Turun"|label="Turun"/);
});
