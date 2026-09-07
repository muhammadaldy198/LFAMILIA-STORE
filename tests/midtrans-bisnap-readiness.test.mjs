import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(
  path.join(process.cwd(), "lib/server/midtrans.ts"),
  "utf8",
);

test("BI-SNAP saved readiness requires configured static relay without live probing", () => {
  assert.match(source, /if \(!isProviderRelayConfigured\("midtrans-bisnap"\)\)/);
  assert.match(source, /Midtrans BI-SNAP memerlukan relay ber-IP statis/);

  const readinessStart = source.indexOf("export function getMidtransReadiness");
  const operationalStart = source.indexOf("export async function getMidtransOperationalReadiness");
  const readinessBody = source.slice(readinessStart, operationalStart);
  assert.doesNotMatch(readinessBody, /probeProviderRelay/);
});
