import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const checkout = fs.readFileSync(path.join(root, "app/checkout/page.tsx"), "utf8");
const ipaymuRoute = fs.readFileSync(path.join(root, "app/api/payments/ipaymu/create/route.ts"), "utf8");
const limits = fs.readFileSync(path.join(root, "lib/payment-limits.ts"), "utf8");

test("iPaymu checkout minimum is centralized", () => {
  assert.match(limits, /IPAYMU_MIN_CHECKOUT_AMOUNT = 10_000/);
  assert.match(limits, /isIpaymuAmountSupported/);
});

test("checkout excludes iPaymu below minimum and prefers Midtrans", () => {
  assert.match(checkout, /gateway\.code !== "ipaymu"/);
  assert.match(checkout, /gateway\.code === "midtrans"/);
  assert.match(checkout, /gateway\.channels\.find\(\(item\) => item\.method === "qris"\)/);
});

test("iPaymu route rejects below-minimum amount before provider request", () => {
  assert.match(ipaymuRoute, /isIpaymuAmountSupported\(promotion\.finalPrice\)/);
  assert.match(ipaymuRoute, /Pilih Midtrans QRIS\/e-wallet/);
});
