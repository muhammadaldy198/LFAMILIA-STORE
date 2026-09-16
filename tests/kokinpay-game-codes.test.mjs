import assert from "node:assert/strict";
import path from "node:path";
import test from "node:test";
import { pathToFileURL } from "node:url";

const root = process.cwd();
const { KOKINPAY_GAME_CODES, kokinpayGameRequiresServer } = await import(
  `${pathToFileURL(path.join(root, "lib/kokinpay-game-codes.ts")).href}?test=${Date.now()}`
);

test("KokinPay game metadata keeps server requirements in one shared source", () => {
  assert.ok(KOKINPAY_GAME_CODES.length > 20);
  assert.equal(kokinpayGameRequiresServer("mobile-legends"), true);
  assert.equal(kokinpayGameRequiresServer("genshin-impact"), true);
  assert.equal(kokinpayGameRequiresServer("honkai-star-rail"), true);
  assert.equal(kokinpayGameRequiresServer("free-fire"), false);
  assert.equal(kokinpayGameRequiresServer("unknown-future-game"), false);
  assert.equal(kokinpayGameRequiresServer("  GENSHIN-IMPACT  "), true);
});
