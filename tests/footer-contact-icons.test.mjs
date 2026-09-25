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


test("customer footer brand banner is full viewport width on every breakpoint", () => {
  const footer = source;
  const layout = fs.readFileSync(path.join(process.cwd(), "components/store-layout.tsx"), "utf8");

  assert.match(footer, /data-sitewide-footer-banner/);
  assert.match(footer, /relative left-1\/2 w-screen -translate-x-1\/2/);
  assert.match(footer, /LFAMILIA/);
  assert.match(footer, /STORE/);
  assert.match(footer, /settings\.logoUrl/);
  assert.match(footer, /min-h-\[118px\]/);
  assert.doesNotMatch(footer, /lfamilia-footer-banner\.webp/);
  assert.match(layout, /<StoreFooter showBrandBanner=\{customerTheme\} \/>/);
});


test("LFAMILIA banner is the first visual block inside the footer", () => {
  const footerStart = source.indexOf('<footer className="bg-[#05070b]">');
  const banner = source.indexOf('data-sitewide-footer-banner');
  const footerBody = source.indexOf('border-t border-white/[0.08]');
  assert.ok(footerStart >= 0);
  assert.ok(banner > footerStart);
  assert.ok(footerBody > banner);
});
