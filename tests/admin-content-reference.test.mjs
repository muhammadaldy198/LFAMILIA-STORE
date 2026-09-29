import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-experience-manager.tsx"), "utf8");

test("banner and content page exposes real managed customer content", () => {
  for (const label of [
    "Banner, Pop-up, Berita & Ulasan",
    "Kelola konten yang benar-benar ditampilkan pada frontend customer.",
    "Daftar Banner",
    "Pop-up",
    "Berita",
    "Ulasan Pelanggan",
    "FAQ",
    "Edit Banner",
    "Lihat Website Customer",
  ]) assert.ok(source.includes(label), `missing content label: ${label}`);
  assert.doesNotMatch(source, /function PreviewPanel/);
});

test("content reference exposes real add edit ordering and visibility controls", () => {
  for (const label of [
    "Tambah Banner",
    "Tambah Pop-up",
    "Tulis Berita",
    "Tambah FAQ",
    "Link Tujuan",
    "Tampilkan di",
    "Urutan",
    "Simpan Perubahan",
  ]) assert.ok(source.includes(label), `missing content control: ${label}`);
  assert.doesNotMatch(source, /action="Tambah Ulasan"/);
  assert.match(source, /Ulasan dibuat oleh pelanggan yang sudah bertransaksi/);
  assert.match(source, /function EditorPanel/);
  assert.match(source, /function Switch/);
});

test("content controls persist through the admin APIs", () => {
  for (const endpoint of [
    "/api/admin/content",
    "/api/admin/faqs",
    "/api/admin/reviews",
    "/api/admin/media",
  ]) assert.ok(source.includes(endpoint), `missing content endpoint: ${endpoint}`);
  assert.match(source, /method: "POST"/);
  assert.match(source, /method: "PATCH"/);
  assert.match(source, /method: "DELETE"/);
  assert.doesNotMatch(source, /frontend-only|backend nanti|simulasi/i);
});

test("banner and news uploads compress large images without changing canvas dimensions and surface real HTTP errors", () => {
  assert.match(source, /prepareContentImage\(file\)/);
  assert.match(source, /canvas\.width = bitmap\.width/);
  assert.match(source, /canvas\.height = bitmap\.height/);
  assert.match(source, /CONTENT_MEDIA_MAX_BYTES = 6 \* 1024 \* 1024/);
  assert.match(source, /response\.text\(\)/);
  assert.match(source, /Upload gagal \(HTTP \$\{response\.status\}\)\./);
  assert.match(source, /maks\. 6MB · otomatis dikompres/);
});

test("each content type exposes saved customer-facing settings without duplicate URL inputs", () => {
  for (const label of [
    "Gambar Desktop",
    "Gambar Mobile",
    "Teks tombol utama",
    "Sembunyikan lagi setelah (hari)",
    "Slug URL",
    "Isi berita",
    "Tanggal terbit (ISO, opsional)",
    "Jawaban",
  ]) assert.ok(source.includes(label), `missing useful content setting: ${label}`);
  assert.doesNotMatch(source, /label="URL gambar desktop"/);
  assert.doesNotMatch(source, /label="URL gambar mobile/);
  assert.doesNotMatch(source, /label="URL cover"/);
  assert.match(source, /primaryLabel/);
  assert.match(source, /secondaryHref/);
  assert.match(source, /publishedAt/);
  assert.match(source, /mobileImageUrl/);
});
