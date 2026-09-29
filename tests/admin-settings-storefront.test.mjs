import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-operations-workspaces.tsx"), "utf8");

test("storefront settings keep real identity/contact fields without duplicate or simulated settings", () => {
  for (const label of ["Instagram", "Discord", "Logo toko", "Aktifkan widget bantuan"]) {
    assert.ok(source.includes(label), `missing storefront settings field: ${label}`);
  }

  for (const duplicateBannerControl of [
    "Label atas banner",
    "Label tombol banner",
    "Link tombol banner",
    "Judul banner",
    "Sorotan banner",
    "Deskripsi banner",
    "Aktifkan banner utama",
    "Gambar banner",
  ]) {
    assert.ok(!source.includes(duplicateBannerControl), `duplicate banner control still visible in Pengaturan: ${duplicateBannerControl}`);
  }

  assert.match(source, /Banner homepage tetap dikelola di Banner & Konten/);
  assert.match(source, /TabBar tabs=\{\["Toko", "Ekspor"\]\}/);
  assert.doesNotMatch(source, /Riwayat Backup|Notifikasi Admin|Akses & Sesi/);
  assert.match(source, /store\.instagramUrl/);
  assert.match(source, /store\.discordUrl/);
});
