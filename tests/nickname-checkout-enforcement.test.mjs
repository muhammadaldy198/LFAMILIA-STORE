import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("nickname requirement is configured per product and legacy repairs have independent markers", () => {
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

  assert.match(config, /GAME_CODE_BACKFILL_OPERATION_KEY = "kokinpay_nickname_game_code_backfill_0032"/);
  assert.match(config, /GENSHIN_SERVER_REPAIR_OPERATION_KEY = "kokinpay_genshin_server_input_repair_0032_v2"/);
  assert.match(config, /gameBackfillCompleted && genshinRepairCompleted/);
  assert.match(config, /if \(!gameBackfillCompleted\)/);
  assert.match(config, /if \(!genshinRepairCompleted\)/);
  assert.match(config, /WHEN 'mobile-legends' THEN 'mobile-legends'/);
  assert.match(config, /WHEN 'wild-rift' THEN 'league-of-legends-wild-rift'/);
  assert.match(config, /WHERE slug = 'genshin-impact'[\s\S]*nickname_game_code = 'genshin-impact'/);
  assert.match(config, /SET needs_server = 1/);
  assert.match(config, /target_template = '\{\{destination\}\}\{\{server\}\}'/);
  assert.match(config, /"id":"server","label":"Server"/);

  assert.match(migration, /UPDATE products/);
  assert.match(migration, /WHEN 'mobile-legends' THEN 'mobile-legends'/);
  assert.match(migration, /WHERE slug = 'genshin-impact'[\s\S]*nickname_game_code = 'genshin-impact'/);
  assert.match(migration, /SET needs_server = 1/);
  assert.match(migration, /target_template = '\{\{destination\}\}\{\{server\}\}'/);
  assert.match(migration, /"id":"server","label":"Server"/);
  assert.match(migration, /kokinpay_nickname_game_code_backfill_0032/);
  assert.match(migration, /kokinpay_genshin_server_input_repair_0032_v2/);
});

test("both checkout routes verify account server-side and never trust browser nickname", () => {
  for (const file of ["app/api/payments/auto/create/route.ts", "app/api/payments/wallet/create/route.ts"]) {
    const source = read(file);
    assert.match(source, /verifyNicknameForCheckout\(/);
    assert.match(source, /nickname: verifiedAccount\.nickname/);
    assert.doesNotMatch(source, /nickname: input\.nickname/);
  }
});

test("KokinPay game lookup uses active v1 routes, sends server-side credentials, and keeps them out of public responses", () => {
  const checker = read("lib/server/nickname-check.ts");
  const publicRoute = read("app/api/nickname/route.ts");
  assert.match(checker, /https:\/\/api\.kokinpay\.com/);
  assert.match(checker, /KOKINPAY_GAME_NICKNAME_PATH = "\/v1\/check-nickname"/);
  assert.match(checker, /KOKINPAY_MLBB_REGION_PATH = "\/v1\/check-region"/);
  assert.match(checker, /game_code: gameCode/);
  assert.match(checker, /api_key: apiKey/);
  assert.match(checker, /kokinpayGameRequiresServer\(gameCode\)/);
  assert.doesNotMatch(checker, /"\/check-nick-game"|"\/check-region-mlbb"/);
  assert.doesNotMatch(publicRoute, /KOKINPAY_API_KEY|api_key/);
});

test("Mobile Legends must pass nickname and region checks before verification succeeds", () => {
  const checker = read("lib/server/nickname-check.ts");
  const adminRoute = read("app/api/admin/nickname-tools/route.ts");
  assert.match(checker, /MLBB_GAME_CODE = "mobile-legends"/);
  assert.match(checker, /Promise\.all\(/);
  assert.match(checker, /KOKINPAY_MLBB_REGION_PATH/);
  assert.match(checker, /tidak mengembalikan region Mobile Legends/);
  assert.match(adminRoute, /gameCode: "mobile-legends"/);
  assert.match(adminRoute, /!result\.nickname \|\| !result\.country/);
});

test("KokinPay PLN tool uses active v1 route and fails closed on empty results", () => {
  const route = read("app/api/admin/nickname-tools/route.ts");
  assert.match(route, /https:\/\/api\.kokinpay\.com\/v1\/check-pln/);
  assert.match(route, /lookupKokinpayNickname/);
  assert.match(route, /if \(!customerName\)/);
  assert.match(route, /tidak mengembalikan nama pelanggan/);
  assert.doesNotMatch(route, /api\.kokinpay\.com\/check-nick-pln/);
});

test("KokinPay nickname and PLN flows share one HTTP failure classifier", () => {
  const checker = read("lib/server/nickname-check.ts");
  const adminRoute = read("app/api/admin/nickname-tools/route.ts");
  assert.match(checker, /classifyKokinpayFailure/);
  assert.match(checker, /const kind = classifyKokinpayFailure\(status\)/);
  assert.match(adminRoute, /classifyKokinpayFailure/);
  assert.match(adminRoute, /const kind = classifyKokinpayFailure\(response\.status\)/);
  assert.doesNotMatch(checker, /data\.status === false/);
  assert.doesNotMatch(adminRoute, /payload\.status === false/);
});

test("public verification never forwards raw upstream KokinPay error messages", () => {
  const checker = read("lib/server/nickname-check.ts");
  const classifierStart = checker.indexOf("function throwKokinpayError");
  const classifierEnd = checker.indexOf("async function postKokinpay", classifierStart);
  assert.ok(classifierStart >= 0 && classifierEnd > classifierStart);
  const classifier = checker.slice(classifierStart, classifierEnd);
  assert.doesNotMatch(classifier, /data\.message|message\s*\|\|/);
  assert.match(checker, /throwKokinpayError\(upstream\.status\)/);
});

test("public nickname response is provider-neutral, minimal, and non-cacheable", () => {
  const route = read("app/api/nickname/route.ts");
  assert.doesNotMatch(route, /provider:/);
  assert.doesNotMatch(route, /kokinpay/i);
  assert.doesNotMatch(route, /userId:\s*input\.userId|server:\s*input\.server|game:\s*input\.game/);
  assert.match(route, /"Cache-Control": "no-store"/);
});

test("admin nickname tools reject cross-origin mutations", () => {
  const route = read("app/api/admin/nickname-tools/route.ts");
  assert.match(route, /rejectCrossOriginMutation\(request\)/);
  assert.match(route, /requireAdminSession\(request, "admin"\)/);
});
