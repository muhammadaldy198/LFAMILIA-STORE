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

test("manual product creation uploads the selected product image before persisting the product", () => {
  assert.match(source, /name="image" type="file" accept="image\/png,image\/jpeg,image\/webp"/);
  assert.match(source, /form\.get\("image"\)/);
  assert.match(source, /Ukuran gambar produk maksimal 2MB/);
  assert.match(source, /upload\.set\("file", image\)/);
  assert.match(source, /raw\.imageUrl = uploaded\.url/);
});
