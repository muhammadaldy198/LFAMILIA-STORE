import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "proxy.ts"), "utf8");

test("direct admin API mutations receive the same cross-origin boundary as the panel adapter", () => {
  assert.match(source, /matcher:\s*\["\/api\/admin\/:path\*"\]/);
  assert.match(source, /safeMethods\.has\(request\.method\)/);
  assert.match(source, /sec-fetch-site/);
  assert.match(source, /request\.nextUrl\.origin/);
  assert.match(source, /status:\s*403/);
});
