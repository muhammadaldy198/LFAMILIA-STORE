import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();

test("account menu is interactive and exposes identity plus safe navigation", () => {
  const dashboard = fs.readFileSync(path.join(root, "components/admin-dashboard.tsx"), "utf8");
  const accountMenu = fs.readFileSync(path.join(root, "components/admin-account-menu.tsx"), "utf8");
  assert.match(dashboard, /<AdminAccountMenu/);
  assert.match(accountMenu, /aria-expanded=\{open\}/);
  assert.match(accountMenu, /session\.email/);
  assert.match(accountMenu, /Super Admin/);
  assert.match(accountMenu, /logoutPath/);
});

test("orders panel implements desktop, mobile, filters, export and real detail data", () => {
  const source = fs.readFileSync(path.join(root, "components/admin-order-manager.tsx"), "utf8");
  const route = fs.readFileSync(path.join(root, "app/api/admin/orders/route.ts"), "utf8");
  for (const label of [
    "Kelola semua pesanan dan pantau status transaksi pelanggan.",
    "Export",
    "Semua Status",
    "Semua Produk",
    "Semua Pembayaran",
    "Semua Provider",
    "Menunggu Pembayaran",
    "Diproses",
    "Berhasil",
    "Refund",
    "Detail Pesanan",
    "Timeline",
    "Aksi Admin",
    "Copy Invoice",
  ]) assert.ok(source.includes(label), label);
  assert.match(source, /DesktopOrderTable/);
  assert.match(source, /MobileOrderList/);
  assert.match(source, /MobileFilters/);
  assert.match(source, /text\/csv/);
  assert.match(route, /FROM order_events WHERE order_id = \?/);
  assert.match(route, /Cache-Control/);
  assert.doesNotMatch(source, />Refund<\/Button>/);
});
