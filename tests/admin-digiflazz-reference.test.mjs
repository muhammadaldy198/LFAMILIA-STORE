import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-digiflazz-workspace.tsx"), "utf8");
const dashboard = fs.readFileSync(path.join(process.cwd(), "components/admin-dashboard.tsx"), "utf8");

test("Digiflazz workspace owns operational pricing controls", () => {
  for (const label of [
    "Pusat operasional provider dan Price Control LFAMILIA.",
    "Price Control LFAMILIA",
    "Sync Pricelist",
    "Auto Sync:",
    "Semua Kategori",
    "Semua Subkategori",
    "Max Price",
  ]) assert.ok(source.includes(label), `missing Digiflazz label: ${label}`);
  assert.match(dashboard, /<AdminDigiflazzWorkspace/);
});

test("Digiflazz price control separates provider cost, guard, margin and selling price", () => {
  for (const field of [
    "currentPrice",
    "maxPrice",
    "marginType",
    "marginValue",
    "sellingPrice",
    "blockedByMaxPrice",
    "category",
    "brand",
  ]) assert.ok(source.includes(field), `missing Digiflazz pricing field: ${field}`);
  assert.match(source, /async function savePricing/);
  assert.match(source, /method: "PUT"/);
  assert.match(source, /packageId: editing\.packageId, maxPrice, marginType, marginValue/);
  assert.match(source, /item\.category === category/);
  assert.match(source, /item\.brand === brand/);
});

test("Digiflazz workspace uses backend operations while credentials stay in Integrasi", () => {
  assert.match(source, /fetch\("\/api\/panel\/digiflazz-monitor"/);
  assert.match(source, /fetch\("\/api\/panel\/digiflazz-pricing"/);
  assert.match(source, /fetch\("\/api\/panel\/orders"/);
  assert.match(source, /method: "POST"/);
  assert.doesNotMatch(source, /Simulasi sync|Backend Digiflazz belum dihubungkan|rancangan frontend/);
  assert.doesNotMatch(source, /api key|username|secret/i);
  assert.match(dashboard, /label: "Integrasi"/);
  assert.match(dashboard, /value="integrations"/);
});
