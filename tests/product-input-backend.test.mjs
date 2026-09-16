import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/product-input/route.ts"), "utf8");
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

test("panel exposes product-input and editor persists real values", () => {
  assert.match(panel, /"product-input": \{ GET: productInput\.GET, PATCH: productInput\.PATCH \}/);
  assert.match(manager, /fetch\(`\/api\/panel\/product-input\?slug=/);
  assert.match(manager, /fetch\("\/api\/panel\/product-input", \{/);
  assert.match(manager, /method: "PATCH"/);
  assert.match(manager, /Pengaturan input dan kode game berhasil disimpan ke backend/);
});
