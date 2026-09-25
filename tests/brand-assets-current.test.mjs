import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("LFAMILIA storefront renders the verified site icon", () => {
  const brand = read("components/store-brand.tsx");
  assert.match(brand, /src="\/icon-192\.png"/);
  assert.doesNotMatch(brand, /failedUrl/);
});

test("footer banner is rendered full-width without the broken generated image", () => {
  const footer = read("components/store-footer.tsx");
  assert.match(footer, /h-\[72px\] w-screen/);
  assert.match(footer, /LFAMILIA/);
  assert.match(footer, /STORE/);
  assert.match(footer, /src="\/icon-192\.png"/);
  assert.doesNotMatch(footer, /lfamilia-footer-banner\.webp/);
  assert.match(footer, /sm:h-\[82px\]/);
  assert.match(footer, /md:h-\[92px\]/);
  assert.match(footer, /lg:h-\[104px\]/);
});
