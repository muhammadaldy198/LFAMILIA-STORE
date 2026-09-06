import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/wallet/route.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-wallet-manager.tsx"), "utf8");

test("owner payment API exposes gateway readiness without credentials", () => {
  assert.match(route, /gatewayReadiness/);
  assert.match(route, /getIpaymuReadiness\(\)/);
  assert.match(route, /getMidtransReadiness\(\)/);
});

test("payment admin shows ready and not-ready gateway states", () => {
  assert.match(manager, /GatewayReadiness/);
  assert.match(manager, /"Siap"/);
  assert.match(manager, /"Belum siap"/);
  assert.match(manager, /row\.readiness\.reason/);
});
