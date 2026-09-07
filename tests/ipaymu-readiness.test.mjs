import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const ipaymu = fs.readFileSync(path.join(root, "lib/server/ipaymu.ts"), "utf8");
const relay = fs.readFileSync(path.join(root, "lib/server/provider-relay.ts"), "utf8");
const methods = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const adminWallet = fs.readFileSync(path.join(root, "app/api/admin/wallet/route.ts"), "utf8");

test("iPaymu uses official Direct Payment endpoints by default", () => {
  assert.match(ipaymu, /https:\/\/sandbox\.ipaymu\.com\/api\/v2\/payment\/direct/);
  assert.match(ipaymu, /https:\/\/my\.ipaymu\.com\/api\/v2\/payment\/direct/);
});

test("iPaymu production config requires a static relay", () => {
  assert.match(ipaymu, /config\.environment === "production"/);
  assert.match(ipaymu, /isProviderRelayConfigured\("ipaymu"\)/);
});

test("live relay probing is reserved for admin diagnostics", () => {
  assert.match(ipaymu, /getIpaymuOperationalReadiness/);
  assert.match(ipaymu, /probeProviderRelay\("ipaymu", "iPaymu"\)/);
  assert.match(relay, /export async function probeProviderRelay/);
  assert.match(adminWallet, /getIpaymuOperationalReadiness\(\)/);
  assert.doesNotMatch(methods, /getIpaymuOperationalReadiness\(\)/);
  assert.doesNotMatch(autoRoute, /getIpaymuOperationalReadiness\(\)/);
});

test("storefront availability uses saved gateway readiness so transient relay probes cannot blank checkout", () => {
  assert.match(methods, /getIpaymuReadiness\(\)/);
  assert.match(methods, /getMidtransReadiness\(\)/);
  assert.match(methods, /settings\.ipaymuCheckoutEnabled && ipaymuReadiness\.ready/);
  assert.match(methods, /settings\.midtransCheckoutEnabled && midtransReadiness\.ready/);
  assert.match(methods, /allChannels/);
});
