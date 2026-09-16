import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("nickname requirement is configured per product rather than inferred from a hard-coded slug", () => {
  const route = read("app/api/admin/product-input/route.ts");
  const checker = read("lib/server/nickname-check.ts");
  assert.match(route, /nicknameGameCode/);
  assert.match(route, /nickname_game_code/);
  assert.match(checker, /SELECT nickname_game_code, needs_server FROM products/);
  assert.match(checker, /if \(!gameCode\) return \{ supported: false/);
});

test("both checkout routes verify account server-side and never trust browser nickname", () => {
  for (const file of ["app/api/payments/auto/create/route.ts", "app/api/payments/wallet/create/route.ts"]) {
    const source = read(file);
    assert.match(source, /verifyNicknameForCheckout\(/);
    assert.match(source, /nickname: verifiedAccount\.nickname/);
    assert.doesNotMatch(source, /nickname: input\.nickname/);
  }
});

test("KokinPay game lookup sends documented game_code, id, optional server and never exposes api_key to browsers", () => {
  const checker = read("lib/server/nickname-check.ts");
  const publicRoute = read("app/api/nickname/route.ts");
  assert.match(checker, /https:\/\/api\.kokinpay\.com/);
  assert.match(checker, /game_code: input\.gameCode/);
  assert.match(checker, /api_key: input\.apiKey/);
  assert.match(checker, /check-nick-game/);
  assert.doesNotMatch(checker, /\/check-nickname/);
  assert.doesNotMatch(publicRoute, /KOKINPAY_API_KEY|api_key/);
});

test("KokinPay admin tools use the three documented endpoint paths", () => {
  const route = read("app/api/admin/nickname-tools/route.ts");
  assert.match(route, /check-region-mlbb/);
  assert.match(route, /check-nick-pln/);
  assert.match(route, /lookupKokinpayNickname/);
  assert.doesNotMatch(route, /\"check-pln\"/);
});

test("public nickname response never exposes integration identity", () => {
  const route = read("app/api/nickname/route.ts");
  assert.doesNotMatch(route, /provider:/);
  assert.doesNotMatch(route, /kokinpay/i);
});
