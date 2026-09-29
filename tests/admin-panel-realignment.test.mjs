import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("admin navigation and ownership are aligned without duplicate category management", () => {
  const dashboard = read("components/admin-dashboard.tsx");
  const settingsIndex = dashboard.indexOf('{ value: "settings", label: "Pengaturan"');
  const integrationsIndex = dashboard.indexOf('{ value: "integrations", label: "Integrasi"');

  assert.ok(settingsIndex > 0 && integrationsIndex > settingsIndex);
  assert.match(
    dashboard,
    /value="products"[\s\S]*?<AdminProductManager \/><AdminHomepageCategoryManager \/>/,
  );
  assert.doesNotMatch(
    dashboard,
    /value="content"[\s\S]*?<AdminHomepageCategoryManager \/>/,
  );
});

test("admin overview does not advertise static feature claims as completed functionality", () => {
  const overview = read("components/admin-overview.tsx");
  assert.doesNotMatch(overview, /const featureCards =/);
  assert.doesNotMatch(overview, /function FeatureCard/);
  assert.doesNotMatch(overview, /Produk Populer/);
  assert.match(overview, /Produk Terlaris/);
});

test("product manager has one real editor path and no simulated customer preview", () => {
  const products = read("components/admin-product-manager.tsx");
  assert.doesNotMatch(products, /"Tabel Pemisah"\s*\|/);
  assert.doesNotMatch(products, />Kelola Nominal<\/button>/);
  assert.doesNotMatch(products, />Atur Urutan<\/ActionButton>/);
  assert.doesNotMatch(products, />Upload Gambar Nominal<\/ActionButton>/);
  assert.doesNotMatch(products, /label="Ditampilkan di katalog"/);
  assert.doesNotMatch(products, /label="Produk populer"/);
  assert.doesNotMatch(products, /function StorePreview/);
  assert.doesNotMatch(products, /Top up Diamonds, Weekly Pass/);
  assert.match(products, /marginType: "percent", sell: Math\.ceil\(cost \+ cost \* margin \/ 100\)/);
  assert.match(products, /Lihat di Toko/);
});

test("promo workspace owns Popular Now instead of obsolete Flash Sale UI", () => {
  const operations = read("components/admin-operations-workspaces.tsx");
  assert.doesNotMatch(operations, /function FlashSaleModal/);
  assert.doesNotMatch(operations, /Daftar Flash Sale/);
  assert.match(operations, /title="Populer sekarang"/);
  assert.match(operations, /Status Populer sekarang gagal disimpan/);
  assert.match(operations, /body: JSON\.stringify\(\{ \.\.\.item, popular \}\)/);
});

test("settings only exposes real storefront settings and configuration export", () => {
  const operations = read("components/admin-operations-workspaces.tsx");
  assert.match(operations, /TabBar tabs=\{\["Toko", "Ekspor"\]\}/);
  assert.doesNotMatch(operations, /Riwayat Backup/);
  assert.doesNotMatch(operations, /tabs=\{\["Toko", "Notifikasi", "Keamanan", "Backup"\]\}/);
  assert.doesNotMatch(operations, /function SecurityRow/);
  assert.match(operations, /Ekspor Konfigurasi JSON/);
});

test("customer-facing previews use real data or the real storefront", () => {
  const payments = read("components/admin-payment-workspace.tsx");
  const content = read("components/admin-experience-manager.tsx");

  assert.doesNotMatch(payments, /Mobile Legends 86 Diamonds/);
  assert.doesNotMatch(payments, /Rp 20\.000/);
  assert.match(payments, /const previewOrder = paymentOrders\[0\] \?\? null/);

  assert.doesNotMatch(content, /function PreviewPanel/);
  assert.doesNotMatch(content, /action="Tambah Ulasan"/);
  assert.match(content, /Lihat Website Customer/);
});

test("Laravel content controller uses the real DB facade for FAQ deletion", () => {
  const controller = read("laravel/app/Http/Controllers/AdminContentController.php");
  assert.match(controller, /use Illuminate\\Support\\Facades\\DB;/);
  assert.match(controller, /DB::table\('faq_entries'\)->where\('id',\$id\)->delete\(\)/);
  assert.doesNotMatch(controller, /IlluminateSupportFacadesDB/);
});
