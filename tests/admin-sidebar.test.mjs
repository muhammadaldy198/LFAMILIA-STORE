import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-dashboard.tsx"), "utf8");

test("desktop admin sidebar uses vertical tabs and full viewport height", () => {
  assert.match(source, /<Tabs orientation="vertical"/);
  assert.match(source, /lg:!h-\[calc\(100vh-5\.5rem\)\]/);
  assert.match(source, /lg:self-start/);
});
