import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-dashboard.tsx"), "utf8");

test("admin v3 keeps a persistent dark desktop sidebar and responsive mobile drawer", () => {
  assert.match(source, /admin-v3-sidebar/);
  assert.match(source, /min-h-\[calc\(100vh-3\.5rem\)\]/);
  assert.match(source, /fixed inset-y-0 left-0/);
  assert.match(source, /lg:hidden/);
});

test("admin v3 exposes the simplified operational information architecture", () => {
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
