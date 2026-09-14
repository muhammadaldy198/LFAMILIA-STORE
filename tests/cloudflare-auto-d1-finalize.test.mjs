import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const repair = fs.readFileSync(path.join(root, "lib/server/database-repair.ts"), "utf8");
const migration = fs.readFileSync(path.join(root, "drizzle/0029_final_source_audit_remediation.sql"), "utf8");
const worker = fs.readFileSync(path.join(root, "worker/index.ts"), "utf8");

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

test("Cloudflare runtime compatibility repair mirrors every 0029 added column", () => {
  const addedColumns = [...migration.matchAll(/ALTER TABLE\s+([A-Za-z0-9_]+)\s+ADD COLUMN\s+([A-Za-z0-9_]+)/g)]
    .map((match) => [match[1], match[2]]);
  assert.ok(addedColumns.length > 0);
  for (const [table, column] of addedColumns) {
    assert.match(
      repair,
      new RegExp(`\\[\\s*"${escapeRegExp(table)}"\\s*,\\s*"${escapeRegExp(column)}"`),
      `runtime repair is missing ${table}.${column}`,
    );
  }
});

test("Cloudflare runtime repair owns the final 0029 indexes, reservation table, triggers, and snapshot backfill", () => {
  for (const token of [
    "wallet_topups_external_checkout_key_unique",
    "promotion_reservations",
    "promotion_reservations_expiry_idx",
    "promotion_reservation_voucher_guard",
    "promotion_reservation_flash_guard",
    "promotion_reservation_insert",
    "promotion_reservation_consumed",
    "promotion_reservation_released",
    "supplier_cost_snapshot",
    "delivery_mode",
  ]) {
    assert.match(repair, new RegExp(escapeRegExp(token)));
  }
  assert.doesNotMatch(repair, /DELETE\s+FROM\s+(orders|wallet_topups|promotion_reservations)/i);
});

test("Cloudflare request and scheduled entry points repair D1 before application or maintenance work", () => {
  assert.match(worker, /import \{ ensureLegacyDatabaseColumns \} from "\.\.\/lib\/server\/database-repair"/);
  assert.equal(
    (worker.match(/await ensureLegacyDatabaseColumns\(\);/g) ?? []).length,
    2,
  );
  const fetchRepair = worker.indexOf("await ensureLegacyDatabaseColumns();");
  const appFetch = worker.indexOf("handler.fetch(request, env, ctx)");
  assert.ok(fetchRepair >= 0 && fetchRepair < appFetch);
  const scheduled = worker.indexOf("async scheduled(");
  const scheduledRepair = worker.indexOf("await ensureLegacyDatabaseColumns();", scheduled);
  const maintenance = worker.indexOf("releaseExpiredExternalPromotions()", scheduled);
  assert.ok(scheduledRepair > scheduled && scheduledRepair < maintenance);
});
