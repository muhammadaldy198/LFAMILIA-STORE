import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/store-header.tsx"), "utf8");

test("header brand is a reliable homepage action", () => {
  assert.match(source, /usePathname/);
  assert.match(source, /useRouter/);
  assert.match(source, /function goHome/);
  assert.match(source, /window\.scrollTo\(\{ top: 0, behavior: "smooth" \}\)/);
  assert.match(source, /router\.push\("\/"\)/);
  assert.match(source, /onClick=\{goHome\}/);
  assert.match(source, /pointer-events-auto/);
  assert.match(source, /cursor-pointer/);
});
