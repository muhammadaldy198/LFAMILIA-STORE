import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-product-manager.tsx"), "utf8");

test("products page keeps the real product list controls without duplicate nominal action", () => {
  for (const label of [
    "Kelola semua produk top up, voucher, dan layanan digital.",
    "Tambah Produk",
    "Semua Kategori",
    "Semua Provider",
    "Semua Status",
    "Total Nominal",
    "Harga Mulai",
    "Ditampilkan",
    "Terakhir Update",
  ]) assert.ok(source.includes(label), `missing products label: ${label}`);
  assert.doesNotMatch(source, />Kelola Nominal<\/button>/);
  assert.doesNotMatch(source, />Nominal<\/button>/);
});

test("product creation is manual and DigiFlazz imports nominal only", () => {
  assert.match(source, /Semua produk dibuat sendiri/);
  assert.match(source, /Produk tetap dibuat manual\. Hanya nominal terpilih yang diambil dari Digiflazz/);
  assert.match(source, /provider: "Digiflazz" as const/);
  assert.doesNotMatch(source, /Import Produk dari Digiflazz/);
  assert.match(source, /fetch\("\/api\/admin\/products"/);
  assert.match(source, /method: "POST"/);
  assert.match(source, /method: "PATCH"/);
});

test("editor includes real nominal separator and reordering controls without fake preview", () => {
  for (const label of [
    "Daftar Nominal",
    "Tambah dari Digiflazz",
    "Tambah Manual",
    "Sync Harga",
    "Atur Margin Massal",
    "Tabel Pemisah Nominal",
    "Tambah Tabel Pemisah",
    "Lihat di Toko",
    "Grup / Tabel",
  ]) assert.ok(source.includes(label), `missing editor label: ${label}`);

  assert.doesNotMatch(source, />Upload Gambar Nominal<\/ActionButton>/);
  assert.doesNotMatch(source, />Atur Urutan<\/ActionButton>/);
  assert.doesNotMatch(source, /function StorePreview/);
  assert.match(source, /draggable/);
  assert.match(source, /moveNominal/);
  assert.match(source, /moveSection/);
  assert.match(source, /moveBefore/);
  assert.match(source, /aria-label="Naik"|label="Naik"/);
  assert.match(source, /aria-label="Turun"|label="Turun"/);
});

test("product input editor controls checkout ID labels and customer_no mapping", () => {
  for (const label of [
    "Checkout Type",
    "ID + Server",
    "Label ID",
    "Label Server",
    "Kode Game Nickname",
    "Preview Input Checkout",
    "Format customer_no",
  ]) assert.ok(source.includes(label), `missing customer input UI: ${label}`);
  assert.match(source, /\{ id: "destination", label: labelId \}/);
  assert.match(source, /\{ id: "server", label: labelServer \}/);
  assert.doesNotMatch(source, /nicknameRequired|nicknameOptional/);
});

test("product controls persist instead of showing frontend-only simulations", () => {
  assert.match(source, /loadProducts/);
  assert.match(source, /saveProductChanges/);
  assert.match(source, /toggleProduct/);
  assert.match(source, /Nominal dan tabel pemisah berhasil disimpan ke database/);
  assert.doesNotMatch(source, /disimpan sementara di frontend|endpoint khusus berikutnya/);
});
