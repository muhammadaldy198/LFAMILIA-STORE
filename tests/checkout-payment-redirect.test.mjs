import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const ipaymu = fs.readFileSync(path.join(root, "app/api/payments/ipaymu/create/route.ts"), "utf8");
const midtrans = fs.readFileSync(path.join(root, "app/api/payments/midtrans/create/route.ts"), "utf8");

test("provider create responses identify their payment gateway", () => {
  assert.match(ipaymu, /paymentGateway: "ipaymu"/);
  assert.match(midtrans, /paymentGateway: "midtrans"/);
});

test("successful iPaymu checkout leaves checkout immediately", () => {
  assert.match(checkout, /data\.paymentGateway === "ipaymu" && data\.paymentUrl/);
  assert.match(checkout, /window\.location\.assign\(redirectUrl\.toString\(\)\)/);
});

test("external payments fall back to internal payment page", () => {
  assert.match(checkout, /\/payment\?invoice=/);
  assert.match(checkout, /encodeURIComponent\(invoice\)/);
});
