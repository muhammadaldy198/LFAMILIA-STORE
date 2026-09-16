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
const kokinpay = read("admin-kokinpay-workspace.tsx");
const operations = read("admin-operations-workspaces.tsx");
const ui = read("admin-workspace-ui.tsx");

test("remaining admin menus use the new desktop workspaces", () => {
  for (const component of ["AdminPaymentWorkspace", "AdminCustomerWorkspace", "AdminPromoWorkspace", "AdminSupportWorkspace", "AdminReportsWorkspace", "AdminTeamWorkspace", "AdminIntegrationWorkspace", "AdminSettingsWorkspace", "AdminKokinpayWorkspace"]) {
    assert.ok(dashboard.includes(`<${component}`), `dashboard does not use ${component}`);
  }
  assert.match(dashboard, /label: "Validasi Akun"/);
  assert.match(dashboard, /value: "account-validation"/);
});

test("KokinPay operational menu follows the shared admin workspace theme", () => {
  for (const sharedComponent of ["WorkspaceHeader", "Panel", "TabBar", "Field", "Status"]) {
    assert.match(kokinpay, new RegExp(sharedComponent));
  }
  assert.match(kokinpay, /inputClass/);
  assert.match(kokinpay, /buttonClass/);
  assert.match(kokinpay, /primaryButtonClass/);
  assert.match(kokinpay, /Daftar Kode Game/);
  assert.match(kokinpay, /check-nick-game/);
  assert.match(kokinpay, /check-region-mlbb/);
  assert.match(kokinpay, /check-nick-pln/);
  assert.match(kokinpay, /Mobile Legends divalidasi dengan dua endpoint/);
  assert.match(kokinpay, /api\.kokinpay\.com\/docs\/check-nick-game/);
  assert.match(kokinpay, /api\.kokinpay\.com\/docs\/check-region-mlbb/);
  assert.match(kokinpay, /api\.kokinpay\.com\/docs\/check-nick-pln/);
});

test("dual-gateway payment UI includes channel controls and editable gateway-neutral payment page", () => {
  for (const label of ["Midtrans", "DOKU", "Partner Service ID VA", "Tampilan Halaman", "Editor Halaman Pembayaran", "Ganti Gambar", "Preview Halaman Pembayaran", ">QRIS<"]) {
    assert.ok(payment.includes(label), `missing payment UI: ${label}`);
  }
  assert.ok(payment.includes("image/png,image/jpeg,image/webp"));
  assert.match(payment, /Preview customer tanpa nama payment gateway/);
  assert.doesNotMatch(payment, />QRIS DOKU</);
});

test("Super Admin can design balance changes for customer or admin accounts", () => {
  for (const label of ["Atur Saldo", "Pelanggan", "Admin", "Tambah", "Kurangi", "Simpan Perubahan Saldo", "audit log"]) assert.ok(balance.includes(label), `missing balance UI: ${label}`);
  assert.match(customer, /<AdminBalanceManager \/>/);
});

test("integration UI contains encrypted credentials, provider URLs, and keeps KokinPay operations separate", () => {
  for (const label of [
    "DOKU Notification URL",
    "Midtrans BI-SNAP VA Notification URL",
    "Digiflazz Webhook URL",
    "Konfigurasi KokinPay",
    "API Key KokinPay",
    "/check-nick-game",
    "/check-region-mlbb",
    "/check-nick-pln",
    "Client Secret",
    "Partner ID",
    "Relay Token",
    "Webhook Secret",
    "https://digiflazz-relay.lfamiliastore.my.id",
    "https://midtrans-relay.lfamiliastore.my.id",
  ]) assert.ok(integration.includes(label), `missing integration UI: ${label}`);
  assert.ok(integration.includes("https://lfamiliastore.my.id/api/payments/doku/callback"));
  assert.ok(integration.includes("https://lfamiliastore.my.id/api/payments/midtrans/v1.0/transfer-va/payment"));
  assert.ok(integration.includes("https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback"));
  assert.ok(ui.includes("navigator.clipboard.writeText"));
  assert.doesNotMatch(integration, /SwitchLine label="(?:Wajib|Tidak Wajib).*nickname/i);
  assert.doesNotMatch(integration, /KOKINPAY_GAME_CODES|checkKokinpay|\/check-nickname|value="\/check-pln"/);
  assert.match(integration, /Pemeriksaan operasional berada di menu Validasi Akun/);
  assert.match(integration, /Periksa Konfigurasi Relay/);
  assert.doesNotMatch(integration, /Tes Relay Sekarang/);
});

test("promo, support, reports, team, and settings are fully represented", () => {
  for (const label of ["Tambah Promo", "Daftar Tiket", "Grafik Penjualan", "Hak Akses Role", "Audit Aktivitas Admin", "Logo & Ikon", "Aturan Wallet Pelanggan", "Keamanan Transaksi", "Riwayat Backup"]) assert.ok(operations.includes(label), `missing remaining UI: ${label}`);
});

test("all remaining workspaces use real panel APIs", () => {
  assert.match(integration, /fetch\("\/api\/panel\/integrations"/);
  assert.doesNotMatch(integration, /fetch\("\/api\/panel\/nickname-tools"/);
  assert.match(kokinpay, /fetch\("\/api\/panel\/nickname-tools"/);
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
