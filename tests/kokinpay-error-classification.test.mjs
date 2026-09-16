import assert from "node:assert/strict";
import path from "node:path";
import test from "node:test";
import { pathToFileURL } from "node:url";

const root = process.cwd();
const { classifyKokinpayFailure } = await import(
  `${pathToFileURL(path.join(root, "lib/server/kokinpay-errors.ts")).href}?test=${Date.now()}`
);

test("KokinPay validation failures are limited to explicit bad-input statuses", () => {
  assert.equal(classifyKokinpayFailure(400), "validation");
  assert.equal(classifyKokinpayFailure(404), "validation");
});

test("KokinPay credential failures stay service-side", () => {
  assert.equal(classifyKokinpayFailure(401), "authentication");
  assert.equal(classifyKokinpayFailure(403), "authentication");
});

test("KokinPay outages and throttling remain retryable service failures", () => {
  for (const status of [0, 408, 409, 422, 429, 500, 502, 503, 504]) {
    assert.equal(classifyKokinpayFailure(status), "service", `status ${status}`);
  }
});
