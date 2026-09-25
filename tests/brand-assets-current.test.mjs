import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const logo = "/brand/lfamilia-logo-2026.jpg";
const footerBanner = "/brand/lfamilia-footer-2026.jpg";

test("storefront and browser icons use the supplied LFAMILIA logo", () => {
  assert.ok(read("components/store-brand.tsx").includes(logo));
  assert.ok(read("lib/store-data.ts").includes(logo));
  assert.ok(read("app/layout.tsx").includes(logo));
  assert.ok(!read("components/store-brand.tsx").includes("/icon-192.png"));
});

test("footer uses the supplied banner and both deployed images are complete JPEGs", () => {
  assert.ok(read("components/store-footer.tsx").includes(footerBanner));
  for (const asset of [logo, footerBanner]) {
    const bytes = fs.readFileSync(path.join(root, "public", asset));
    assert.ok(bytes.length > 10_000, `${asset} must contain the supplied image`);
    assert.equal(bytes.readUInt16BE(0), 0xffd8, `${asset} must begin with a JPEG header`);
    assert.equal(bytes.readUInt16BE(bytes.length - 2), 0xffd9, `${asset} must have a JPEG end marker`);
  }
});
