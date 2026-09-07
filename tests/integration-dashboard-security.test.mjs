import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const manager = fs.readFileSync(
  path.join(root, "components/admin-integration-manager.tsx"),
  "utf8",
);
const route = fs.readFileSync(
  path.join(root, "app/api/admin/integrations/route.ts"),
  "utf8",
);
const config = fs.readFileSync(
  path.join(root, "lib/server/integration-config.ts"),
  "utf8",
);

test("every payment and fulfillment environment can be selected from Dashboard Admin", () => {
  assert.match(manager, /Environment iPaymu/);
  assert.match(manager, /Environment Midtrans/);
  assert.match(manager, /Environment DigiFlazz/);
  assert.match(manager, /Environment VIPayment/);
  assert.match(manager, /selections\.vippaymentEnvironment/);
});

test("integration credentials stay owner-only and encrypted", () => {
  assert.match(route, /requireAdminSession\(request, "owner"\)/);
  assert.match(config, /INTEGRATION_ENCRYPTION_KEY/);
  assert.match(config, /encryptConfig\(/);
  assert.match(config, /decryptConfig\(/);
  assert.match(manager, /Tersimpan — isi untuk mengganti/);
  assert.match(manager, /type=\{field\.secret \? "password" : "text"\}/);
});

test("provider credentials never fall back to Cloudflare runtime values", () => {
  assert.match(config, /withoutDashboardManagedRuntime/);
  for (const prefix of [
    "MIDTRANS_",
    "IPAYMU_",
    "DIGIFLAZZ_",
    "VIPPAYMENT_",
    "MELOSTORE_",
    "RESEND_",
    "PROVIDER_RELAY_",
  ]) {
    assert.match(config, new RegExp(`"${prefix}"`));
  }
  assert.match(config, /return systemOnly as T/);
  assert.doesNotMatch(config, /valueOr\(source\.MIDTRANS_ENV/);
  assert.doesNotMatch(config, /valueOr\(source\.IPAYMU_ENV/);
  assert.doesNotMatch(config, /valueOr\(source\.DIGIFLAZZ_ENV/);
  assert.doesNotMatch(config, /valueOr\(source\.VIPPAYMENT_ENV/);
});
