import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-dashboard.tsx"), "utf8");
const overview = fs.readFileSync(path.join(process.cwd(), "components/admin-overview.tsx"), "utf8");

test("admin reference uses the approved dark desktop shell", () => {
  assert.match(source, /grid-cols-\[230px_minmax\(0,1fr\)\]/);
  assert.match(source, /bg-\[#112842\]/);
  assert.match(source, /LFAMILIA ADMIN/);
  assert.match(source, /Top Up Game Solution/);
  assert.match(source, /Cari menu, produk, pesanan, atau pelanggan/);
});

test("admin reference exposes the approved desktop information architecture", () => {
  for (const label of [
    "Dashboard",
    "Pesanan",
    "Produk",
    "Banner & Konten",
    "Digiflazz",
    "Pembayaran",
    "Pelanggan",
    "Promo",
    "Layanan Pelanggan",
    "Laporan",
    "Staff & Admin Akses",
    "Integrasi",
    "Pengaturan",
  ]) assert.ok(source.includes(`label: "${label}"`), label);
  assert.doesNotMatch(source, /value: "site-content"/);
  assert.equal([...source.matchAll(/\{ value: "[^"]+", label: "[^"]+"/g)].length, 13);
  assert.ok(source.indexOf('label: "Integrasi"') < source.indexOf('label: "Pengaturan"'));
});

test("dashboard is a backend-independent visual reference", () => {
  assert.doesNotMatch(overview, /fetch\(/);
  for (const label of [
    "Omzet Hari Ini",
    "Pesanan Hari Ini",
    "Produk Aktif",
    "Saldo Digiflazz",
    "Pembayaran Berhasil",
    "Grafik Penjualan",
    "Aktivitas Terbaru",
    "Status Integrasi",
    "Pesanan Terbaru",
    "Produk Populer",
  ]) assert.ok(overview.includes(label), label);
});
