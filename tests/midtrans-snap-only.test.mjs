import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("active payment runtime is Midtrans Snap only", () => {
  const files = [
    "lib/server/midtrans.ts",
    "lib/server/integration-config.ts",
    "app/api/admin/integrations/route.ts",
    "components/admin-integration-manager.tsx",
    "app/api/payments/midtrans/client-config/route.ts",
    "app/api/payment-methods/route.ts",
    "lib/server/provider-relay.ts",
    "relay/server.mjs",
  ];
  for (const file of files) {
    const source = read(file);
    assert.doesNotMatch(source, /bisnap|BI-SNAP|MIDTRANS_BISNAP/i, file);
  }
});

test("removed BI-SNAP implementation and callback routes stay deleted", () => {
  for (const file of [
    "lib/server/midtrans-bisnap.ts",
    "lib/server/midtrans-bisnap-callback.ts",
    "app/v1.0/debit/notify/route.ts",
    "app/v1.0/qr/qr-mpm-notify/route.ts",
  ]) {
    assert.equal(fs.existsSync(path.join(root, file)), false, file);
  }
});

test("historical Midtrans mode columns remain available for old orders", () => {
  const schema = read("db/schema.ts");
  const status = read("app/api/orders/status/route.ts");
  assert.match(schema, /midtransMode: text\("midtrans_mode"/);
  assert.match(status, /order\.midtrans_mode/);
});

test("D1 migration removes obsolete BI-SNAP integration selection", () => {
  const migration = read("drizzle/0022_remove_active_midtrans_bisnap.sql");
  assert.match(migration, /DELETE FROM integration_profiles/);
  assert.match(migration, /mode = 'bisnap'/);
  assert.match(migration, /VALUES \('midtrans_mode', 'snap'/);
});
