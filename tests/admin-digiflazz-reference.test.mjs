import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-digiflazz-workspace.tsx"), "utf8");
const dashboard = fs.readFileSync(path.join(process.cwd(), "components/admin-dashboard.tsx"), "utf8");

test("Digiflazz workspace follows the supplied desktop reference", () => {
  for (const label of [
    "Pantau operasional provider Digiflazz dan sinkronisasi data untuk LFAMILIA.",
    "Status API",
    "Saldo Digiflazz",
    "SKU Aktif",
    "Sync Terakhir",
    "Produk Bermasalah",
    "Monitoring Produk Digiflazz",
    "Aksi Cepat",
    "Status Sinkronisasi",
    "Transaksi Provider Terbaru",
  ]) assert.ok(source.includes(label), `missing Digiflazz label: ${label}`);
  assert.match(dashboard, /<AdminDigiflazzWorkspace/);
});

test("Digiflazz operational controls and tables are present", () => {
  for (const label of [
    "Sync Pricelist",
    "Mapping SKU",
    "Monitor Seller",
    "Lihat Log",
    "SKU Digiflazz",
    "Harga Modal",
    "Status Seller",
    "Invoice LFAMILIA",
    "Response",
  ]) assert.ok(source.includes(label), `missing Digiflazz control: ${label}`);
  assert.match(source, /function MonitorTable/);
  assert.match(source, /function TransactionTable/);
  assert.match(source, /function OperationDialog/);
});

test("Digiflazz workspace remains frontend-only and credentials stay in Integrasi", () => {
  assert.doesNotMatch(source, /fetch\s*\(/);
  assert.doesNotMatch(source, /api key|username|secret/i);
  assert.match(dashboard, /label: "Integrasi"/);
  assert.match(dashboard, /value="integrations"/);
});
