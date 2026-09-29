import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-digiflazz-workspace.tsx"), "utf8");
const dashboard = fs.readFileSync(path.join(process.cwd(), "components/admin-dashboard.tsx"), "utf8");

test("Digiflazz workspace shows provider inventory and keeps selling controls in Produk", () => {
  for (const label of ["Sync Pricelist", "Auto Sync:", "Semua Kategori", "Semua Produk", "Harga DigiFlazz", "Stok", "Status"]) {
    assert.ok(source.includes(label), `missing provider label: ${label}`);
  }
  assert.doesNotMatch(source, /async function savePricing|Max Price Digiflazz|Harga Jual = Max Price|marginType|marginValue/);
  assert.match(source, /item\.productName === product/);
  assert.match(source, /nominalNumber\(left\.packageLabel\) - nominalNumber\(right\.packageLabel\)/);
  assert.match(dashboard, /<AdminDigiflazzWorkspace/);
});

test("Digiflazz workspace uses backend operations while credentials stay in Integrasi", () => {
  assert.match(source, /fetch\("\/api\/admin\/digiflazz-monitor"/);
  assert.match(source, /fetch\("\/api\/admin\/digiflazz-pricing"/);
  assert.match(source, /fetch\("\/api\/admin\/orders"/);
  assert.match(source, /method: "POST"/);
  assert.doesNotMatch(source, /Simulasi sync|Backend Digiflazz belum dihubungkan|rancangan frontend/);
  assert.doesNotMatch(source, /api key|username|secret/i);
  assert.match(dashboard, /label: "Integrasi"/);
  assert.match(dashboard, /value="integrations"/);
});