import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "components/admin-experience-manager.tsx"), "utf8");

test("banner and content page matches the supplied desktop reference", () => {
  for (const label of [
    "Banner, Pop-up, Berita & Ulasan",
    "Kelola semua konten tampilan pelanggan di halaman utama.",
    "Daftar Banner",
    "Pop-up",
    "Berita",
    "Ulasan Pelanggan",
    "FAQ",
    "Edit Banner",
    "Preview Tampilan di Website",
  ]) assert.ok(source.includes(label), `missing content label: ${label}`);
});

test("content reference exposes add, edit, ordering, visibility and preview controls", () => {
  for (const label of [
    "Tambah Banner",
    "Tambah Pop-up",
    "Tulis Berita",
    "Tambah Ulasan",
    "Tambah FAQ",
    "Link Tujuan",
    "Tampilkan di",
    "Urutan",
    "Simpan Perubahan",
    "Tampilan Desktop",
    "Tampilan Mobile",
  ]) assert.ok(source.includes(label), `missing content control: ${label}`);
  assert.match(source, /function EditorPanel/);
  assert.match(source, /function PreviewPanel/);
  assert.match(source, /function Switch/);
});

test("content controls persist through the admin APIs", () => {
  for (const endpoint of [
    "/api/panel/content",
    "/api/panel/faqs",
    "/api/panel/reviews",
    "/api/panel/media",
  ]) assert.ok(source.includes(endpoint), `missing content endpoint: ${endpoint}`);
  assert.match(source, /method: "POST"/);
  assert.match(source, /method: "PATCH"/);
  assert.match(source, /method: "DELETE"/);
  assert.doesNotMatch(source, /frontend-only|backend nanti|simulasi/i);
});
