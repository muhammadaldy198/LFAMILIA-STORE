import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/payment-methods/route.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-payment-workspace.tsx"), "utf8");

test("payment admin API exposes DOKU and Midtrans readiness without credentials", () => {
  assert.match(route, /getDokuReadiness\(\)/);
  assert.match(route, /getMidtransReadiness\(\)/);
  assert.match(route, /isProviderRelayConfigured\("midtrans"\)/);
  assert.match(route, /gatewayReadiness/);
  assert.doesNotMatch(route, /clientSecret\s*:|secretKey\s*:|privateKey\s*:/);
});

test("payment admin shows dual exclusive gateway controls", () => {
  assert.match(manager, /DOKU Direct API/);
  assert.match(manager, /Midtrans BI-SNAP/);
  assert.match(manager, /Master Gateway/);
  assert.match(manager, /Metode Pembayaran/);
  assert.match(manager, /Editor Halaman Pembayaran/);
  assert.match(manager, /Tidak ada fallback otomatis antar gateway/);
  assert.doesNotMatch(manager, /QRIS DOKU/);
});
