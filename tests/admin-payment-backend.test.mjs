import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-payment-workspace.tsx"), "utf8");

test("payment workspace loads and saves live dual-gateway channel configuration", () => {
  assert.match(source, /fetch\("\/api\/panel\/payment-methods"/);
  assert.match(source, /fetch\("\/api\/panel\/payment-page"/);
  assert.match(source, /fetch\("\/api\/panel\/wallet"/);
  assert.match(source, /fetch\("\/api\/panel\/orders"/);
  assert.match(source, /gateway_status/);
  assert.match(source, /gatewayConfig/);
  assert.match(source, /Partner Service ID VA/);
  assert.match(source, /Midtrans BI-SNAP/);
  assert.match(source, /DOKU Direct API/);
});

test("payment and channel images are uploaded before their URLs are persisted", () => {
  assert.match(source, /form\.set\("file", heroFile\)/);
  assert.match(source, /form\.set\("file", channelFile\)/);
  assert.match(source, /\/api\/panel\/media/);
  assert.match(source, /headerImageUrl/);
  assert.match(source, /setSelectedTransaction/);
  assert.doesNotMatch(source, /Simulasi UI|backend dihubungkan|saat backend aktif/);
});

test("payment page editor exposes every persisted customer-facing setting", () => {
  for (const field of [
    "eyebrow", "pendingTitle", "paidTitle", "failedTitle", "invoiceNoticeTitle",
    "invoiceNoticeText", "pendingStatusText", "paidStatusText", "failedStatusText",
    "payButtonText", "checkStatusButtonText", "checkInvoiceButtonText", "supportText",
    "supportUrl", "showStoreBrand", "showInvoiceNotice", "showOrderSummary",
    "showStatusBox", "showSupport",
  ]) assert.ok(source.includes(`pageSettings.${field}`), `payment page setting is not editable: ${field}`);
});

test("customer payment preview stays gateway-neutral", () => {
  assert.match(source, /Preview customer tanpa nama payment gateway/);
  assert.match(source, />QRIS</);
  assert.doesNotMatch(source, /QRIS DOKU/);
});
