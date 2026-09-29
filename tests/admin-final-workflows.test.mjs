import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const read = (file) => fs.readFileSync(path.join(process.cwd(), file), "utf8");

test("content tabs show one section and switch its editor", () => {
  const source = read("components/admin-experience-manager.tsx");
  for (const kind of ["banner", "popup", "news", "review", "faq"]) {
    assert.match(source, new RegExp(`activeTab === "${kind}" &&`));
  }
  assert.match(source, /onClick=\{\(\) => \{ setActiveTab\(tab\.value\);[\s\S]*?setEditor\(\{ kind: tab\.value/);
  assert.match(source, /<EditorPanel editor=\{editor\}/);
});

test("team Refresh reloads uncached backend data and reports completion", () => {
  const source = read("components/admin-operations-workspaces.tsx");
  const team = source.slice(source.indexOf("export function AdminTeamWorkspace"), source.indexOf("export function AdminSettingsWorkspace"));
  assert.match(team, /async function load\(showNotice = false\)/);
  assert.match(team, /"\/api\/admin\/team", \{ cache: "no-store" \}/);
  assert.match(team, /setUsers\(team\.users\)/);
  assert.match(team, /if \(showNotice\) setNotice\(/);
  assert.match(team, /finally \{ setRefreshing\(false\); \}/);
  assert.match(team, /onClick=\{\(\) => void load\(true\)\}/);
  assert.match(team, /refreshing \? "animate-spin"/);
});

test("every overlaid Search icon leaves input text clear", () => {
  const files = [
    "components/home-product-browser.tsx", "components/admin-dashboard.tsx",
    "components/admin-kokinpay-workspace.tsx", "components/admin-order-manager.tsx",
    "components/admin-customer-workspace.tsx", "components/admin-product-manager.tsx",
    "components/admin-operations-workspaces.tsx", "components/admin-digiflazz-workspace.tsx",
    "components/admin-payment-workspace.tsx",
  ];
  let checked = 0;
  for (const file of files) {
    const source = read(file);
    for (const match of source.matchAll(/<Search\b[^>]*>/g)) {
      if (!match[0].includes("absolute")) continue;
      assert.match(match[0], /pointer-events-none/, `${file}: icon must not intercept input focus`);
      const following = source.slice(match.index + match[0].length, match.index + match[0].length + 1200);
      const input = following.match(/<(?:input|Input)\b[\s\S]*?\/>/)?.[0];
      assert.ok(input, `${file}: overlaid search needs input`);
      assert.match(input, /!?pl-(?:\d+|\[\d+px\])/, `${file}: input needs left padding`);
      checked++;
    }
  }
  assert.ok(checked >= 10);
});
