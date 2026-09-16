import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const manager = fs.readFileSync(path.join(root, "components/admin-integration-workspace.tsx"), "utf8");
const route = fs.readFileSync(path.join(root, "app/api/admin/integrations/route.ts"), "utf8");
const config = fs.readFileSync(path.join(root, "lib/server/integration-config.ts"), "utf8");
const dokuConnection = fs.readFileSync(path.join(root, "lib/server/doku-connection-test.ts"), "utf8");

test("DOKU Midtrans and DigiFlazz environments are selected from Admin Dashboard", () => {
  assert.match(manager, /DOKU Direct API/);
  assert.match(manager, /Midtrans BI-SNAP/);
  assert.match(manager, /Kredensial Digiflazz/);
  assert.match(manager, /midtransEnvironment/);
});

test("integration credentials stay owner-only and encrypted", () => {
  assert.match(route, /requireAdminSession\(request, "owner"\)/);
  assert.match(config, /INTEGRATION_ENCRYPTION_KEY/);
  assert.match(config, /encryptConfig\(/);
  assert.match(config, /decryptConfig\(/);
  assert.match(manager, /berhasil disimpan terenkripsi di backend/);
  assert.match(manager, /type=\{show \? "text" : "password"\}/);
  assert.match(manager, /action: "save_profile"/);
  assert.match(manager, /action: "save_selections"/);
});

test("Midtrans payment secrets are dashboard-managed and never returned to the frontend", () => {
  assert.match(config, /"midtrans:direct"/);
  for (const field of ["clientSecret", "privateKey", "midtransPublicKey", "partnerId", "channelId"]) {
    assert.match(config, new RegExp(field));
  }
  assert.match(manager, /Tersimpan — isi hanya untuk mengganti/);
  assert.doesNotMatch(config, /return .*clientSecret/);
});

test("dashboard-managed credentials fail closed instead of using stale Cloudflare provider secrets", () => {
  assert.match(config, /withoutDashboardManagedRuntime/);
  for (const prefix of ["DOKU_", "MIDTRANS_", "DIGIFLAZZ_", "KOKINPAY_", "MELOSTORE_", "RESEND_", "PROVIDER_RELAY_"]) {
    assert.match(config, new RegExp(`"${prefix}"`));
  }
  assert.match(config, /return systemOnly as T/);
  assert.match(config, /put\(target, "KOKINPAY_API_KEY", config\.apiKey\)/);
  assert.doesNotMatch(config, /applyMelostoreConfig/);
  assert.doesNotMatch(config, /IPAYMU_|VIPPAYMENT_/);
});

test("DOKU Admin form binds official API origin to the selected environment", () => {
  assert.match(manager, /https:\/\/api-sandbox\.doku\.com/);
  assert.match(manager, /https:\/\/api\.doku\.com/);
  assert.match(manager, /dokuApiUrlForEnvironment\(dokuEnvironment\)/);
  assert.match(manager, /Direct API Base URL/);
  assert.match(manager, /readOnly/);
  assert.match(manager, /options=\{\["sandbox", "production"\]\}/);
  assert.match(route, /apiUrl: dokuApiOrigin\(input\.environment\)/);
});

test("DOKU encrypted private key passphrase is editable and persisted securely", () => {
  assert.match(manager, /Private Key Passphrase/);
  assert.match(manager, /privateKeyPassphrase: values\.dokuPrivateKeyPassphrase/);
  assert.match(manager, /dokuPrivateKeyPassphrase: ""/);
  assert.match(config, /"privateKeyPassphrase"/);
  assert.match(config, /PRIVATE_KEY_PASSPHRASE/);
});

test("DOKU connection test performs only a real SNAP B2B token request", () => {
  assert.match(route, /action: z\.literal\("test_doku"\)/);
  assert.match(route, /testDokuB2BConnection\(input\.environment\)/);
  assert.match(manager, /action: "test_doku"/);
  assert.match(manager, /Tes Koneksi DOKU/);
  assert.match(dokuConnection, /authorization\/v1\/access-token\/b2b/);
  assert.match(dokuConnection, /RSA-SHA256/);
  assert.match(dokuConnection, /"x-client-key"/);
  assert.match(dokuConnection, /"x-timestamp"/);
  assert.match(dokuConnection, /"x-signature"/);
  assert.match(dokuConnection, /grantType: "client_credentials"/);
  assert.doesNotMatch(dokuConnection, /qr-mpm-generate|payment-host-to-host|transfer-va\/create-va/);
  assert.doesNotMatch(dokuConnection, /accessToken\s*:/);
});
