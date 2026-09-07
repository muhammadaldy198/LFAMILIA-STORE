import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import test, { after } from "node:test";
import { fileURLToPath } from "node:url";

import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { createServer } from "vite";

const root = fileURLToPath(new URL("..", import.meta.url));
const vite = await createServer({
  appType: "custom",
  configFile: false,
  root,
  resolve: { alias: { "@": root } },
  server: { middlewareMode: true },
});

after(async () => {
  await vite.close();
});

async function readCssTree(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const contents = await Promise.all(
    entries.map(async (entry) => {
      const entryPath = path.join(directory, entry.name);
      if (entry.isDirectory()) {
        return readCssTree(entryPath);
      }
      return entry.name.endsWith(".css") ? readFile(entryPath, "utf8") : "";
    }),
  );
  return contents.join("\n");
}

test("emits the catalog's animation and scrolling utilities", async () => {
  const css = await readCssTree(path.join(root, "dist"));

  assert.match(css, /--tw-enter-opacity/);
  assert.match(css, /scrollbar-width:\s*thin/);
  assert.match(css, /scrollbar-width:\s*none/);
  assert.match(css, /scrollbar-gutter:\s*stable/);
  assert.match(css, /scroll-fade-reveal-b/);
  assert.match(css, /mask-image:/);
  assert.match(css, /tw-shimmer/);
  assert.match(css, /prefers-reduced-motion:\s*reduce/);
});

test("forwards progress semantics to the primitive", async () => {
  const { Progress } = await vite.ssrLoadModule("/components/ui/progress.tsx");
  const html = renderToStaticMarkup(React.createElement(Progress, { value: 37 }));

  assert.match(html, /aria-valuenow="37"/);
  assert.match(html, /aria-valuetext="37%"/);
  assert.match(html, /data-state="loading"/);
});

test("renders sidebar skeletons deterministically", async () => {
  const { SidebarMenuSkeleton } = await vite.ssrLoadModule(
    "/components/ui/sidebar.tsx",
  );
  const first = renderToStaticMarkup(React.createElement(SidebarMenuSkeleton));
  const second = renderToStaticMarkup(React.createElement(SidebarMenuSkeleton));

  assert.equal(first, second);
  assert.match(first, /--skeleton-width:70%/);
});

test("accepts managed and HTTPS image URLs while rejecting unsafe schemes", async () => {
  const { isAllowedMediaUrl } = await vite.ssrLoadModule("/lib/media-url.ts");

  assert.equal(isAllowedMediaUrl("/brand/lfamilia-pixel-logo.webp"), true);
  assert.equal(isAllowedMediaUrl("/api/media/media-123e4567-e89b-12d3-a456-426614174000.webp"), true);
  assert.equal(isAllowedMediaUrl("https://images.example.com/product.webp"), true);
  assert.equal(isAllowedMediaUrl("javascript:alert(1)"), false);
  assert.equal(isAllowedMediaUrl("data:image/svg+xml,<svg/>"), false);
  assert.equal(isAllowedMediaUrl("/brand/../private.png"), false);
});

test("media uploader exposes accessible URL and upload controls", async () => {
  const { AdminMediaUpload } = await vite.ssrLoadModule("/components/admin-media-upload.tsx");
  const html = renderToStaticMarkup(React.createElement(AdminMediaUpload, {
    value: "/brand/lfamilia-pixel-logo.webp",
    onChange() {},
    label: "Logo toko",
    help: "Unggah gambar",
  }));

  assert.match(html, /aria-label="Logo toko URL"/);
  assert.match(html, /aria-label="Unggah logo toko"/);
  assert.match(html, /accept="\.jpg,\.jpeg,\.png,\.webp,\.gif,image\/jpeg,image\/png,image\/webp,image\/gif"/);
});
