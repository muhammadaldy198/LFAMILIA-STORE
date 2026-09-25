import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("LFAMILIA storefront uses the repaired themed logo asset", () => {
  const storeData = read("lib/store-data.ts");
  const storefront = read("lib/server/storefront.ts");
  const brand = read("components/store-brand.tsx");

  assert.match(storeData, /logoUrl: "\/brand\/lfamilia-neon-logo\.webp"/);
  assert.match(storefront, /row\.logo_url === "\/brand\/lfamilia-pixel-logo\.webp"/);
  assert.match(brand, /lfamilia-neon-logo\.webp\?v=20260925c/);
  assert.doesNotMatch(brand, /pixel-art size-full object-cover/);
});

test("footer banner uses the repaired compact full-width LFAMILIA artwork", () => {
  const footer = read("components/store-footer.tsx");

  assert.match(footer, /src="\/brand\/lfamilia-footer-banner\.webp\?v=20260925c"/);
  assert.match(footer, /h-\[72px\] w-screen/);
  assert.match(footer, /sm:h-\[82px\]/);
  assert.match(footer, /md:h-\[92px\]/);
  assert.match(footer, /lg:h-\[104px\]/);
  assert.match(footer, /object-cover object-center/);
});
