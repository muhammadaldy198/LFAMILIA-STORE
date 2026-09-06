import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const ipaymu = fs.readFileSync(path.join(root, "lib/server/ipaymu.ts"), "utf8");
const methods = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");

test("iPaymu uses official Direct Payment endpoints by default", () => {
  assert.match(ipaymu, /https:\/\/sandbox\.ipaymu\.com\/api\/v2\/payment\/direct/);
  assert.match(ipaymu, /https:\/\/my\.ipaymu\.com\/api\/v2\/payment\/direct/);
});

test("iPaymu production readiness requires configured static relay", () => {
  assert.match(ipaymu, /config\.environment === "production"/);
  assert.match(ipaymu, /isProviderRelayConfigured\("ipaymu"\)/);
});

test("storefront advertises only ready gateways", () => {
  assert.match(methods, /settings\.ipaymuCheckoutEnabled && ipaymuReadiness\.ready/);
  assert.match(methods, /settings\.midtransCheckoutEnabled && midtransReadiness\.ready/);
  assert.match(methods, /allChannels/);
});
