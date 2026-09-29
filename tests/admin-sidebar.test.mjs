import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-dashboard.tsx"), "utf8");
const overview = fs.readFileSync(path.join(process.cwd(), "components/admin-overview.tsx"), "utf8");
const summaryClient = fs.readFileSync(path.join(process.cwd(), "lib/client/admin-summary.ts"), "utf8");

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
    "Validasi Akun",
    "Pembayaran",
    "Pelanggan",
    "Promo",
    "Layanan Pelanggan",
    "Laporan",
    "Staff & Admin Akses",
    "Pengaturan",
    "Integrasi",
  ]) assert.ok(source.includes(`label: "${label}"`), label);
  assert.doesNotMatch(source, /value: "site-content"/);
  assert.equal([...source.matchAll(/\{ value: "[^"]+", label: "[^"]+"/g)].length, 14);
  assert.ok(source.indexOf('label: "Pengaturan"') < source.indexOf('label: "Integrasi"'));
});

test("dashboard uses live summary data and labels sales ranking accurately", () => {
  assert.match(overview, /fetchAdminSummary<Summary>\(range\)/);
  assert.match(summaryClient, /\/api\/admin\/summary\?range=/);
  assert.match(overview, /summary\?\.recentOrders/);
  assert.match(overview, /summary\?\.topProducts/);
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
    "Produk Terlaris",
  ]) assert.ok(overview.includes(label), label);
  assert.doesNotMatch(overview, /const featureCards =/);
});
