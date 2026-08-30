"use client";

import { useCallback, useEffect, useState } from "react";
import { Eye, EyeOff, LoaderCircle, Megaphone, Newspaper, Plus, Save, Star, Trash2 } from "lucide-react";
import { AdminMediaUpload } from "@/components/admin-media-upload";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import type { HomeBannerRecord, NewsRecord, SitePopupRecord } from "@/lib/server/content";
import type { ProductReview } from "@/lib/server/reviews";

type ManagedKind = "banner" | "popup" | "news";

export function AdminExperienceManager({ role }: { role: "owner" | "staff" }) {
  const [banners, setBanners] = useState<HomeBannerRecord[]>([]);
  const [popups, setPopups] = useState<SitePopupRecord[]>([]);
  const [news, setNews] = useState<NewsRecord[]>([]);
  const [reviews, setReviews] = useState<ProductReview[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const [contentResponse, reviewResponse] = await Promise.all([
        fetch("/api/admin/content", { cache: "no-store" }),
        fetch("/api/admin/reviews", { cache: "no-store" }),
      ]);
      const [contentData, reviewData] = await Promise.all([contentResponse.json(), reviewResponse.json()]);
      if (!contentResponse.ok) throw new Error(contentData.error || "Konten gagal dimuat.");
      if (!reviewResponse.ok) throw new Error(reviewData.error || "Ulasan gagal dimuat.");
      setBanners(contentData.banners ?? []); setPopups(contentData.popups ?? []); setNews(contentData.news ?? []); setReviews(reviewData.reviews ?? []);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Konten pengalaman pelanggan gagal dimuat."); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, [load]);

  async function save(kind: ManagedKind, item: HomeBannerRecord | SitePopupRecord | NewsRecord, key: string) {
    setSaving(key); setError(""); setMessage("");
    try {
      const response = await fetch("/api/admin/content", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ kind, item }) });
      const data = await response.json(); if (!response.ok) throw new Error(data.error || "Konten gagal disimpan.");
      setMessage(kind === "banner" ? "Banner Home berhasil disimpan." : kind === "popup" ? "Pop-up Home berhasil disimpan." : "Berita berhasil disimpan.");
      await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Konten gagal disimpan."); }
    finally { setSaving(""); }
  }

  async function remove(kind: ManagedKind, id: number) {
    if (!window.confirm("Hapus konten ini secara permanen?")) return;
    const response = await fetch(`/api/admin/content?kind=${kind}&id=${id}`, { method: "DELETE" });
    const data = await response.json(); if (!response.ok) { setError(data.error || "Konten gagal dihapus."); return; }
    setMessage("Konten berhasil dihapus."); await load();
  }

  async function moderate(review: ProductReview) {
    setSaving(`review-${review.id}`);
    try {
      const response = await fetch("/api/admin/reviews", { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ id: review.id, isVisible: !review.isVisible }) });
      const data = await response.json(); if (!response.ok) throw new Error(data.error || "Ulasan gagal diperbarui.");
      setReviews((current) => current.map((item) => item.id === review.id ? { ...item, isVisible: !item.isVisible } : item));
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Ulasan gagal diperbarui."); }
    finally { setSaving(""); }
  }

  if (loading) return <div className="flex min-h-56 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat banner, pop-up, berita, dan ulasan…</div>;
  return <div className="space-y-6">
    {message && <div className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">{message}</div>}
    {error && <div className="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}

    <ManagedSection icon={Megaphone} title="Banner carousel Home" description="Tambahkan sebanyak yang dibutuhkan. Banner akan berganti otomatis di bagian paling atas Home." onAdd={() => setBanners((current) => [...current, emptyBanner(current.length)])}>
      {banners.length ? banners.map((item, index) => <div key={item.id ?? `banner-${index}`} className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4">
        <div className="grid gap-4 lg:grid-cols-[280px_1fr]"><div className="space-y-4"><AdminMediaUpload label="Banner desktop" value={item.imageUrl} onChange={(value) => updateAt(setBanners, index, { imageUrl: value })} help="Untuk laptop/desktop. Rekomendasi rasio 4:1." previewClassName="aspect-[4/1]" /><AdminMediaUpload label="Banner ponsel" value={item.mobileImageUrl ?? ""} onChange={(value) => updateAt(setBanners, index, { mobileImageUrl: value })} help="Untuk layar ponsel. Jika kosong, banner desktop digunakan." previewClassName="aspect-[5/2]" /></div><div className="grid gap-3 sm:grid-cols-2"><Field label="Judul" wide><Input value={item.title} onChange={(event) => updateAt(setBanners, index, { title: event.target.value })} className="admin-input" /></Field><Field label="Subjudul" wide><Textarea value={item.subtitle} onChange={(event) => updateAt(setBanners, index, { subtitle: event.target.value })} className="min-h-20 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" /></Field><Field label="Teks tombol"><Input value={item.ctaLabel} onChange={(event) => updateAt(setBanners, index, { ctaLabel: event.target.value })} className="admin-input" /></Field><Field label="Tujuan tombol"><Input value={item.ctaHref} onChange={(event) => updateAt(setBanners, index, { ctaHref: event.target.value })} className="admin-input" placeholder="/catalog atau URL" /></Field><Field label="Urutan"><Input type="number" min={0} value={item.sortOrder} onChange={(event) => updateAt(setBanners, index, { sortOrder: Number(event.target.value) })} className="admin-input" /></Field><label className="flex items-center justify-between rounded-xl border border-white/[0.08] px-3 text-xs text-white/55"><span>Aktif</span><Switch checked={item.isActive} onCheckedChange={(checked) => updateAt(setBanners, index, { isActive: checked })} /></label></div></div>
        <Actions role={role} id={item.id} saving={saving === `banner-${index}`} onSave={() => void save("banner", item, `banner-${index}`)} onDelete={() => item.id && void remove("banner", item.id)} />
      </div>) : <Empty text="Belum ada banner khusus. Banner identitas toko tetap digunakan sebagai cadangan." />}
    </ManagedSection>

    <ManagedSection icon={Megaphone} title="Pop-up Home" description="Isi bebas untuk jadwal, pengumuman, komunitas, atau promo. Mendukung beberapa slide dan dua tautan." onAdd={() => setPopups((current) => [...current, emptyPopup(current.length)])}>
      {popups.length ? popups.map((item, index) => <div key={item.id ?? `popup-${index}`} className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4"><div className="grid gap-3 sm:grid-cols-2"><Field label="Judul" wide><Input value={item.title} onChange={(event) => updateAt(setPopups, index, { title: event.target.value })} className="admin-input" /></Field><Field label="Isi pop-up" wide><Textarea value={item.body} onChange={(event) => updateAt(setPopups, index, { body: event.target.value })} className="min-h-32 rounded-xl border-white/10 bg-white/[0.025] text-xs leading-5 text-white" placeholder="Tulis informasi bebas di sini…" /></Field><Field label="Label tautan utama"><Input value={item.primaryLabel ?? ""} onChange={(event) => updateAt(setPopups, index, { primaryLabel: event.target.value })} className="admin-input" placeholder="Gabung WhatsApp" /></Field><Field label="Tautan utama"><Input value={item.primaryHref ?? ""} onChange={(event) => updateAt(setPopups, index, { primaryHref: event.target.value })} className="admin-input" placeholder="https://…" /></Field><Field label="Label tautan kedua"><Input value={item.secondaryLabel ?? ""} onChange={(event) => updateAt(setPopups, index, { secondaryLabel: event.target.value })} className="admin-input" placeholder="Gabung Discord" /></Field><Field label="Tautan kedua"><Input value={item.secondaryHref ?? ""} onChange={(event) => updateAt(setPopups, index, { secondaryHref: event.target.value })} className="admin-input" placeholder="https://discord.gg/…" /></Field><Field label="Sembunyikan selama (hari)"><Input type="number" min={0} max={365} value={item.dismissDays} onChange={(event) => updateAt(setPopups, index, { dismissDays: Number(event.target.value) })} className="admin-input" /></Field><Field label="Urutan"><Input type="number" min={0} value={item.sortOrder} onChange={(event) => updateAt(setPopups, index, { sortOrder: Number(event.target.value) })} className="admin-input" /></Field><label className="flex items-center justify-between rounded-xl border border-white/[0.08] px-3 text-xs text-white/55 sm:col-span-2"><span>Pop-up aktif</span><Switch checked={item.isActive} onCheckedChange={(checked) => updateAt(setPopups, index, { isActive: checked })} /></label></div><Actions role={role} id={item.id} saving={saving === `popup-${index}`} onSave={() => void save("popup", item, `popup-${index}`)} onDelete={() => item.id && void remove("popup", item.id)} /></div>) : <Empty text="Belum ada pop-up Home aktif." />}
    </ManagedSection>

    <ManagedSection icon={Newspaper} title="Berita" description="Buat artikel yang tampil di tab Berita. Draft tidak terlihat oleh pelanggan." onAdd={() => setNews((current) => [...current, emptyNews(current.length)])}>
      {news.length ? news.map((item, index) => <div key={item.id ?? `news-${index}`} className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4"><div className="grid gap-4 lg:grid-cols-[260px_1fr]"><AdminMediaUpload label="Sampul berita" value={item.coverUrl} onChange={(value) => updateAt(setNews, index, { coverUrl: value })} help="Opsional, rekomendasi rasio 16:9." /><div className="grid gap-3 sm:grid-cols-2"><Field label="Judul" wide><Input value={item.title} onChange={(event) => updateAt(setNews, index, { title: event.target.value, slug: item.id ? item.slug : slugify(event.target.value) })} className="admin-input" /></Field><Field label="Slug URL"><Input value={item.slug} onChange={(event) => updateAt(setNews, index, { slug: slugify(event.target.value) })} className="admin-input" /></Field><Field label="Tanggal publikasi"><Input type="datetime-local" value={toLocalDate(item.publishedAt)} onChange={(event) => updateAt(setNews, index, { publishedAt: event.target.value ? new Date(event.target.value).toISOString() : undefined })} className="admin-input" /></Field><Field label="Ringkasan" wide><Textarea value={item.summary} onChange={(event) => updateAt(setNews, index, { summary: event.target.value })} className="min-h-20 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" /></Field><Field label="Isi berita" wide><Textarea value={item.body} onChange={(event) => updateAt(setNews, index, { body: event.target.value })} className="min-h-48 rounded-xl border-white/10 bg-white/[0.025] text-xs leading-5 text-white" /></Field><Field label="Urutan"><Input type="number" min={0} value={item.sortOrder} onChange={(event) => updateAt(setNews, index, { sortOrder: Number(event.target.value) })} className="admin-input" /></Field><label className="flex items-center justify-between rounded-xl border border-white/[0.08] px-3 text-xs text-white/55"><span>Terbitkan</span><Switch checked={item.isPublished} onCheckedChange={(checked) => updateAt(setNews, index, { isPublished: checked })} /></label></div></div><Actions role={role} id={item.id} saving={saving === `news-${index}`} onSave={() => void save("news", item, `news-${index}`)} onDelete={() => item.id && void remove("news", item.id)} /></div>) : <Empty text="Belum ada berita. Tekan Tambah untuk membuat artikel pertama." />}
    </ManagedSection>

    <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5"><div className="flex items-start gap-3"><Star className="mt-0.5 size-5 text-amber-300" /><div><h3 className="font-bold">Moderasi ulasan produk</h3><p className="mt-1 text-[10px] text-white/30">Ulasan hanya dapat dibuat oleh akun dengan pembelian lunas dan dapat disembunyikan oleh admin.</p></div></div><div className="mt-4 space-y-2">{reviews.length ? reviews.map((item) => <div key={item.id} className="flex flex-col gap-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 sm:flex-row sm:items-start sm:justify-between"><div><div className="flex flex-wrap items-center gap-2"><strong className="text-xs">{item.customerName}</strong><span className="text-amber-300">{"★".repeat(item.rating)}<span className="text-white/15">{"★".repeat(5 - item.rating)}</span></span><span className="rounded-full bg-[#b9ff35]/10 px-2 py-0.5 text-[8px] font-bold text-[#d8ff8d]">Pembelian terverifikasi</span></div><p className="mt-2 text-[10px] font-bold text-white/52">{item.productSlug}{item.title ? ` • ${item.title}` : ""}</p><p className="mt-1 text-xs leading-5 text-white/40">{item.body}</p></div><Button type="button" size="sm" variant="outline" disabled={saving === `review-${item.id}`} onClick={() => void moderate(item)} className="shrink-0 border-white/10 bg-white/[0.03] text-white">{item.isVisible ? <><EyeOff className="size-3.5" />Sembunyikan</> : <><Eye className="size-3.5" />Tampilkan</>}</Button></div>) : <Empty text="Belum ada ulasan pelanggan." />}</div></section>
  </div>;
}

function ManagedSection({ icon: Icon, title, description, onAdd, children }: { icon: typeof Megaphone; title: string; description: string; onAdd(): void; children: React.ReactNode }) { return <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5"><div className="flex items-start justify-between gap-4"><div className="flex items-start gap-3"><Icon className="mt-0.5 size-5 text-[#cfff72]" /><div><h3 className="font-bold">{title}</h3><p className="mt-1 text-[10px] leading-5 text-white/30">{description}</p></div></div><Button type="button" size="sm" variant="outline" onClick={onAdd} className="border-white/10 bg-white/[0.03] text-white"><Plus className="size-3.5" />Tambah</Button></div><div className="mt-4 space-y-3">{children}</div></section>; }
function Actions({ role, id, saving, onSave, onDelete }: { role: "owner" | "staff"; id: number | null; saving: boolean; onSave(): void; onDelete(): void }) { return <div className="mt-4 flex justify-end gap-2">{role === "owner" && id && <Button type="button" variant="ghost" onClick={onDelete} className="text-red-300"><Trash2 className="size-4" />Hapus</Button>}<Button type="button" disabled={saving} onClick={onSave} className="bg-[#b9ff35] text-xs font-black text-[#091006]">{saving ? <LoaderCircle className="size-4 animate-spin" /> : <Save className="size-4" />}Simpan</Button></div>; }
function Field({ label, wide = false, children }: { label: string; wide?: boolean; children: React.ReactNode }) { return <label className={wide ? "sm:col-span-2" : ""}><span className="field-label">{label}</span>{children}</label>; }
function Empty({ text }: { text: string }) { return <div className="rounded-xl border border-dashed border-white/10 px-4 py-8 text-center text-xs text-white/28">{text}</div>; }
function emptyBanner(sortOrder: number): HomeBannerRecord { return { id: null, title: "", subtitle: "", imageUrl: "", mobileImageUrl: "", ctaLabel: "Lihat produk", ctaHref: "#produk", isActive: true, sortOrder }; }
function emptyPopup(sortOrder: number): SitePopupRecord { return { id: null, title: "", body: "", primaryLabel: "", primaryHref: "", secondaryLabel: "", secondaryHref: "", dismissDays: 7, isActive: true, sortOrder }; }
function emptyNews(sortOrder: number): NewsRecord { return { id: null, slug: "", title: "", summary: "", body: "", coverUrl: "", isPublished: false, sortOrder }; }
function slugify(value: string) { return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
function toLocalDate(value?: string) { if (!value) return ""; const date = new Date(value); if (Number.isNaN(date.getTime())) return ""; const offset = date.getTimezoneOffset() * 60_000; return new Date(date.getTime() - offset).toISOString().slice(0, 16); }
function updateAt<T>(setter: React.Dispatch<React.SetStateAction<T[]>>, index: number, patch: Partial<T>) { setter((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item)); }
