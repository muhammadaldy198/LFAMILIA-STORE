import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/wallet/route.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-wallet-manager.tsx"), "utf8");

test("owner payment API exposes DOKU readiness without exposing credentials", () => {
  assert.match(route, /gatewayReadiness: \{ doku: getDokuReadiness\(\) \}/);
  assert.doesNotMatch(route, /secretKey|clientId/);
});

test("payment admin shows one DOKU gateway with ready and not-ready states", () => {
  assert.match(manager, /DOKU Direct API/);
  assert.match(manager, /"Siap"/);
  assert.match(manager, /"Belum siap"/);
  assert.match(manager, /readiness\.reason/);
  assert.doesNotMatch(manager, /midtrans|ipaymu/i);
});
