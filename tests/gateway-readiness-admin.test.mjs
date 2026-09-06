import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/wallet/route.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-wallet-manager.tsx"), "utf8");

test("owner payment API exposes iPaymu operational readiness without credentials", () => {
  assert.match(route, /gatewayReadiness/);
  assert.match(route, /getIpaymuOperationalReadiness\(\)/);
  assert.doesNotMatch(route, /Midtrans/i);
});

test("payment admin shows iPaymu ready and not-ready states", () => {
  assert.match(manager, /GatewayReadiness/);
  assert.match(manager, /"Siap"/);
  assert.match(manager, /"Belum siap"/);
  assert.match(manager, /gatewayReadiness\.reason/);
  assert.doesNotMatch(manager, /Midtrans/i);
});
