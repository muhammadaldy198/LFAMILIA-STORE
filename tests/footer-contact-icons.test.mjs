import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/store-footer.tsx"), "utf8");

test("footer contact links use recognizable platform logos and configured contact URLs", () => {
  for (const name of ["WhatsAppIcon", "InstagramIcon", "Mail", "DiscordIcon"]) {
    assert.match(source, new RegExp(name), `missing footer contact logo: ${name}`);
  }
  assert.match(source, /settings\.supportWhatsapp/);
  assert.match(source, /settings\.instagramUrl/);
  assert.match(source, /settings\.supportEmail/);
  assert.match(source, /settings\.discordUrl/);
  assert.doesNotMatch(source, /Camera|Headphones|MessageCircle|MessagesSquare/);
});
