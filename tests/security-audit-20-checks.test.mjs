import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("outbound integration URLs reject local and private SSRF targets", () => {
  const source = read("lib/server/outbound-url.ts");
  assert.match(source, /parsed\.protocol !== "https:"/);
  assert.match(source, /hostname === "localhost"/);
  assert.match(source, /a === 10/);
  assert.match(source, /a === 127/);
  assert.match(source, /a === 169 && b === 254/);
  assert.match(source, /a === 172 && b >= 16 && b <= 31/);
  assert.match(source, /a === 192 && b === 168/);
  assert.match(source, /normalized === "::1"/);
  assert.match(source, /normalized\.startsWith\("fc"\)/);
  assert.match(source, /normalized\.startsWith\("fd"\)/);
});

test("payment relay and email outbound sinks use the SSRF guard", () => {
  const doku = read("lib/server/doku-checkout.ts");
  const paymentConfig = read("lib/server/payment-mode-config.ts");
  const integrations = read("lib/server/integration-config.ts");
  const relay = read("lib/server/provider-relay.ts");
  const reset = read("lib/server/password-reset.ts");
  const vouchers = read("lib/server/vouchers.ts");
  const notifications = read("lib/server/transaction-notifications.ts");

  assert.match(doku, /safeHttpsOrigin/);
  assert.match(paymentConfig, /safeHttpsOrigin\(merged\.apiUrl/);
  assert.match(integrations, /validateProfileUrls/);
  assert.match(relay, /assertSafeHttpsUrl\(relayOrigin/);
  assert.match(reset, /assertSafeHttpsUrl\(configuredApiUrl/);
  assert.match(vouchers, /assertSafeHttpsUrl\(requireRuntimeValue/);
  assert.match(notifications, /assertSafeHttpsUrl\(config\.RESEND_API_URL/);
});

test("critical production paths use redacted structured error logging", () => {
  const criticalFiles = [
    "worker/index.ts",
    "lib/server/doku-reconciliation.ts",
    "lib/server/midtrans-reconciliation.ts",
    "app/api/payments/doku/callback/route.ts",
    "app/api/payments/midtrans/snap/notification/route.ts",
    "app/api/payments/wallet/create/route.ts",
    "app/api/orders/status/route.ts",
    "app/api/fulfillment/digiflazz/callback/route.ts",
    "lib/server/digiflazz-reconciliation.ts",
  ];
  for (const file of criticalFiles) {
    const source = read(file);
    assert.match(source, /logServerError/);
    assert.doesNotMatch(source, /console\.error\([^\n]*,\s*error\)/);
  }

  const logger = read("lib/server/safe-log.ts");
  assert.match(logger, /Bearer \[REDACTED\]/);
  assert.match(logger, /SENSITIVE_KEY/);
  assert.doesNotMatch(logger, /error\.stack/);
});

test("production CI includes safe live security probes", () => {
  const workflow = read(".github/workflows/validate.yml");
  assert.match(workflow, /lfamilia-production-security-smoke/);
  assert.match(workflow, /Cross-site mutation tidak ditolak/);
  assert.match(workflow, /CORS wildcard terdeteksi/);
  assert.match(workflow, /Admin API tidak tertutup/);
  assert.match(workflow, /strict-transport-security/);
  assert.match(workflow, /content-security-policy/);
});
