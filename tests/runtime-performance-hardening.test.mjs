import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("public reads warm runtime and schema repair outside the critical response path", () => {
  const worker = read("worker/index.ts");
  const panel = read("app/api/panel/[...path]/route.ts");

  assert.match(worker, /RUNTIME_HYDRATION_TTL_MS = 15_000/);
  assert.match(worker, /requestNeedsHydratedRuntime/);
  assert.match(worker, /ctx\.waitUntil\(\s*hydrateRuntime\(env\)\.catch/);
  assert.match(worker, /if \(isReadOnlyRequest\(request\)\)[\s\S]*ctx\.waitUntil\([\s\S]*ensureLegacyDatabaseColumns/);
  assert.match(worker, /else \{\s*await ensureLegacyDatabaseColumns\(\)\.catch/);
  assert.match(panel, /if \(!\["GET", "HEAD", "OPTIONS"\]\.includes\(method\)\) \{\s*await ensureLegacyDatabaseColumns\(\)/);
});

test("payment runtime initialization and readiness avoid repeated D1 work", () => {
  const modes = read("lib/server/payment-mode-config.ts");
  const publicMethods = read("app/api/payment-methods/route.ts");
  const adminMethods = read("app/api/admin/payment-methods/route.ts");
  const router = read("lib/server/payment-router.ts");

  assert.match(modes, /let schemaPromise: Promise<void> \| null = null/);
  assert.match(modes, /schemaPromise = db\.batch\(/);
  assert.match(router, /getConfiguredGatewayBaseReadiness/);
  assert.match(publicMethods, /activeGatewayNames/);
  assert.match(publicMethods, /getConfiguredGatewayBaseReadiness/);
  assert.doesNotMatch(publicMethods, /getConfiguredGatewayReadiness/);
  assert.match(publicMethods, /public, max-age=10, s-maxage=10, stale-while-revalidate=20/);
  assert.match(adminMethods, /gatewayState/);
  assert.doesNotMatch(adminMethods, /channels\.map\(async/);
});

test("storefront and dashboard reads use bounded cache and provider latency", () => {
  const storefrontHook = read("hooks/use-storefront.ts");
  const storefrontRoute = read("app/api/storefront/route.ts");
  const digiflazz = read("lib/server/providers/digiflazz.ts");
  const summary = read("app/api/admin/summary/route.ts");

  assert.doesNotMatch(storefrontHook, /fetch\("\/api\/storefront", \{ cache: "no-store" \}\)/);
  assert.match(storefrontRoute, /public, max-age=30, s-maxage=60, stale-while-revalidate=120/);
  assert.match(digiflazz, /getDigiflazzBalance\(options: \{ timeoutMs\?: number \} = \{\}\)/);
  assert.match(summary, /getDigiflazzBalance\(\{ timeoutMs: 1_500 \}\)/);
});
