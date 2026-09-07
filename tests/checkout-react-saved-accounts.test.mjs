import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("saved game accounts are loaded and rendered from checkout React state", () => {
  const checkout = read("app/checkout/page.tsx");
  const layout = read("app/checkout/layout.tsx");

  assert.match(checkout, /type SavedGameAccount =/);
  assert.match(checkout, /const \[savedGameAccounts, setSavedGameAccounts\]/);
  assert.match(checkout, /\/api\/account\/game-accounts\?product=/);
  assert.match(checkout, /savedGameAccounts\.map\(\(saved\)/);
  assert.match(checkout, /Akun game tersimpan/);
  assert.doesNotMatch(layout, /CheckoutSavedGameAccounts/);
  assert.equal(
    fs.existsSync(path.join(root, "components/checkout-saved-game-accounts.tsx")),
    false,
  );
});

test("saved account values are mapped by field id instead of DOM index", () => {
  const checkout = read("app/checkout/page.tsx");
  const section = checkout.slice(
    checkout.indexOf("function chooseSavedGameAccount"),
    checkout.indexOf("function choosePackageGroup"),
  );

  assert.match(section, /new Map\(/);
  assert.match(section, /saved\.values\.map\(\(item\) => \[item\.id, item\.value\]\)/);
  assert.match(section, /savedById\.get\(field\.id\)/);
  assert.doesNotMatch(section, /querySelector|HTMLInputElement|dispatchEvent/);
});
