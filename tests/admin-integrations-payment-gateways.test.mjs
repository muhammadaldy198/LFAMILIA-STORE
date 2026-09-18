import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("Integrasi owns DOKU Direct API and Midtrans Snap credentials", () => {
  const integration = read("components/admin-integration-workspace.tsx");
  assert.match(integration, /DOKU Direct API/);
  assert.match(integration, /Midtrans Snap/);
  assert.match(integration, /\/api\/panel\/payment-routing/);
  assert.match(integration, /provider: "doku"/);
  assert.match(integration, /mode: "direct"/);
  assert.match(integration, /provider: "midtrans"/);
  assert.match(integration, /mode: "snap"/);
  assert.match(integration, /dokuClientId/);
  assert.match(integration, /dokuSecretKey/);
  assert.match(integration, /dokuPrivateKey/);
  assert.match(integration, /midtransServerKey/);
  assert.match(integration, /midtransClientKey/);
});

test("Integrasi Periksa button is enabled for Google and payment gateway tabs", () => {
  const integration = read("components/admin-integration-workspace.tsx");
  assert.match(integration, /fetch\("\/api\/auth\/google\/status"/);
  assert.match(integration, /Google Login aktif/);
  assert.match(integration, /Konfigurasi DOKU Direct API/);
  assert.match(integration, /Konfigurasi Midtrans Snap/);
  assert.doesNotMatch(integration, /tab !== "Digiflazz" && tab !== "Relay & Keamanan"/);
});

test("payment credential backend keeps DOKU and Midtrans values encrypted", () => {
  const config = read("lib/server/payment-mode-config.ts");
  assert.match(config, /INTEGRATION_ENCRYPTION_KEY/);
  assert.match(config, /encryptWithSecret/);
  assert.match(config, /integration_profiles/);
  assert.match(config, /direct: \["clientId", "secretKey", "privateKey"/);
  assert.match(config, /snap: \["serverKey", "clientKey"\]/);
});
