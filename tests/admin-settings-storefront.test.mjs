import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-operations-workspaces.tsx"), "utf8");

test("storefront settings expose every public contact and banner field", () => {
  for (const label of [
    "Instagram",
    "Discord",
    "Label tombol banner",
    "Link tombol banner",
    "Label atas banner",
  ]) assert.ok(source.includes(label), `missing public storefront field: ${label}`);
  assert.match(source, /store\.instagramUrl/);
  assert.match(source, /store\.discordUrl/);
  assert.match(source, /store\.bannerCtaLabel/);
  assert.match(source, /store\.bannerCtaHref/);
});
