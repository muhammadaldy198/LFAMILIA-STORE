import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const logo = "/brand/lfamilia-logo-transparent-v2.png";
const footerBanners = [
  "/brand/lfamilia-footer-mobile-wordmark.jpg",
  "/brand/lfamilia-footer-desktop-wordmark.jpg",
];

test("storefront and browser icons use the supplied LFAMILIA logo", () => {
  assert.ok(read("components/store-brand.tsx").includes(logo));
  assert.ok(read("lib/store-data.ts").includes(logo));
  assert.ok(read("app/layout.tsx").includes(logo));
  assert.ok(!read("components/store-brand.tsx").includes("/icon-192.png"));
});

test("brand PNG is transparent and both footer banners are complete JPEGs", () => {
  const icon = fs.readFileSync(path.join(root, "public", logo));
  assert.ok(icon.length > 10_000);
  assert.deepEqual([...icon.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10]);
  assert.equal(icon[25], 6, "PNG must have an alpha channel");

  for (const asset of footerBanners) {
    assert.ok(read("components/store-footer.tsx").includes(asset));
    const banner = fs.readFileSync(path.join(root, "public", asset));
    assert.ok(banner.length > 10_000);
    assert.equal(banner.readUInt16BE(0), 0xffd8);
    assert.equal(banner.readUInt16BE(banner.length - 2), 0xffd9);
  }
});
