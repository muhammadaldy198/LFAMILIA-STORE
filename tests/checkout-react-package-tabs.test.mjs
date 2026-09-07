import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("checkout waits for D1 catalog before mounting a product form", () => {
  const checkout = read("app/checkout/page.tsx");
  assert.match(checkout, /const \{ products, databaseReady, loading \} = useStoreProducts\(\)/);
  assert.match(checkout, /if \(loading\)/);
  assert.match(checkout, /if \(!product\)/);
  assert.match(checkout, /Checkout dinonaktifkan agar harga lama tidak digunakan/);
});

test("package tabs are rendered from React state instead of DOM mutation", () => {
  const checkout = read("app/checkout/page.tsx");
  const layout = read("app/checkout/layout.tsx");
  const types = read("lib/store-data.ts");

  assert.match(types, /group\?: string/);
  assert.match(types, /packageTabsEnabled\?: boolean/);
  assert.match(types, /packageTabs\?: string\[\]/);
  assert.match(checkout, /const packageGroups = useMemo/);
  assert.match(checkout, /const visiblePackages =/);
  assert.match(checkout, /packageGroups\.map\(\(group\)/);
  assert.match(checkout, /visiblePackages\.map\(\(item\)/);
  assert.doesNotMatch(layout, /CheckoutPackageTabs/);
  assert.equal(
    fs.existsSync(path.join(root, "components/checkout-package-tabs.tsx")),
    false,
  );
});
