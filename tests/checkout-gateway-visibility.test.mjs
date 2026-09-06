import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "app/checkout/page.tsx"), "utf8");

test("checkout hides gateway/provider selector from customers", () => {
  assert.doesNotMatch(source, /Pilih gateway pembayaran/);
  assert.doesNotMatch(source, /activeCheckoutGateway/);
  assert.match(source, /Pilih metode pembayaran yang ingin digunakan/);
});

test("external checkout posts directly to iPaymu", () => {
  assert.match(source, /\/api\/payments\/ipaymu\/create/);
  assert.doesNotMatch(source, /\/api\/payments\/auto\/create/);
  assert.doesNotMatch(source, /Midtrans/i);
});
