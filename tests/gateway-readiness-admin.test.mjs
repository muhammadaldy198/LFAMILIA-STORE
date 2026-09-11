import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/wallet/route.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-payment-workspace.tsx"), "utf8");

test("owner payment API exposes DOKU readiness without exposing credentials", () => {
  assert.match(route, /gatewayReadiness: \{ doku: getDokuReadiness\(\) \}/);
  assert.doesNotMatch(route, /secretKey|clientId/);
});

test("payment admin shows the single DOKU Direct API workspace", () => {
  assert.match(manager, /DOKU Direct API/);
  assert.match(manager, /Periksa Konfigurasi/);
  assert.match(manager, /Uji koneksi dan transaksi live dilakukan pada tahap pra-peluncuran/);
  assert.match(manager, /Channel Pembayaran/);
  assert.match(manager, /Editor Halaman Pembayaran/);
  assert.doesNotMatch(manager, /midtrans|ipaymu/i);
});
