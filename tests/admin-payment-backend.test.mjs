import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-payment-workspace.tsx"), "utf8");

test("DOKU workspace loads and saves live channel and checkout configuration", () => {
  assert.match(source, /fetch\("\/api\/panel\/payment-methods"/);
  assert.match(source, /fetch\("\/api\/panel\/payment-page"/);
  assert.match(source, /fetch\("\/api\/panel\/wallet"/);
  assert.match(source, /fetch\("\/api\/panel\/orders"/);
  assert.match(source, /method: "POST"/);
  assert.match(source, /method: "PUT"/);
  assert.match(source, /DOKU sebagai satu-satunya jalur eksternal/);
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

test("configuration checks do not pretend to be live DOKU tests", () => {
  assert.match(source, /Periksa Konfigurasi/);
  assert.match(source, /Uji koneksi dan transaksi live dilakukan pada tahap pra-peluncuran/);
  assert.doesNotMatch(source, /kunci tanda tangan valid/);
});
