import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("production runtime has one database authority: Laravel MariaDB", () => {
  const wrangler = read("wrangler.jsonc");
  const worker = read("worker/index.ts");
  const service = read("laravel/deploy/systemd/lfamilia-web.service.template");
  const migration = read("MIGRATION-LARAVEL.md");

  assert.doesNotMatch(wrangler, /d1_databases|database_id|lfamilia-store-db/);
  assert.match(worker, /VPS_FRONTEND_MODE/);
  assert.match(worker, /proxyApiToLaravel/);
  assert.match(worker, /isVpsFrontendRuntime\(\)/);
  assert.match(worker, /url\.pathname === "\/api"/);
  assert.match(worker, /LFAMILIA_LARAVEL_INTERNAL_URL/);
  assert.match(service, /Environment=VPS_FRONTEND_MODE=1/);
  assert.match(service, /Environment=LARAVEL_INTERNAL_URL=http:\/\/127\.0\.0\.1:8080/);
  assert.match(migration, /former Worker\/D1 implementation remains in Git history/);
  assert.match(migration, /no longer the public runtime/);
});

test("main validates source without depending on retired Worker deployment", () => {
  const workflow = read(".github/workflows/validate.yml");
  const laravelWorkflow = read(".github/workflows/validate-laravel.yml");
  assert.match(workflow, /branches: \[main\]/);
  assert.match(workflow, /npm run build/);
  assert.match(workflow, /node --test tests\/\*\.test\.mjs/);
  assert.doesNotMatch(workflow, /Workers Builds:|Wait for Cloudflare production deployment/);
  assert.match(laravelWorkflow, /- main/);
  assert.match(laravelWorkflow, /Run schema migrations on MariaDB/);
  assert.match(laravelWorkflow, /vendor\/bin\/phpunit/);
});
