import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/product-input/route.ts"), "utf8");
const productsRoute = fs.readFileSync(path.join(root, "app/api/admin/products/route.ts"), "utf8");
const productContentRoute = fs.readFileSync(path.join(root, "app/api/admin/product-content/route.ts"), "utf8");
const panel = fs.readFileSync(path.join(root, "app/api/panel/[...path]/route.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-product-manager.tsx"), "utf8");

test("product input endpoint derives checkout fields and provider target on the server", () => {
  assert.match(route, /checkoutType: z\.enum\(\["id", "id-server"\]\)/);
  assert.match(route, /id: "destination"/);
  assert.match(route, /id: "server"/);
  assert.match(route, /needsServer \? "\{\{destination\}\}\{\{server\}\}" : "\{\{destination\}\}"/);
  assert.match(route, /requireAdminSession\(request, "admin"\)/);
  assert.match(route, /nicknameGameCode/);
  assert.match(route, /nickname_game_code/);
});

test("partial product-input updates do not silently clear an existing nickname game code", () => {
  assert.match(route, /nicknameGameCodeProvided = input\.nicknameGameCode !== undefined/);
  assert.match(route, /effectiveNicknameGameCode/);
  assert.match(route, /nickname_game_code = CASE WHEN \? = 1 THEN \? ELSE nickname_game_code END/);
  assert.match(route, /nicknameGameCodeProvided \? 1 : 0/);
  assert.match(route, /return Response\.json\(\{ ok: true, input: serialize\(updated\) \}/);
});

test("server-required KokinPay game codes cannot be saved with ID-only checkout", () => {
  assert.match(route, /kokinpayGameRequiresServer\(effectiveNicknameGameCode\)/);
  assert.match(route, /!needsServer/);
  assert.match(route, /membutuhkan Checkout Type ID \+ Server/);
});

test("full product writes cannot corrupt a server-required nickname checkout contract", () => {
  assert.match(productsRoute, /validateNicknameCheckoutContract/);
  assert.match(productsRoute, /SELECT nickname_game_code FROM products WHERE id = \? LIMIT 1/);
  assert.match(productsRoute, /kokinpayGameRequiresServer\(gameCode\)/);
  assert.match(productsRoute, /input\.needsServer/);
  assert.match(productsRoute, /field\.id\.toLowerCase\(\) === "server"/);
  assert.match(productsRoute, /\/\\\{\\\{server\\\}\\\}\/i\.test\(input\.targetTemplate\)/);
  assert.match(productsRoute, /await validateNicknameCheckoutContract\(input\.dbId, input\)/);
});

test("all admin product read paths apply nickname compatibility repair first", () => {
  assert.match(productsRoute, /await ensureKokinpayNicknameGameCodeBackfill\(\);[\s\S]*const products = await readProducts\(true\)/);
  assert.match(productContentRoute, /await ensureKokinpayNicknameGameCodeBackfill\(\);[\s\S]*const products = await readProducts\(true\)/);
});

test("panel exposes product-input and editor persists real values", () => {
  assert.match(panel, /"product-input": \{ GET: productInput\.GET, PATCH: productInput\.PATCH \}/);
  assert.match(manager, /fetch\(`\/api\/panel\/product-input\?slug=/);
  assert.match(manager, /fetch\("\/api\/panel\/product-input", \{/);
  assert.match(manager, /method: "PATCH"/);
  assert.match(manager, /Pengaturan input dan kode game berhasil disimpan ke backend/);
});
