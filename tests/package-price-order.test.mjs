import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const publicProducts = read("app/api/products/route.ts");
const adminProducts = read("app/api/admin/products/route.ts");

function assertPriceSort(source, label) {
  assert.match(source, /left\.price - right\.price/,
    `${label} must sort packages by selling price ascending`);
  assert.match(source, /left\.sortOrder - right\.sortOrder/,
    `${label} must keep deterministic ordering for equal prices`);
  assert.match(source, /localeCompare\(right\.label, "id-ID", \{ numeric: true, sensitivity: "base" \}\)/,
    `${label} must use natural numeric label ordering as the final tie-breaker`);
}

test("customer checkout receives nominal packages from cheapest to most expensive", () => {
  assertPriceSort(publicProducts, "public products API");
});

test("admin product nominal list receives the same cheapest-to-most-expensive order", () => {
  assertPriceSort(adminProducts, "admin products API");
});
