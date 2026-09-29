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

test("Vinext runtime cannot execute the retired D1 API", () => {
  const worker = read("worker/index.ts");
  assert.match(worker, /proxyApiToLaravel/);
  assert.match(worker, /VPS_FRONTEND_MODE/);
  assert.match(worker, /RETIRED_WORKER_API/);
  assert.match(worker, /API LFAMILIA dijalankan oleh Laravel\/MariaDB pada VPS/);
  assert.doesNotMatch(worker, /D1Database/);
  assert.doesNotMatch(worker, /ensureLegacyDatabaseColumns/);
  assert.doesNotMatch(worker, /hydrateIntegrationRuntimeEnv/);
  assert.doesNotMatch(worker, /async scheduled\(/);
});
