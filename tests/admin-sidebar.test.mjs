import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-dashboard.tsx"), "utf8");
const overview = fs.readFileSync(path.join(process.cwd(), "components/admin-overview.tsx"), "utf8");

test("admin reference keeps a persistent light desktop sidebar and responsive mobile drawer", () => {
  assert.ok(source.includes("border-r border-[#e7ebf2] bg-white"));
  assert.match(source, /fixed inset-y-0 left-0/);
  assert.match(source, /lg:hidden/);
  assert.match(source, /lfamilia-admin-logo\.webp/);
  assert.match(source, /Cari pesanan, produk, atau pelanggan/);
});

test("admin reference exposes the simplified operational information architecture", () => {
  for (const label of [
    "Dashboard",
    "Pesanan",
    "Produk",
    "Digiflazz",
    "Pembayaran",
    "Pelanggan",
    "Promo",
    "Konten",
    "Layanan Pelanggan",
    "Laporan",
    "Staff & Akses",
    "Pengaturan",
  ]) {
    assert.ok(source.includes(`label: "${label}"`), label);
  }
  assert.doesNotMatch(source, /label: "Pesanan realtime"|label: "Produk & nominal"|label: "Integrasi & harga"/);
});

test("dashboard reference contains the approved sections and quick actions", () => {
  for (const label of [
    "Tambah Produk",
    "Import Digiflazz",
    "Sync Harga",
    "Lihat Pesanan",
    "Omzet Hari Ini",
    "Profit Hari Ini",
    "Pesanan Hari Ini",
    "Pesanan Pending",
    "Pesanan Gagal",
    "Produk Aktif",
    "Saldo Digiflazz",
    "Grafik Penjualan",
    "Perlu Perhatian",
    "Pesanan Terbaru",
    "Produk Terlaris",
    "Aktivitas Terbaru",
  ]) {
    assert.ok(overview.includes(label), label);
  }
});
