import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const repair = fs.readFileSync(path.join(root, "lib/server/database-repair.ts"), "utf8");
const workflow = fs.readFileSync(path.join(root, ".github/workflows/validate.yml"), "utf8");
const migrationName = "0029_final_source_audit_remediation.sql";

test("runtime records healed 0029 only through an existing Wrangler migration ledger", () => {
  assert.match(repair, new RegExp(migrationName.replaceAll(".", "\\.")));
  assert.match(repair, /tableColumns\("d1_migrations"\)/);
  assert.match(repair, /INSERT OR IGNORE INTO d1_migrations \(name\) VALUES \(\?\)/);
  assert.match(repair, /schemaObjects\.results\.length !== FINAL_SCHEMA_OBJECTS\.length/);
  assert.match(repair, /unsupportedRequiredColumn/);
  assert.doesNotMatch(repair, /CREATE TABLE IF NOT EXISTS d1_migrations/);
});

test("Wrangler migration marker is replay-idempotent for its normal ledger shape", () => {
  const db = new DatabaseSync(":memory:");
  db.exec(`CREATE TABLE d1_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
  )`);
  const statement = db.prepare("INSERT OR IGNORE INTO d1_migrations (name) VALUES (?)");
  statement.run(migrationName);
  statement.run(migrationName);
  const rows = db.prepare("SELECT name FROM d1_migrations").all();
  assert.deepEqual(rows.map((row) => row.name), [migrationName]);
  db.close();
});

test("main validates source without depending on retired Worker deployment", () => {
  const laravelWorkflow = fs.readFileSync(path.join(root, ".github/workflows/validate-laravel.yml"), "utf8");
  assert.match(workflow, /branches: \[main\]/);
  assert.match(workflow, /npm run build/);
  assert.match(workflow, /node --test tests\/\*\.test\.mjs/);
  assert.doesNotMatch(workflow, /Workers Builds:|Wait for Cloudflare production deployment/);
  assert.match(laravelWorkflow, /- main/);
  assert.match(laravelWorkflow, /Run schema migrations on MariaDB/);
  assert.match(laravelWorkflow, /vendor\/bin\/phpunit/);
});
