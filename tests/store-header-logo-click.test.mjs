import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/store-header.tsx"), "utf8");

test("header brand is a reliable homepage action", () => {
  assert.match(source, /usePathname/);
  assert.match(source, /useRouter/);
  assert.match(source, /function goHome/);
  assert.match(source, /window\.location\.assign\("\/"\)/);
  assert.match(source, /router\.push\("\/"\)/);
  assert.doesNotMatch(source, /window\.scrollTo/);
  assert.match(source, /onClick=\{goHome\}/);
  assert.match(source, /pointer-events-auto/);
  assert.match(source, /cursor-pointer/);
});
