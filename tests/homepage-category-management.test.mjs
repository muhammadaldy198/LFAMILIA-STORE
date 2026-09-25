import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("homepage categories are managed by staff or higher", () => {
  const route = read("app/api/panel/categories/route.ts");
  assert.equal((route.match(/requireAdminSession\(request, "staff"\)/g) || []).length, 3);
  assert.match(route, /deleteCategory/);
  assert.match(route, /saveCategory/);
});

test("managed categories drive storefront order and visibility", () => {
  const hook = read("hooks/use-storefront.ts");
  const manager = read("components/admin-homepage-category-manager.tsx");
  const dashboard = read("components/admin-dashboard.tsx");

  assert.match(hook, /setCategories\(data\.categories\.slice\(\)\.sort/);
  assert.doesNotMatch(hook, /canonicalCategories/);
  assert.match(manager, /Kategori Homepage/);
  assert.match(manager, /Tambah/);
  assert.match(manager, /Hapus/);
  assert.match(manager, /ArrowUp/);
  assert.match(manager, /ArrowDown/);
  assert.match(manager, /isActive/);
  assert.match(dashboard, /<AdminHomepageCategoryManager \/>/);
});

test("custom category slugs survive public products and admin product editing", () => {
  const categories = read("lib/product-categories.ts");
  const products = read("components/admin-product-manager.tsx");
  const publicProducts = read("app/api/products/route.ts");

  assert.match(categories, /return normalized\.replace/);
  assert.match(publicProducts, /category: normalizeProductCategorySlug\(item\.category\)/);
  assert.match(products, /fetch\("\/api\/admin\/categories"/);
  assert.match(products, /categoryOptions\.map/);
  assert.match(products, /name="category"/);
});
