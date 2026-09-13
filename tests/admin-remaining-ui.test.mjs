import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const read = (name) => fs.readFileSync(path.join(process.cwd(), "components", name), "utf8");
const dashboard = read("admin-dashboard.tsx");
const payment = read("admin-payment-workspace.tsx");
const customer = read("admin-customer-workspace.tsx");
const balance = read("admin-balance-manager.tsx");
const integration = read("admin-integration-workspace.tsx");
const operations = read("admin-operations-workspaces.tsx");
const ui = read("admin-workspace-ui.tsx");

test("remaining admin menus use the new desktop workspaces", () => {
  for (const component of ["AdminPaymentWorkspace", "AdminCustomerWorkspace", "AdminPromoWorkspace", "AdminSupportWorkspace", "AdminReportsWorkspace", "AdminTeamWorkspace", "AdminIntegrationWorkspace", "AdminSettingsWorkspace"]) {
    assert.ok(dashboard.includes(`<${component}`), `dashboard does not use ${component}`);
  }
});

test("DOKU payment UI includes channels and editable payment page images", () => {
  for (const label of ["DOKU Direct API", "QRIS DOKU", "Virtual Account BCA", "Tampilan Halaman", "Editor Halaman Pembayaran", "Ganti Gambar", "Preview Halaman Pembayaran"]) assert.ok(payment.includes(label), `missing payment UI: ${label}`);
  assert.ok(payment.includes("image/png,image/jpeg,image/webp"));
});

test("Super Admin can design balance changes for customer or admin accounts", () => {
  for (const label of ["Atur Saldo", "Pelanggan", "Admin", "Tambah", "Kurangi", "Simpan Perubahan Saldo", "audit log"]) assert.ok(balance.includes(label), `missing balance UI: ${label}`);
  assert.match(customer, /<AdminBalanceManager \/>/);
});

test("integration UI contains credentials, copyable provider URLs, and relay", () => {
  for (const label of ["DOKU Notification URL", "Digiflazz Webhook URL", "Melostore Nickname", "API Keys Check Nickname", "API Key", "Secret Key", "Relay Token", "Webhook Secret", "digiflazz-relay@lfamilia.my.id", "https://digiflazz-relay.lfamiliastore.my.id"]) assert.ok(integration.includes(label), `missing integration UI: ${label}`);
  assert.ok(integration.includes("https://lfamiliastore.my.id/api/payments/doku/callback"));
  assert.ok(integration.includes("https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback"));
  assert.ok(ui.includes("navigator.clipboard.writeText"));
  assert.doesNotMatch(integration, /SwitchLine label="(?:Wajib|Tidak Wajib).*nickname/i);
  assert.match(integration, /Periksa Konfigurasi Relay/);
  assert.doesNotMatch(integration, /Tes Relay Sekarang/);
});

test("promo, support, reports, team, and settings are fully represented", () => {
  for (const label of ["Tambah Promo", "Daftar Tiket", "Grafik Penjualan", "Hak Akses Role", "Audit Aktivitas Admin", "Logo & Ikon", "Aturan Wallet Pelanggan", "Keamanan Transaksi", "Riwayat Backup"]) assert.ok(operations.includes(label), `missing remaining UI: ${label}`);
});

test("all remaining workspaces use real panel APIs", () => {
  assert.match(integration, /fetch\("\/api\/panel\/integrations"/);
  assert.match(integration, /fetch\("\/api\/nickname"/);
  assert.doesNotMatch(integration, /Simulasi tes|UI sementara/);
  for (const endpoint of ["payment-methods", "payment-page", "wallet", "media"]) assert.ok(payment.includes(`/api/panel/${endpoint}`), `payment does not use ${endpoint}`);
  assert.doesNotMatch(payment, /Simulasi UI|backend dikerjakan/);
  assert.match(balance, /requestJson<BalancePayload>\("\/api\/panel\/balances"/);
  assert.doesNotMatch(balance, /dicatat pada UI/);
  for (const endpoint of ["promotions", "support", "summary", "team", "storefront", "wallet", "media"]) {
    assert.ok(operations.includes(`/api/panel/${endpoint}`), `operations does not use ${endpoint}`);
  }
  for (const method of ["POST", "PATCH", "PUT", "DELETE"]) assert.ok(operations.includes(`method: "${method}"`), `operations does not issue ${method}`);
  assert.doesNotMatch(operations, /backend dikerjakan|tahap UI|simulasi/i);
});
