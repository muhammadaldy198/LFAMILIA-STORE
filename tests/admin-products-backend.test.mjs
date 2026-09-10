import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-product-manager.tsx"), "utf8");

test("product list and mutations use the real panel endpoint", () => {
  assert.match(source, /fetch\("\/api\/panel\/products"/);
  assert.match(source, /payload\.products\.map\(mapProduct\)/);
  assert.match(source, /JSON\.stringify\(raw\)/);
  assert.match(source, /JSON\.stringify\(buildPayload\(\)\)/);
});

test("nominals and separators are serialized into the backend product contract", () => {
  assert.match(source, /packages: nominals\.map/);
  assert.match(source, /packageTabs: activeSections/);
  assert.match(source, /providerCode: item\.provider === "Digiflazz" \? "digiflazz"/);
  assert.match(source, /pricingMode: item\.provider === "Digiflazz" \? "auto" : "manual"/);
  assert.match(source, /targetTemplate/);
});
