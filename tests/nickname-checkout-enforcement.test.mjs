import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("nickname requirement is configured per product and legacy enabled products are backfilled once", () => {
  const route = read("app/api/admin/product-input/route.ts");
  const checker = read("lib/server/nickname-check.ts");
  const config = read("lib/server/nickname-config.ts");
  const migration = read("drizzle/0032_kokinpay_nickname_game_codes.sql");
  const publicProducts = read("app/api/products/route.ts");

  assert.match(route, /nicknameGameCode/);
  assert.match(route, /nickname_game_code/);
  assert.match(route, /ensureKokinpayNicknameGameCodeBackfill/);
  assert.match(checker, /SELECT nickname_game_code, needs_server FROM products/);
  assert.match(checker, /if \(!gameCode\) return \{ supported: false/);
  assert.match(checker, /ensureKokinpayNicknameGameCodeBackfill/);
  assert.match(publicProducts, /ensureKokinpayNicknameGameCodeBackfill/);

  assert.match(config, /kokinpay_nickname_game_code_backfill_0032/);
  assert.match(config, /SELECT completed_at FROM one_time_operations/);
  assert.match(config, /if \(completed\?\.completed_at\) return/);
  assert.match(config, /WHEN 'mobile-legends' THEN 'mobile-legends'/);
  assert.match(config, /WHEN 'wild-rift' THEN 'league-of-legends-wild-rift'/);
  assert.match(migration, /UPDATE products/);
  assert.match(migration, /WHEN 'mobile-legends' THEN 'mobile-legends'/);
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
  assert.match(checker, /game_code: gameCode/);
  assert.match(checker, /api_key: apiKey/);
  assert.match(checker, /check-nick-game/);
  assert.doesNotMatch(checker, /\/check-nickname/);
  assert.doesNotMatch(publicRoute, /KOKINPAY_API_KEY|api_key/);
});

test("Mobile Legends must pass nickname and region checks before verification succeeds", () => {
  const checker = read("lib/server/nickname-check.ts");
  const adminRoute = read("app/api/admin/nickname-tools/route.ts");
  assert.match(checker, /MLBB_GAME_CODE = "mobile-legends"/);
  assert.match(checker, /check-region-mlbb/);
  assert.match(checker, /Promise\.all\(/);
  assert.match(checker, /tidak mengembalikan region Mobile Legends/);
  assert.match(adminRoute, /gameCode: "mobile-legends"/);
  assert.match(adminRoute, /!result\.nickname \|\| !result\.country/);
});

test("KokinPay admin tools use documented paths and fail closed on empty PLN results", () => {
  const route = read("app/api/admin/nickname-tools/route.ts");
  assert.match(route, /check-nick-pln/);
  assert.match(route, /lookupKokinpayNickname/);
  assert.match(route, /if \(!customerName\)/);
  assert.match(route, /tidak mengembalikan nama pelanggan/);
  assert.doesNotMatch(route, /\"check-pln\"/);
});

test("public nickname response never exposes integration identity", () => {
  const route = read("app/api/nickname/route.ts");
  assert.doesNotMatch(route, /provider:/);
  assert.doesNotMatch(route, /kokinpay/i);
});
