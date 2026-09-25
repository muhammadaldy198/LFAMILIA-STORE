import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import assert from "node:assert/strict";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("Nordic Indigo theme is scoped to customer storefront", () => {
  const layout = read("components/store-layout.tsx");
  const css = read("app/globals.css");
  const panelLogin = read("components/panel-login.tsx");
  const ownerSetup = read("app/admin/setup/page.tsx");

  assert.match(layout, /customerTheme = true/);
  assert.match(layout, /customer-theme site-shell/);
  assert.match(css, /LFAMILIA customer theme — Nordic Indigo \+ Vanilla Mist/);
  assert.match(css, /--primary: #263baa/);
  assert.match(css, /--foreground: #fff4d6/);
  assert.match(panelLogin, /<StoreLayout customerTheme=\{false\}>/);
  assert.match(ownerSetup, /<StoreLayout customerTheme=\{false\}>/);
});

test("customer password recovery pages use the customer theme", () => {
  assert.match(read("app/forgot-password/page.tsx"), /className="customer-theme /);
  assert.match(read("app/reset-password/page.tsx"), /className="customer-theme /);
});


test("customer home removes quick menu and tightens display spacing", () => {
  const home = read("app/page.tsx");
  const css = read("app/globals.css");

  assert.doesNotMatch(home, /QuickTools|Menu cepat|Semua yang kamu butuhkan/);
  assert.match(css, /\.customer-theme \.eyebrow[\s\S]*letter-spacing: 0\.11em/);
  assert.match(css, /\.customer-theme \.store-brand-subtitle[\s\S]*letter-spacing: 0\.14em/);
});
