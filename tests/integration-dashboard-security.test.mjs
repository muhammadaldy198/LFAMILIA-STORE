import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const manager = fs.readFileSync(path.join(root, "components/admin-integration-workspace.tsx"), "utf8");
const route = fs.readFileSync(path.join(root, "app/api/admin/integrations/route.ts"), "utf8");
const config = fs.readFileSync(path.join(root, "lib/server/integration-config.ts"), "utf8");

test("DOKU and DigiFlazz environments are selected from Admin Dashboard", () => {
  assert.match(manager, /DOKU Direct API/);
  assert.match(manager, /Kredensial Digiflazz/);
  assert.doesNotMatch(manager, /Midtrans|iPaymu|VIPayment/i);
});

test("integration credentials stay owner-only and encrypted", () => {
  assert.match(route, /requireAdminSession\(request, "owner"\)/);
  assert.match(config, /INTEGRATION_ENCRYPTION_KEY/);
  assert.match(config, /encryptConfig\(/);
  assert.match(config, /decryptConfig\(/);
  assert.match(manager, /berhasil disimpan terenkripsi di backend/);
  assert.match(manager, /type=\{show \? "text" : "password"\}/);
  assert.match(manager, /action: "save_profile"/);
  assert.match(manager, /action: "save_selections"/);
});

test("dashboard-managed credentials fail closed instead of using stale Cloudflare provider secrets", () => {
  assert.match(config, /withoutDashboardManagedRuntime/);
  for (const prefix of ["DOKU_", "DIGIFLAZZ_", "MELOSTORE_", "RESEND_", "PROVIDER_RELAY_"]) {
    assert.match(config, new RegExp(`"${prefix}"`));
  }
  assert.match(config, /return systemOnly as T/);
  assert.doesNotMatch(config, /MIDTRANS_|IPAYMU_|VIPPAYMENT_/);
});
