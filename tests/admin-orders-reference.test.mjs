import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-order-manager.tsx"), "utf8");

test("orders page follows the supplied desktop reference structure", () => {
  for (const label of [
    "Kelola seluruh transaksi top up game, monitor status pembayaran dan proses pengiriman.",
    "Total Pesanan",
    "Pending",
    "Diproses",
    "Berhasil",
    "Gagal",
    "Komplain",
    "Auto Refresh Aktif",
    "Pesanan Manual",
    "Daftar Pesanan",
    "Aktivitas Terbaru",
    "Menampilkan 1–",
  ]) assert.ok(source.includes(label), `missing reference label: ${label}`);

  assert.match(source, /grid-cols-6/);
  assert.match(source, /grid-cols-\[minmax\(0,1fr\)_270px\]/);
  assert.match(source, /function DesktopOrderTable/);
});

test("orders table exposes all reference columns and local controls", () => {
  for (const label of [
    "ID Pesanan",
    "Pelanggan",
    "Produk",
    "Tujuan",
    "Pembayaran",
    "Provider",
    "Total",
    "Status",
    "Aksi",
    "Aksi massal",
    "Pilih semua",
    "Baris per halaman",
  ]) assert.ok(source.includes(label), `missing table control: ${label}`);

  assert.match(source, /type: "text\/csv;charset=utf-8"/);
  assert.match(source, /OrderDetailModal/);
  assert.match(source, /ManualOrderModal/);
});

test("orders UI loads and mutates backend data", () => {
  assert.match(source, /fetch\("\/api\/panel\/orders"/);
  assert.match(source, /method: "POST"/);
  assert.match(source, /method: "PATCH"/);
  assert.match(source, /complete_manual/);
  assert.match(source, /setInterval/);
  assert.doesNotMatch(source, /const initialOrders|12\.450 pesanan|Data tampilan berhasil diperbarui/);
});
