import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("nickname policy belongs to backend and distinguishes supported games", () => {
  const policy = read("lib/nickname-policy.ts");
  assert.match(policy, /"mobile-legends": \{ supported: true, needsServer: true/);
  assert.match(policy, /unsupportedPolicy/);
  assert.match(policy, /supported: false/);
});

test("both checkout routes verify account server-side and never trust browser nickname", () => {
  for (const file of [
    "app/api/payments/auto/create/route.ts",
    "app/api/payments/wallet/create/route.ts",
  ]) {
    const source = read(file);
    assert.match(source, /verifyNicknameForCheckout\(/);
    assert.match(source, /nickname: verifiedAccount\.nickname/);
    assert.doesNotMatch(source, /nickname: input\.nickname/);
    assert.doesNotMatch(source, /nickname: z\.string/);
  }
});

test("unsupported games can checkout while supported accounts fail closed", () => {
  const checker = read("lib/server/nickname-check.ts");
  assert.match(checker, /if \(!target\.policy\.supported\)/);
  assert.match(checker, /supported: false, nickname: null/);
  assert.match(checker, /Checkout sementara tidak dapat dilanjutkan/);
  assert.match(checker, /NicknameValidationError/);
});

test("Melostore nickname endpoint accepts either API origin or h2h base path", () => {
  const checker = read("lib/server/nickname-check.ts");
  assert.match(checker, /melostoreNicknameEndpoint/);
  assert.match(checker, /\/api\\\/v1\\\/h2h\\\/check-nickname/);
  assert.match(checker, /`\$\{base\}\/check-nickname`/);
});

test("Melostore errors distinguish missing accounts from service and validation failures", () => {
  const checker = read("lib/server/nickname-check.ts");
  const start = checker.indexOf("function throwMelostoreError");
  const end = checker.indexOf("export async function verifyNicknameForCheckout", start);
  const melostoreErrors = checker.slice(start, end);
  assert.ok(start >= 0 && end > start);
  assert.match(melostoreErrors, /category === "not_found" \|\| code === 4001/);
  assert.match(melostoreErrors, /category === "validation" \|\| code === 4006/);
  assert.match(melostoreErrors, /"maintenance"/);
  assert.match(melostoreErrors, /status === 404 && !category && code === null/);
  assert.doesNotMatch(melostoreErrors, /\[400, 404, 422\]\.includes\(upstream\.status\)/);
});

test("Mobile Legends false not-found from primary is cross-checked before checkout is blocked", () => {
  const checker = read("lib/server/nickname-check.ts");
  assert.match(checker, /class NicknameNotFoundError extends NicknameValidationError/);
  assert.match(checker, /error instanceof NicknameNotFoundError/);
  assert.match(checker, /lookupSecondaryMobileLegends/);
  assert.match(checker, /api\.isan\.eu\.org\/nickname/);
  assert.match(checker, /decode", "false"/);
});

test("public nickname response never exposes integration identity", () => {
  const route = read("app/api/nickname/route.ts");
  assert.doesNotMatch(route, /provider:/);
  assert.doesNotMatch(route, /melostore/i);
});
