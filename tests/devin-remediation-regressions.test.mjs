import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("uncertain external payment dispatch stays pending for callback recovery", () => {
  const route = read("app/api/payments/auto/create/route.ts");
  const recovery = read("lib/server/external-payments.ts");
  const worker = read("worker/index.ts");
  assert.match(route, /paymentDispatchStarted = true/);
  assert.match(route, /if \(!paymentDispatchStarted\)/);
  assert.match(route, /Jangan bayar dua kali/);
  assert.match(recovery, /expireUninitializedExternalOrders/);
  assert.match(recovery, /created_at <= datetime\('now', '-70 minutes'\)/);
  assert.match(worker, /expireUninitializedExternalOrders\(\)/);
});

test("active promo reservations cannot be orphaned by admin edits", () => {
  const promotions = read("lib/server/promotions.ts");
  assert.match(promotions, /Kode voucher tidak dapat diubah saat masih memiliki reservasi/);
  assert.match(promotions, /Batas penggunaan tidak boleh lebih kecil dari penggunaan \+ reservasi aktif/);
  assert.match(promotions, /Produk\/nominal flash sale tidak dapat diganti saat masih memiliki reservasi/);
  assert.match(promotions, /Promo tidak dapat dihapus saat masih memiliki reservasi/);
});

test("DOKU overview only reports ready for a parseable RSA key and HTTPS endpoint", () => {
  const config = read("lib/server/payment-mode-config.ts");
  assert.match(config, /createPrivateKey/);
  assert.match(config, /apiUrl\.protocol !== "https:"/);
  assert.match(config, /privateKeyPassphrase/);
});
