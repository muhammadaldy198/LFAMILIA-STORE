"use client";

import { useCallback, useEffect, useState } from "react";
import { LoaderCircle, Plus, Save, Trash2 } from "lucide-react";
import { AdminMediaUpload } from "@/components/admin-media-upload";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { defaultStorefrontSettings, type StorefrontSettings } from "@/lib/store-data";
import type { FaqRecord, ProductCategoryRecord } from "@/lib/server/storefront";

export function AdminStorefrontManager({ role }: { role: "owner" | "staff" }) {
  const [settings, setSettings] = useState<StorefrontSettings>(defaultStorefrontSettings);
  const [categories, setCategories] = useState<ProductCategoryRecord[]>([]);
  const [faqs, setFaqs] = useState<FaqRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const [settingsResponse, categoryResponse, faqResponse] = await Promise.all([
        fetch("/api/admin/storefront", { cache: "no-store" }), fetch("/api/admin/categories", { cache: "no-store" }), fetch("/api/admin/faqs", { cache: "no-store" }),
      ]);
      const [settingsData, categoryData, faqData] = await Promise.all([settingsResponse.json(), categoryResponse.json(), faqResponse.json()]);
      if (!settingsResponse.ok) throw new Error(settingsData.error);
      setSettings(settingsData.settings); setCategories(categoryData.categories ?? []); setFaqs(faqData.faqs ?? []);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Konten toko gagal dimuat."); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, [load]);

  function updateSetting<K extends keyof StorefrontSettings>(key: K, value: StorefrontSettings[K]) { setSettings((current) => ({ ...current, [key]: value })); }

  async function saveSettings() {
    setSaving("settings"); setError("");
    try {
      const response = await fetch("/api/admin/storefront", { method: "PUT", headers: { "content-type": "application/json" }, body: JSON.stringify(settings) });
      const data = await response.json(); if (!response.ok) throw new Error(data.error);
      setMessage("Identitas, banner, dan kanal bantuan berhasil disimpan.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Pengaturan gagal disimpan."); }
    finally { setSaving(""); }
  }

  async function saveCategory(item: ProductCategoryRecord) {
    setSaving(`category-${item.id ?? "new"}`); setError("");
    try {
      const response = await fetch("/api/admin/categories", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(item) });
      const data = await response.json(); if (!response.ok) throw new Error(data.error);
      setMessage("Kategori berhasil disimpan."); await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Kategori gagal disimpan."); }
    finally { setSaving(""); }
  }

  async function removeCategory(id: number) {
    const response = await fetch(`/api/admin/categories?id=${id}`, { method: "DELETE" });
    const data = await response.json(); if (!response.ok) { setError(data.error); return; }
    setMessage("Kategori berhasil dihapus."); await load();
  }

  async function saveFaq(item: FaqRecord) {
    setSaving(`faq-${item.id ?? "new"}`); setError("");
    try {
      const response = await fetch("/api/admin/faqs", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(item) });
      const data = await response.json(); if (!response.ok) throw new Error(data.error);
      setMessage("FAQ berhasil disimpan."); await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "FAQ gagal disimpan."); }
    finally { setSaving(""); }
  }

  async function removeFaq(id: number) { const response = await fetch(`/api/admin/faqs?id=${id}`, { method: "DELETE" }); const data = await response.json(); if (!response.ok) { setError(data.error); return; } setMessage("FAQ berhasil dihapus."); await load(); }

  if (loading) return <div className="flex min-h-56 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat konten…</div>;
  return <div className="space-y-6">
    {message && <div className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">{message}</div>}
    {error && <div className="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
    <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5"><div className="flex items-start justify-between gap-4"><div><h3 className="font-bold">Identitas toko, banner & kanal bantuan</h3><p className="mt-1 text-[10px] text-white/30">Semua teks, media utama, dan kontak publik dapat diubah tanpa menyentuh kode.</p></div><Button onClick={() => void saveSettings()} disabled={saving === "settings"} className="rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006] hover:bg-[#d0ff75]">{saving === "settings" ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <Save className="mr-2 size-4" />}Simpan</Button></div><div className="mt-5 grid gap-4 sm:grid-cols-2">
      <Field label="Nama toko"><Input value={settings.storeName} onChange={(event) => updateSetting("storeName", event.target.value)} className="admin-input" /></Field>
      <Field label="Inisial logo"><Input value={settings.storeShortName} maxLength={6} onChange={(event) => updateSetting("storeShortName", event.target.value.toUpperCase())} className="admin-input" /></Field>
      <div className="sm:col-span-2"><AdminMediaUpload label="Logo toko" value={settings.logoUrl} onChange={(value) => updateSetting("logoUrl", value)} help="Unggah JPG, PNG, WEBP, atau GIF maksimal 6 MB. Logo persegi paling cocok." previewClassName="aspect-square max-w-40" /></div>
      <Field label="Tagline" wide><Input value={settings.tagline} onChange={(event) => updateSetting("tagline", event.target.value)} className="admin-input" /></Field>
      <Field label="Pengumuman atas" wide><Input value={settings.announcement ?? ""} onChange={(event) => updateSetting("announcement", event.target.value)} placeholder="Kosongkan untuk menyembunyikan" className="admin-input" /></Field>
      <label className="flex items-center justify-between rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 text-xs text-white/60 sm:col-span-2"><span>Tampilkan banner utama</span><Switch checked={settings.bannerEnabled} onCheckedChange={(checked) => updateSetting("bannerEnabled", checked)} /></label>
      <Field label="Label kecil banner"><Input value={settings.bannerEyebrow} onChange={(event) => updateSetting("bannerEyebrow", event.target.value)} className="admin-input" /></Field>
      <Field label="Judul banner"><Input value={settings.bannerTitle} onChange={(event) => updateSetting("bannerTitle", event.target.value)} className="admin-input" /></Field>
      <Field label="Teks sorotan"><Input value={settings.bannerHighlight} onChange={(event) => updateSetting("bannerHighlight", event.target.value)} className="admin-input" /></Field>
      <Field label="Teks tombol"><Input value={settings.bannerCtaLabel} onChange={(event) => updateSetting("bannerCtaLabel", event.target.value)} className="admin-input" /></Field>
      <Field label="Tujuan tombol"><Input value={settings.bannerCtaHref} onChange={(event) => updateSetting("bannerCtaHref", event.target.value)} className="admin-input" placeholder="#produk atau /catalog" /></Field>
      <div className="sm:col-span-2"><AdminMediaUpload label="Gambar banner Home" value={settings.bannerImageUrl} onChange={(value) => updateSetting("bannerImageUrl", value)} help="Gunakan gambar landscape; teks banner tetap dapat diedit terpisah." /></div>
      <Field label="Deskripsi banner" wide><Textarea value={settings.bannerDescription} onChange={(event) => updateSetting("bannerDescription", event.target.value)} className="min-h-24 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" /></Field>
      <div className="mt-2 border-t border-white/[0.08] pt-5 sm:col-span-2"><h4 className="text-xs font-bold text-white/75">Kanal bantuan publik</h4><p className="mt-1 text-[10px] leading-5 text-white/30">Owner dan staff dapat mengubah WhatsApp, email, Instagram, Discord, serta jam dukungan. Kanal yang belum diisi tampil sebagai belum diaktifkan di halaman Contact.</p></div>
      <Field label="WhatsApp bantuan"><Input inputMode="tel" value={settings.supportWhatsapp ?? ""} onChange={(event) => updateSetting("supportWhatsapp", event.target.value.replace(/[^+0-9]/g, ""))} className="admin-input" placeholder="628123456789" /></Field>
      <Field label="Email bantuan"><Input type="email" value={settings.supportEmail ?? ""} onChange={(event) => updateSetting("supportEmail", event.target.value)} className="admin-input" placeholder="support@domain.com" /></Field>
      <Field label="URL Instagram"><Input type="url" value={settings.instagramUrl ?? ""} onChange={(event) => updateSetting("instagramUrl", event.target.value)} className="admin-input" placeholder="https://instagram.com/..." /></Field>
      <Field label="URL Discord server"><Input type="url" value={settings.discordUrl ?? ""} onChange={(event) => updateSetting("discordUrl", event.target.value)} className="admin-input" placeholder="https://discord.gg/..." /></Field>
      <Field label="Jam dukungan"><Input value={settings.supportHours} onChange={(event) => updateSetting("supportHours", event.target.value)} className="admin-input" /></Field>
    </div></section>

    <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5"><div className="flex items-center justify-between"><div><h3 className="font-bold">Kategori katalog</h3><p className="mt-1 text-[10px] text-white/30">Kategori baru langsung tersedia di filter katalog.</p></div>{role === "owner" && <Button type="button" onClick={() => setCategories((current) => [...current, { id: null, slug: "", name: "", icon: "grid", isActive: true, sortOrder: current.length }])} size="sm" variant="outline" className="border-white/10 bg-white/[0.03] text-white"><Plus className="mr-1 size-3.5" />Tambah</Button>}</div><div className="mt-4 space-y-2">{categories.map((item, index) => <div key={item.id ?? `new-${index}`} className="grid gap-2 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 sm:grid-cols-[1fr_1.2fr_110px_75px_48px_72px]"><Input disabled={role !== "owner"} value={item.slug} onChange={(event) => setCategories((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, slug: slugify(event.target.value) } : row))} className="admin-input" placeholder="entertainment" /><Input disabled={role !== "owner"} value={item.name} onChange={(event) => setCategories((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, name: event.target.value } : row))} className="admin-input" placeholder="Entertainment" /><select disabled={role !== "owner"} value={item.icon} onChange={(event) => setCategories((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, icon: event.target.value } : row))} className="h-10 rounded-xl border border-white/10 bg-[#171c27] px-2 text-[10px] text-white disabled:opacity-50"><option value="gamepad">Game</option><option value="ticket">Voucher</option><option value="play">Play</option><option value="smartphone">Ponsel</option><option value="grid">Grid</option></select><Input disabled={role !== "owner"} type="number" min={0} value={item.sortOrder} onChange={(event) => setCategories((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, sortOrder: Number(event.target.value) } : row))} className="admin-input" /><div className="flex items-center justify-center"><Switch disabled={role !== "owner"} checked={item.isActive} onCheckedChange={(checked) => setCategories((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, isActive: checked } : row))} /></div>{role === "owner" && <div className="flex"><Button type="button" onClick={() => void saveCategory(item)} size="icon-sm" variant="ghost" className="text-[#b9ff35]"><Save className="size-3.5" /></Button>{item.id && <Button type="button" onClick={() => void removeCategory(item.id!)} size="icon-sm" variant="ghost" className="text-red-300"><Trash2 className="size-3.5" /></Button>}</div>}</div>)}</div></section>

    <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5"><div className="flex items-center justify-between"><div><h3 className="font-bold">Pertanyaan umum</h3><p className="mt-1 text-[10px] text-white/30">Staff dapat mengubah FAQ; hanya Pemilik yang dapat menghapus.</p></div><Button type="button" onClick={() => setFaqs((current) => [...current, { id: null, question: "", answer: "", isActive: true, sortOrder: current.length }])} size="sm" variant="outline" className="border-white/10 bg-white/[0.03] text-white"><Plus className="mr-1 size-3.5" />Tambah</Button></div><div className="mt-4 space-y-3">{faqs.map((item, index) => <div key={item.id ?? `new-${index}`} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3"><div className="flex items-center gap-2"><Input value={item.question} onChange={(event) => setFaqs((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, question: event.target.value } : row))} className="admin-input" placeholder="Pertanyaan" /><Switch checked={item.isActive} onCheckedChange={(checked) => setFaqs((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, isActive: checked } : row))} /><Button type="button" onClick={() => void saveFaq(item)} size="icon-sm" variant="ghost" className="text-[#b9ff35]"><Save className="size-3.5" /></Button>{role === "owner" && item.id && <Button type="button" onClick={() => void removeFaq(item.id!)} size="icon-sm" variant="ghost" className="text-red-300"><Trash2 className="size-3.5" /></Button>}</div><Textarea value={item.answer} onChange={(event) => setFaqs((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, answer: event.target.value } : row))} className="mt-2 min-h-24 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" placeholder="Jawaban" /></div>)}</div></section>
  </div>;
}

function Field({ label, wide = false, children }: { label: string; wide?: boolean; children: React.ReactNode }) { return <label className={wide ? "sm:col-span-2" : ""}><span className="field-label">{label}</span>{children}</label>; }
function slugify(value: string) { return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
