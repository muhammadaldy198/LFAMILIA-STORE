import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();

test("admin UI uses the unified panel API instead of direct admin API routes", () => {
  const componentDir = path.join(root, "components");
  const files = fs.readdirSync(componentDir).filter((name) => name.startsWith("admin-") && name.endsWith(".tsx"));
  for (const name of files) {
    const source = fs.readFileSync(path.join(componentDir, name), "utf8");
    assert.doesNotMatch(source, /\/api\/admin\//, `${name} must use /api/panel/*`);
  }
});

test("panel route map has no duplicate endpoint keys", () => {
  const source = fs.readFileSync(path.join(root, "app/api/panel/[...path]/route.ts"), "utf8");
  const block = source.slice(source.indexOf("const routes:"), source.indexOf("async function dispatch"));
  const matches = [...block.matchAll(/^\s*(?:"([^"]+)"|([a-z][\w-]*)):\s*\{/gm)].map((match) => match[1] || match[2]);
  assert.ok(matches.length > 10, "expected panel endpoint map");
  assert.equal(new Set(matches).size, matches.length, "panel endpoints must be unique");
});

test("Cloudflare Access protects direct admin API but not staff panel API", () => {
  const source = fs.readFileSync(path.join(root, "worker/index.ts"), "utf8");
  assert.match(source, /url\.pathname === "\/api\/admin"/);
  assert.match(source, /url\.pathname\.startsWith\("\/api\/admin\/"\)/);
  const guard = source.slice(source.indexOf("const isAccessProtectedRequest"), source.indexOf("if (isAccessProtectedRequest)"));
  assert.doesNotMatch(guard, /\/api\/panel/);
});
