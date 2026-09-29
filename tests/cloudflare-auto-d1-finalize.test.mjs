import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("retired Cloudflare config cannot bind the old D1 database", () => {
  const wrangler = read("wrangler.jsonc");
  assert.doesNotMatch(wrangler, /d1_databases/);
  assert.doesNotMatch(wrangler, /database_id/);
  assert.doesNotMatch(wrangler, /lfamilia-store-db/);
  assert.match(wrangler, /"crons": \[\]/);
  assert.match(wrangler, /"workers_dev": false/);
  assert.match(wrangler, /"preview_urls": false/);
});

test("VPS Node API bypasses every legacy Worker D1 handler", () => {
  const worker = read("worker/index.ts");
  const proxy = worker.indexOf("proxyApiToLaravel(request, url)");
  const legacyRuntime = worker.indexOf("if (runtimeEnv.DB)");
  assert.ok(proxy > 0);
  assert.ok(legacyRuntime > proxy);
  assert.match(worker, /isVpsFrontendRuntime\(\)/);
  assert.match(worker, /url\.pathname === "\/api"/);
  assert.match(worker, /LFAMILIA_LARAVEL_INTERNAL_URL/);
});
