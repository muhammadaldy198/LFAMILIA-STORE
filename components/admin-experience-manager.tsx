"use client";
/* eslint-disable @next/next/no-img-element */

import { useCallback, useEffect, useMemo, useState } from "react";
import { Edit3, Eye, EyeOff, HelpCircle, ImageIcon, LoaderCircle, Megaphone, Monitor, Newspaper, Plus, Save, Smartphone, Star, Trash2, X } from "lucide-react";
import { AdminMediaUpload } from "@/components/admin-media-upload";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import type { HomeBannerRecord, NewsRecord, SitePopupRecord } from "@/lib/server/content";
import type { ProductReview } from "@/lib/server/reviews";
import type { FaqRecord } from "@/lib/server/storefront";

type ManagedKind = "banner" | "popup" | "news";
type ContentTab = ManagedKind | "review" | "faq";
type Editor = { kind: ManagedKind | "faq"; index: number };
type ContentPayload = { banners?: HomeBannerRecord[]; popups?: SitePopupRecord[]; news?: NewsRecord[]; error?: string };
type ReviewPayload = { reviews?: ProductReview[]; error?: string };
type FaqPayload = { faqs?: FaqRecord[]; error?: string };

const tabs: Array<{ value: ContentTab; label: string }> = [
  { value: "banner", label: "Banner" },
  { value: "popup", label: "Pop-up" },
  { value: "news", label: "Berita" },
  { value: "review", label: "Ulasan" },
  { value: "faq", label: "FAQ" },
];

export function AdminExperienceManager({ role }: { role: "owner" | "staff" }) {
  const [banners, setBanners] = useState<HomeBannerRecord[]>([]);
  const [popups, setPopups] = useState<SitePopupRecord[]>([]);
  const [news, setNews] = useState<NewsRecord[]>([]);
  const [reviews, setReviews] = useState<ProductReview[]>([]);
  const [faqs, setFaqs] = useState<FaqRecord[]>([]);
  const [activeTab, setActiveTab] = useState<ContentTab>("banner");
  const [editor, setEditor] = useState<Editor | null>(null);
  const [previewMode, setPreviewMode] = useState<"desktop" | "mobile">("mobile");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [contentResponse, reviewResponse, faqResponse] = await Promise.all([
        fetch("/api/panel/content", { cache: "no-store" }),
        fetch("/api/panel/reviews", { cache: "no-store" }),
        fetch("/api/panel/faqs", { cache: "no-store" }),
      ]);
      const [contentData, reviewData, faqData] = await Promise.all([
        readJson<ContentPayload>(contentResponse),
        readJson<ReviewPayload>(reviewResponse),
        readJson<FaqPayload>(faqResponse),
      ]);
      if (!contentResponse.ok) throw new Error(contentData.error || "Konten gagal dimuat.");
      if (!reviewResponse.ok) throw new Error(reviewData.error || "Ulasan gagal dimuat.");
      if (!faqResponse.ok) throw new Error(faqData.error || "FAQ gagal dimuat.");
      setBanners(contentData.banners ?? []);
      setPopups(contentData.popups ?? []);
      setNews(contentData.news ?? []);
      setReviews(reviewData.reviews ?? []);
      setFaqs(faqData.faqs ?? []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Banner dan konten gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  async function saveContent(kind: ManagedKind, item: HomeBannerRecord | SitePopupRecord | NewsRecord, key: string, close = true) {
    setSaving(key); setError(""); setMessage("");
    try {
      const response = await fetch("/api/panel/content", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ kind, item }) });
      const data = await readJson<{ error?: string }>(response);
      if (!response.ok) throw new Error(data.error || "Konten gagal disimpan.");
      setMessage(kind === "banner" ? "Banner berhasil disimpan." : kind === "popup" ? "Pop-up berhasil disimpan." : "Berita berhasil disimpan.");
      if (close) setEditor(null);
      await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Konten gagal disimpan."); }
    finally { setSaving(""); }
  }

  async function saveFaq(item: FaqRecord, key: string, close = true) {
    setSaving(key); setError(""); setMessage("");
    try {
      const response = await fetch("/api/panel/faqs", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(item) });
      const data = await readJson<{ error?: string }>(response);
      if (!response.ok) throw new Error(data.error || "FAQ gagal disimpan.");
      setMessage("FAQ berhasil disimpan.");
      if (close) setEditor(null);
      await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "FAQ gagal disimpan."); }
    finally { setSaving(""); }
  }

  async function remove(kind: ManagedKind | "faq", id: number) {
    if (!window.confirm("Hapus konten ini secara permanen?")) return;
    const url = kind === "faq" ? `/api/panel/faqs?id=${id}` : `/api/panel/content?kind=${kind}&id=${id}`;
    const response = await fetch(url, { method: "DELETE" });
    const data = await readJson<{ error?: string }>(response);
    if (!response.ok) { setError(data.error || "Konten gagal dihapus."); return; }
    setEditor(null); setMessage("Konten berhasil dihapus."); await load();
  }

  async function moderate(review: ProductReview) {
    const key = `review-${review.id}`;
    setSaving(key); setError("");
    try {
      const response = await fetch("/api/panel/reviews", { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ id: review.id, isVisible: !review.isVisible }) });
      const data = await readJson<{ error?: string }>(response);
      if (!response.ok) throw new Error(data.error || "Ulasan gagal diperbarui.");
      setReviews((current) => current.map((item) => item.id === review.id ? { ...item, isVisible: !item.isVisible } : item));
      setMessage(review.isVisible ? "Ulasan disembunyikan." : "Ulasan ditampilkan.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Ulasan gagal diperbarui."); }
    finally { setSaving(""); }
  }

  function add(kind: ManagedKind | "faq") {
    setActiveTab(kind);
    if (kind === "banner") {
      const index = banners.length; setBanners((current) => [...current, emptyBanner(current.length)]); setEditor({ kind, index });
    } else if (kind === "popup") {
      const index = popups.length; setPopups((current) => [...current, emptyPopup(current.length)]); setEditor({ kind, index });
    } else if (kind === "news") {
      const index = news.length; setNews((current) => [...current, emptyNews(current.length)]); setEditor({ kind, index });
    } else {
      const index = faqs.length; setFaqs((current) => [...current, { id: null, question: "", answer: "", isActive: true, sortOrder: current.length }]); setEditor({ kind, index });
    }
  }

  const currentBanner = editor?.kind === "banner" ? banners[editor.index] : undefined;
  const currentPopup = editor?.kind === "popup" ? popups[editor.index] : undefined;
  const currentNews = editor?.kind === "news" ? news[editor.index] : undefined;
  const currentFaq = editor?.kind === "faq" ? faqs[editor.index] : undefined;
  const activeBanner = useMemo(() => banners.find((item) => item.isActive) ?? banners[0], [banners]);
  const activePopup = useMemo(() => popups.find((item) => item.isActive) ?? popups[0], [popups]);
  const publishedNews = useMemo(() => news.filter((item) => item.isPublished).slice(0, 2), [news]);
  const visibleReviews = useMemo(() => reviews.filter((item) => item.isVisible).slice(0, 2), [reviews]);

  if (loading) return <div className="flex min-h-56 items-center justify-center text-xs text-[#667085]"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat banner dan konten…</div>;

  return <div className="content-workspace text-[#344054]">
    <style jsx global>{`
      .content-workspace .content-input{height:2.5rem;width:100%;border:1px solid #d0d5dd;border-radius:.375rem;background:#fff;padding:0 .75rem;font-size:11px;color:#344054;outline:none}
      .content-workspace textarea.content-input{height:auto;padding:.65rem .75rem}
      .content-workspace .content-input:focus{border-color:#155eef;box-shadow:0 0 0 2px rgba(21,94,239,.1)}
      @media(max-width:1279px){.content-editor{position:fixed!important;inset:auto 0 0 0;z-index:80;max-height:86vh;overflow-y:auto;border-radius:18px 18px 0 0!important;box-shadow:0 -18px 60px rgba(16,24,40,.24)!important}}
    `}</style>

    <header className="mb-4"><h2 className="text-xl font-black text-[#101828] sm:text-2xl">Banner, Pop-up, Berita & Ulasan</h2><p className="mt-1 text-[11px] text-[#667085]">Kelola semua konten tampilan pelanggan di halaman utama.</p></header>
    {message && <div className="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] text-emerald-700">{message}</div>}
    {error && <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] text-red-700">{error}</div>}

    <nav className="mb-3 flex gap-1 overflow-x-auto border-b border-[#e4e7ec]">{tabs.map((tab) => <button key={tab.value} type="button" onClick={() => { setActiveTab(tab.value); setEditor(null); }} className={`whitespace-nowrap border-b-2 px-4 py-3 text-[11px] font-bold ${activeTab === tab.value ? "border-[#155eef] text-[#155eef]" : "border-transparent text-[#667085]"}`}>{tab.label}</button>)}</nav>

    <div className={editor ? "grid gap-3 xl:grid-cols-[minmax(0,1fr)_330px]" : "grid gap-3"}>
      <main className="min-w-0 space-y-3">
        {activeTab === "banner" && <>
          <Panel title="Daftar Banner" description="Atur banner yang tampil di halaman utama, urutan, status, dan link tujuan." action="Tambah Banner" onAdd={() => add("banner")}>
            <DataTable headings={["#", "Gambar", "Judul", "Link Tujuan", "Tampilan", "Urutan", "Status", "Aksi"]}>{banners.map((item, index) => <tr key={item.id ?? `banner-${index}`} className="border-t border-[#e4e7ec]"><Cell>{index + 1}</Cell><Cell><Thumb src={item.imageUrl} wide /></Cell><Cell><strong className="text-[10px] text-[#101828]">{item.title || "Banner baru"}</strong></Cell><Cell><span className="block max-w-[170px] truncate text-[#155eef]">{item.ctaHref || "-"}</span></Cell><Cell><DeviceBadge desktop={item.showDesktop !== false} mobile={item.showMobile !== false} /></Cell><Cell>{item.sortOrder + 1}</Cell><Cell><Switch checked={item.isActive} onCheckedChange={(checked) => { const next = { ...item, isActive: checked }; updateAt(setBanners, index, next); void saveContent("banner", next, `banner-toggle-${index}`, false); }} /></Cell><Cell><Actions onEdit={() => setEditor({ kind: "banner", index })} onDelete={role === "owner" && item.id ? () => void remove("banner", item.id!) : undefined} /></Cell></tr>)}</DataTable>
            <MobileList>{banners.map((item, index) => <MobileRow key={item.id ?? index} title={item.title || "Banner baru"} subtitle={item.ctaHref || "Belum ada link"} image={item.imageUrl} active={item.isActive} onClick={() => setEditor({ kind: "banner", index })} />)}</MobileList>
            {!banners.length && <Empty icon={ImageIcon} text="Belum ada banner." />}
          </Panel>
          <div className="grid gap-3 lg:grid-cols-2">
            <Mini title="Pop-up" description="Kelola pop-up informasi, promo, atau pengumuman." action="Tambah Pop-up" onAdd={() => add("popup")} onOpen={() => setActiveTab("popup")}>{popups.slice(0, 3).map((item, index) => <MiniRow key={item.id ?? index} index={index + 1} title={item.title || "Pop-up baru"} detail="Semua halaman" active={item.isActive} onEdit={() => { setActiveTab("popup"); setEditor({ kind: "popup", index }); }} />)}</Mini>
            <Mini title="Berita" description="Kelola berita yang ditampilkan di halaman utama." action="Tulis Berita" onAdd={() => add("news")} onOpen={() => setActiveTab("news")}>{news.slice(0, 3).map((item, index) => <MiniRow key={item.id ?? index} index={index + 1} title={item.title || "Berita baru"} detail={formatDate(item.publishedAt)} active={item.isPublished} onEdit={() => { setActiveTab("news"); setEditor({ kind: "news", index }); }} />)}</Mini>
            <Mini title="Ulasan Pelanggan" description="Moderasi ulasan pembelian terverifikasi." action="Kelola Ulasan" onOpen={() => setActiveTab("review")}>{reviews.slice(0, 3).map((item, index) => <MiniRow key={item.id} index={index + 1} title={item.customerName} detail={`${"★".repeat(item.rating)}  ${item.body}`} active={item.isVisible} onEdit={() => setActiveTab("review")} />)}</Mini>
            <Mini title="FAQ" description="Kelola pertanyaan yang sering ditanyakan." action="Tambah FAQ" onAdd={() => add("faq")} onOpen={() => setActiveTab("faq")}>{faqs.slice(0, 3).map((item, index) => <MiniRow key={item.id ?? index} index={index + 1} title={item.question || "Pertanyaan baru"} detail={`Urutan ${item.sortOrder + 1}`} active={item.isActive} onEdit={() => { setActiveTab("faq"); setEditor({ kind: "faq", index }); }} />)}</Mini>
          </div>
        </>}

        {activeTab === "popup" && <Panel title="Daftar Pop-up" description="Kelola informasi dan promo yang muncul kepada pelanggan." action="Tambah Pop-up" onAdd={() => add("popup")}><DataTable headings={["#", "Judul", "Tampilan", "Sembunyikan", "Urutan", "Status", "Aksi"]}>{popups.map((item, index) => <tr key={item.id ?? index} className="border-t border-[#e4e7ec]"><Cell>{index + 1}</Cell><Cell><strong className="text-[#101828]">{item.title || "Pop-up baru"}</strong></Cell><Cell><Badge>Semua Halaman</Badge></Cell><Cell>{item.dismissDays} hari</Cell><Cell>{item.sortOrder + 1}</Cell><Cell><Switch checked={item.isActive} onCheckedChange={(checked) => { const next = { ...item, isActive: checked }; updateAt(setPopups, index, next); void saveContent("popup", next, `popup-toggle-${index}`, false); }} /></Cell><Cell><Actions onEdit={() => setEditor({ kind: "popup", index })} onDelete={role === "owner" && item.id ? () => void remove("popup", item.id!) : undefined} /></Cell></tr>)}</DataTable><MobileList>{popups.map((item, index) => <MobileRow key={item.id ?? index} title={item.title || "Pop-up baru"} subtitle={`${item.dismissDays} hari`} active={item.isActive} onClick={() => setEditor({ kind: "popup", index })} />)}</MobileList>{!popups.length && <Empty icon={Megaphone} text="Belum ada pop-up." />}</Panel>}

        {activeTab === "news" && <Panel title="Daftar Berita" description="Tulis artikel dan atur status publikasi." action="Tulis Berita" onAdd={() => add("news")}><DataTable headings={["#", "Sampul", "Judul", "Tanggal", "Urutan", "Status", "Aksi"]}>{news.map((item, index) => <tr key={item.id ?? index} className="border-t border-[#e4e7ec]"><Cell>{index + 1}</Cell><Cell><Thumb src={item.coverUrl} /></Cell><Cell><div><strong className="text-[#101828]">{item.title || "Berita baru"}</strong><p className="mt-1 max-w-[250px] truncate text-[#98a2b3]">{item.summary}</p></div></Cell><Cell>{formatDate(item.publishedAt)}</Cell><Cell>{item.sortOrder + 1}</Cell><Cell><Switch checked={item.isPublished} onCheckedChange={(checked) => { const next = { ...item, isPublished: checked }; updateAt(setNews, index, next); void saveContent("news", next, `news-toggle-${index}`, false); }} /></Cell><Cell><Actions onEdit={() => setEditor({ kind: "news", index })} onDelete={role === "owner" && item.id ? () => void remove("news", item.id!) : undefined} /></Cell></tr>)}</DataTable><MobileList>{news.map((item, index) => <MobileRow key={item.id ?? index} title={item.title || "Berita baru"} subtitle={formatDate(item.publishedAt)} image={item.coverUrl} active={item.isPublished} onClick={() => setEditor({ kind: "news", index })} />)}</MobileList>{!news.length && <Empty icon={Newspaper} text="Belum ada berita." />}</Panel>}

        {activeTab === "review" && <Panel title="Ulasan Pelanggan" description="Ulasan hanya berasal dari pelanggan dengan pesanan lunas; admin melakukan moderasi."><DataTable headings={["#", "Pelanggan", "Rating", "Produk", "Pesan", "Status", "Aksi"]}>{reviews.map((item, index) => <tr key={item.id} className="border-t border-[#e4e7ec]"><Cell>{index + 1}</Cell><Cell><strong className="text-[#101828]">{item.customerName}</strong></Cell><Cell><span className="text-amber-500">{"★".repeat(item.rating)}<span className="text-[#d0d5dd]">{"★".repeat(5 - item.rating)}</span></span></Cell><Cell>{item.productSlug}</Cell><Cell><span className="block max-w-[260px] truncate">{item.body}</span></Cell><Cell><Switch checked={item.isVisible} disabled={saving === `review-${item.id}`} onCheckedChange={() => void moderate(item)} /></Cell><Cell><Button type="button" size="sm" variant="outline" onClick={() => void moderate(item)} className="h-8 border-[#d0d5dd] text-[9px]">{item.isVisible ? <EyeOff className="mr-1 size-3" /> : <Eye className="mr-1 size-3" />}{item.isVisible ? "Sembunyikan" : "Tampilkan"}</Button></Cell></tr>)}</DataTable><MobileList>{reviews.map((item) => <MobileRow key={item.id} title={item.customerName + " · " + "★".repeat(item.rating)} subtitle={item.body} active={item.isVisible} onClick={() => void moderate(item)} />)}</MobileList>{!reviews.length && <Empty icon={Star} text="Belum ada ulasan." />}</Panel>}

        {activeTab === "faq" && <Panel title="FAQ" description="Kelola pertanyaan yang sering ditanyakan pelanggan." action="Tambah FAQ" onAdd={() => add("faq")}><DataTable headings={["#", "Pertanyaan", "Status", "Urutan", "Aksi"]}>{faqs.map((item, index) => <tr key={item.id ?? index} className="border-t border-[#e4e7ec]"><Cell>{index + 1}</Cell><Cell><strong className="text-[#101828]">{item.question || "Pertanyaan baru"}</strong></Cell><Cell><Switch checked={item.isActive} onCheckedChange={(checked) => { const next = { ...item, isActive: checked }; updateAt(setFaqs, index, next); void saveFaq(next, `faq-toggle-${index}`, false); }} /></Cell><Cell>{item.sortOrder + 1}</Cell><Cell><Actions onEdit={() => setEditor({ kind: "faq", index })} onDelete={role === "owner" && item.id ? () => void remove("faq", item.id!) : undefined} /></Cell></tr>)}</DataTable><MobileList>{faqs.map((item, index) => <MobileRow key={item.id ?? index} title={item.question || "Pertanyaan baru"} subtitle={`Urutan ${item.sortOrder + 1}`} active={item.isActive} onClick={() => setEditor({ kind: "faq", index })} />)}</MobileList>{!faqs.length && <Empty icon={HelpCircle} text="Belum ada FAQ." />}</Panel>}
      </main>

      {editor && <aside className="content-editor self-start rounded-xl border border-[#dfe5ed] bg-white shadow-sm xl:sticky xl:top-3">
        <div className="flex items-center justify-between border-b border-[#e4e7ec] px-4 py-3"><h3 className="text-sm font-black text-[#101828]">{editorTitle(editor.kind)}</h3><button type="button" onClick={() => setEditor(null)} className="rounded-md p-1.5 text-[#667085] hover:bg-[#f2f4f7]"><X className="size-4" /></button></div>
        <div className="max-h-[calc(100vh-230px)] space-y-4 overflow-y-auto p-4">
          {currentBanner && <BannerForm item={currentBanner} index={editor.index} change={(patch) => updateAt(setBanners, editor.index, patch)} />}
          {currentPopup && <PopupForm item={currentPopup} change={(patch) => updateAt(setPopups, editor.index, patch)} />}
          {currentNews && <NewsForm item={currentNews} change={(patch) => updateAt(setNews, editor.index, patch)} />}
          {currentFaq && <FaqForm item={currentFaq} change={(patch) => updateAt(setFaqs, editor.index, patch)} />}
        </div>
        <div className="grid grid-cols-2 gap-2 border-t border-[#e4e7ec] p-3"><Button type="button" variant="outline" onClick={() => { setEditor(null); void load(); }} className="border-[#d0d5dd]">Batal</Button><Button type="button" disabled={Boolean(saving)} onClick={() => { if (currentBanner) void saveContent("banner", currentBanner, `banner-${editor.index}`); else if (currentPopup) void saveContent("popup", currentPopup, `popup-${editor.index}`); else if (currentNews) void saveContent("news", currentNews, `news-${editor.index}`); else if (currentFaq) void saveFaq(currentFaq, `faq-${editor.index}`); }} className="bg-[#155eef] text-white hover:bg-[#004eeb]">{saving ? <LoaderCircle className="mr-1 size-4 animate-spin" /> : <Save className="mr-1 size-4" />}Simpan Perubahan</Button></div>
      </aside>}
    </div>

    <section className="mt-3 rounded-xl border border-[#dfe5ed] bg-white p-4 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-sm font-black text-[#101828]">Preview Tampilan di Website</h3><p className="mt-1 text-[10px] text-[#667085]">Preview banner, pop-up, berita, dan ulasan dari data aktif.</p></div><div className="flex rounded-lg border border-[#d0d5dd] bg-[#f8fafc] p-1"><PreviewButton active={previewMode === "desktop"} onClick={() => setPreviewMode("desktop")} icon={Monitor}>Desktop</PreviewButton><PreviewButton active={previewMode === "mobile"} onClick={() => setPreviewMode("mobile")} icon={Smartphone}>Mobile</PreviewButton></div></div>
      <div className={`mt-4 grid gap-4 ${previewMode === "desktop" ? "lg:grid-cols-2" : "sm:grid-cols-2 xl:grid-cols-4"}`}>
        <Phone label="Banner"><div className="p-3"><div className="mb-3 h-7 rounded bg-[#f2f4f7]" />{activeBanner ? <div className="aspect-[2/1] overflow-hidden rounded-lg bg-[#101828]">{activeBanner.imageUrl ? <img src={activeBanner.mobileImageUrl || activeBanner.imageUrl} alt="" className="size-full object-cover" /> : <PreviewEmpty />}</div> : <PreviewEmpty />}</div></Phone>
        <Phone label="Pop-up"><div className="grid min-h-60 place-items-center bg-[#101828]/30 p-4"><div className="w-full rounded-xl bg-white p-4 text-center shadow-xl"><Megaphone className="mx-auto size-8 text-[#155eef]" /><h4 className="mt-3 text-sm font-black text-[#101828]">{activePopup?.title || "Informasi LFAMILIA"}</h4><p className="mt-2 line-clamp-4 text-[9px] text-[#667085]">{activePopup?.body || "Belum ada pop-up aktif."}</p>{activePopup?.primaryLabel && <div className="mt-3 rounded bg-[#155eef] py-2 text-[9px] font-bold text-white">{activePopup.primaryLabel}</div>}</div></div></Phone>
        <Phone label="Berita"><div className="p-3"><h4 className="mb-3 text-sm font-black text-[#101828]">Berita Terbaru</h4>{publishedNews.length ? publishedNews.map((item) => <div key={item.id} className="mb-2 flex gap-2 rounded-lg border border-[#e4e7ec] p-2"><Thumb src={item.coverUrl} /><div><strong className="line-clamp-1 text-[9px] text-[#101828]">{item.title}</strong><p className="mt-1 line-clamp-2 text-[8px] text-[#667085]">{item.summary}</p></div></div>) : <PreviewEmpty />}</div></Phone>
        <Phone label="Ulasan"><div className="p-3"><h4 className="mb-3 text-sm font-black text-[#101828]">Ulasan Pelanggan</h4>{visibleReviews.length ? visibleReviews.map((item) => <div key={item.id} className="mb-2 rounded-lg border border-[#e4e7ec] p-3"><strong className="text-[9px] text-[#101828]">{item.customerName}</strong><p className="text-amber-500">{"★".repeat(item.rating)}</p><p className="mt-1 line-clamp-2 text-[8px] text-[#667085]">{item.body}</p></div>) : <PreviewEmpty />}</div></Phone>
      </div>
    </section>
  </div>;
}

function BannerForm({ item, index, change }: { item: HomeBannerRecord; index: number; change(patch: Partial<HomeBannerRecord>): void }) {
  return <><AdminMediaUpload label="Gambar Banner Desktop" value={item.imageUrl} onChange={(value) => change({ imageUrl: value })} help="Rekomendasi 1920 × 600, maksimal 6 MB." previewClassName="aspect-[3/1]" /><AdminMediaUpload label="Gambar Banner Mobile" value={item.mobileImageUrl ?? ""} onChange={(value) => change({ mobileImageUrl: value })} help="Rekomendasi 1080 × 1080. Jika kosong, gambar desktop digunakan." previewClassName="aspect-square max-h-44" /><Field label="Judul"><Input value={item.title} onChange={(event) => change({ title: event.target.value })} className="content-input" /></Field><Field label="Subjudul"><Textarea value={item.subtitle} onChange={(event) => change({ subtitle: event.target.value })} className="content-input min-h-20" /></Field><Field label="Teks Tombol"><Input value={item.ctaLabel} onChange={(event) => change({ ctaLabel: event.target.value })} className="content-input" /></Field><Field label="Link Tujuan"><Input value={item.ctaHref} onChange={(event) => change({ ctaHref: event.target.value })} className="content-input" placeholder="/catalog atau https://..." /></Field><div><span className="mb-2 block text-[10px] font-bold">Tampilkan di</span><div className="grid grid-cols-2 gap-3"><label className="flex items-center gap-2 text-[10px]"><input type="checkbox" checked={item.showDesktop !== false} onChange={(event) => change({ showDesktop: event.target.checked })} className="accent-[#155eef]" />Desktop</label><label className="flex items-center gap-2 text-[10px]"><input type="checkbox" checked={item.showMobile !== false} onChange={(event) => change({ showMobile: event.target.checked })} className="accent-[#155eef]" />Mobile</label></div></div><Field label="Urutan"><Input type="number" min={1} value={item.sortOrder + 1} onChange={(event) => change({ sortOrder: Math.max(0, Number(event.target.value) - 1) })} className="content-input" /></Field><label className="flex items-center gap-3 text-[11px] font-semibold"><Switch checked={item.isActive} onCheckedChange={(checked) => change({ isActive: checked })} />Aktif</label><p className="text-[9px] text-[#98a2b3]">Banner #{index + 1}. Link internal diawali /, link eksternal menggunakan https://.</p></>;
}

function PopupForm({ item, change }: { item: SitePopupRecord; change(patch: Partial<SitePopupRecord>): void }) {
  return <><Field label="Judul"><Input value={item.title} onChange={(event) => change({ title: event.target.value })} className="content-input" /></Field><Field label="Isi Pop-up"><Textarea value={item.body} onChange={(event) => change({ body: event.target.value })} className="content-input min-h-32" /></Field><Field label="Label Tombol Utama"><Input value={item.primaryLabel ?? ""} onChange={(event) => change({ primaryLabel: event.target.value })} className="content-input" /></Field><Field label="Link Tombol Utama"><Input value={item.primaryHref ?? ""} onChange={(event) => change({ primaryHref: event.target.value })} className="content-input" /></Field><Field label="Label Tombol Kedua"><Input value={item.secondaryLabel ?? ""} onChange={(event) => change({ secondaryLabel: event.target.value })} className="content-input" /></Field><Field label="Link Tombol Kedua"><Input value={item.secondaryHref ?? ""} onChange={(event) => change({ secondaryHref: event.target.value })} className="content-input" /></Field><div className="grid grid-cols-2 gap-3"><Field label="Sembunyikan (hari)"><Input type="number" min={0} max={365} value={item.dismissDays} onChange={(event) => change({ dismissDays: Number(event.target.value) })} className="content-input" /></Field><Field label="Urutan"><Input type="number" min={1} value={item.sortOrder + 1} onChange={(event) => change({ sortOrder: Math.max(0, Number(event.target.value) - 1) })} className="content-input" /></Field></div><label className="flex items-center gap-3 text-[11px] font-semibold"><Switch checked={item.isActive} onCheckedChange={(checked) => change({ isActive: checked })} />Aktif</label></>;
}

function NewsForm({ item, change }: { item: NewsRecord; change(patch: Partial<NewsRecord>): void }) {
  return <><AdminMediaUpload label="Sampul Berita" value={item.coverUrl ?? ""} onChange={(value) => change({ coverUrl: value })} help="Opsional, rekomendasi rasio 16:9." previewClassName="aspect-video" /><Field label="Judul"><Input value={item.title} onChange={(event) => change({ title: event.target.value, slug: item.id ? item.slug : slugify(event.target.value) })} className="content-input" /></Field><Field label="Slug URL"><Input value={item.slug} onChange={(event) => change({ slug: slugify(event.target.value) })} className="content-input" /></Field><Field label="Tanggal Publikasi"><Input type="datetime-local" value={toLocalDate(item.publishedAt)} onChange={(event) => change({ publishedAt: event.target.value ? new Date(event.target.value).toISOString() : "" })} className="content-input" /></Field><Field label="Ringkasan"><Textarea value={item.summary} onChange={(event) => change({ summary: event.target.value })} className="content-input min-h-20" /></Field><Field label="Isi Berita"><Textarea value={item.body} onChange={(event) => change({ body: event.target.value })} className="content-input min-h-44" /></Field><Field label="Urutan"><Input type="number" min={1} value={item.sortOrder + 1} onChange={(event) => change({ sortOrder: Math.max(0, Number(event.target.value) - 1) })} className="content-input" /></Field><label className="flex items-center gap-3 text-[11px] font-semibold"><Switch checked={item.isPublished} onCheckedChange={(checked) => change({ isPublished: checked })} />Terbitkan</label></>;
}

function FaqForm({ item, change }: { item: FaqRecord; change(patch: Partial<FaqRecord>): void }) {
  return <><Field label="Pertanyaan"><Input value={item.question} onChange={(event) => change({ question: event.target.value })} className="content-input" /></Field><Field label="Jawaban"><Textarea value={item.answer} onChange={(event) => change({ answer: event.target.value })} className="content-input min-h-36" /></Field><Field label="Urutan"><Input type="number" min={1} value={item.sortOrder + 1} onChange={(event) => change({ sortOrder: Math.max(0, Number(event.target.value) - 1) })} className="content-input" /></Field><label className="flex items-center gap-3 text-[11px] font-semibold"><Switch checked={item.isActive} onCheckedChange={(checked) => change({ isActive: checked })} />Aktif</label></>;
}

function Panel({ title, description, action, onAdd, children }: { title: string; description: string; action?: string; onAdd?: () => void; children: React.ReactNode }) {
  return <section className="rounded-xl border border-[#dfe5ed] bg-white shadow-sm"><div className="flex items-start justify-between gap-3 px-4 py-3"><div><h3 className="text-sm font-black text-[#101828]">{title}</h3><p className="mt-1 text-[9px] text-[#667085]">{description}</p></div>{action && onAdd && <Button type="button" size="sm" onClick={onAdd} className="h-9 shrink-0 bg-[#155eef] px-3 text-[10px] font-bold text-white hover:bg-[#004eeb]"><Plus className="mr-1 size-3.5" />{action}</Button>}</div>{children}</section>;
}
function DataTable({ headings, children }: { headings: string[]; children: React.ReactNode }) { return <div className="hidden overflow-x-auto border-t border-[#e4e7ec] sm:block"><table className="w-full min-w-[760px] text-left text-[9px]"><thead className="bg-[#f8fafc] text-[#667085]"><tr>{headings.map((item) => <th key={item} className="px-3 py-2.5">{item}</th>)}</tr></thead><tbody>{children}</tbody></table></div>; }
function Cell({ children }: { children: React.ReactNode }) { return <td className="px-3 py-2.5 align-middle text-[9px] text-[#475467]">{children}</td>; }
function Actions({ onEdit, onDelete }: { onEdit(): void; onDelete?: () => void }) { return <div className="flex justify-end gap-1"><Button type="button" variant="outline" size="sm" onClick={onEdit} className="h-8 border-[#d0d5dd] px-2 text-[9px]"><Edit3 className="mr-1 size-3" />Edit</Button>{onDelete && <Button type="button" variant="ghost" size="icon-sm" onClick={onDelete} className="text-red-500"><Trash2 className="size-3.5" /></Button>}</div>; }
function MobileList({ children }: { children: React.ReactNode }) { return <div className="space-y-2 border-t border-[#e4e7ec] p-3 sm:hidden">{children}</div>; }
function MobileRow({ title, subtitle, image, active, onClick }: { title: string; subtitle: string; image?: string; active: boolean; onClick(): void }) { return <button type="button" onClick={onClick} className="flex w-full items-center gap-3 rounded-lg border border-[#e4e7ec] p-3 text-left"><Thumb src={image} /><div className="min-w-0 flex-1"><strong className="block truncate text-[11px] text-[#101828]">{title}</strong><p className="mt-1 line-clamp-2 text-[9px] text-[#667085]">{subtitle}</p></div><span className={active ? "rounded bg-emerald-50 px-2 py-1 text-[8px] font-bold text-emerald-600" : "rounded bg-slate-100 px-2 py-1 text-[8px] font-bold text-[#98a2b3]"}>{active ? "Aktif" : "Nonaktif"}</span></button>; }
function Mini({ title, description, action, onAdd, onOpen, children }: { title: string; description: string; action: string; onAdd?: () => void; onOpen(): void; children: React.ReactNode }) { return <section className="rounded-xl border border-[#dfe5ed] bg-white p-3 shadow-sm"><div className="flex items-start justify-between gap-2"><button type="button" onClick={onOpen} className="text-left"><h3 className="text-sm font-black text-[#101828]">{title}</h3><p className="mt-1 text-[9px] text-[#667085]">{description}</p></button><Button type="button" size="sm" onClick={onAdd || onOpen} className="h-8 shrink-0 bg-[#155eef] px-2.5 text-[9px] text-white"><Plus className="mr-1 size-3" />{action}</Button></div><div className="mt-3 overflow-hidden rounded-lg border border-[#e4e7ec]">{children || <div className="p-4 text-center text-[9px] text-[#98a2b3]">Belum ada data.</div>}</div></section>; }
function MiniRow({ index, title, detail, active, onEdit }: { index: number; title: string; detail: string; active: boolean; onEdit(): void }) { return <div className="grid grid-cols-[22px_minmax(0,1fr)_auto_auto] items-center gap-2 border-t border-[#e4e7ec] px-2 py-2 first:border-t-0"><span>{index}</span><div className="min-w-0"><strong className="block truncate text-[9px] text-[#101828]">{title}</strong><p className={detail.includes("★") ? "truncate text-[8px] text-amber-500" : "truncate text-[8px] text-[#667085]"}>{detail}</p></div><span className={active ? "size-2 rounded-full bg-emerald-500" : "size-2 rounded-full bg-[#d0d5dd]"} /><Button type="button" variant="ghost" size="icon-sm" onClick={onEdit}><Edit3 className="size-3.5 text-[#667085]" /></Button></div>; }
function Thumb({ src, wide = false }: { src?: string; wide?: boolean }) { const size = wide ? "h-10 w-28" : "size-10"; return src ? <img src={src} alt="" className={`${size} rounded-md border border-[#e4e7ec] object-cover`} /> : <span className={`grid ${size} shrink-0 place-items-center rounded-md bg-[#f2f4f7] text-[#98a2b3]`}><ImageIcon className="size-4" /></span>; }
function DeviceBadge({ desktop, mobile }: { desktop: boolean; mobile: boolean }) { const label = desktop && mobile ? "Desktop & Mobile" : desktop ? "Desktop" : mobile ? "Mobile" : "Disembunyikan"; return <Badge danger={mobile && !desktop}>{label}</Badge>; }
function Badge({ danger = false, children }: { danger?: boolean; children: React.ReactNode }) { return <span className={danger ? "rounded bg-red-50 px-2 py-1 text-[8px] font-semibold text-red-600" : "rounded bg-slate-100 px-2 py-1 text-[8px] font-semibold text-[#475467]"}>{children}</span>; }
function Empty({ icon: Icon, text }: { icon: typeof ImageIcon; text: string }) { return <div className="border-t border-[#e4e7ec] px-4 py-10 text-center"><Icon className="mx-auto size-6 text-[#98a2b3]" /><p className="mt-2 text-[10px] text-[#667085]">{text}</p></div>; }
function Phone({ label, children }: { label: string; children: React.ReactNode }) { return <div><p className="mb-2 text-center text-[10px] font-bold">{label}</p><div className="mx-auto max-w-[300px] overflow-hidden rounded-[26px] border-[5px] border-[#101828] bg-white shadow-md"><div className="flex h-7 items-center justify-between px-3 text-[7px] font-bold"><span>9:41</span><span>● ◒ ▰</span></div><div className="min-h-60 bg-[#f8fafc]">{children}</div></div></div>; }
function PreviewEmpty() { return <div className="grid min-h-24 place-items-center text-[9px] text-[#98a2b3]">Belum ada konten aktif</div>; }
function PreviewButton({ active, onClick, icon: Icon, children }: { active: boolean; onClick(): void; icon: typeof Monitor; children: React.ReactNode }) { return <button type="button" onClick={onClick} className={active ? "rounded-md bg-white px-3 py-2 text-[9px] font-bold text-[#155eef] shadow-sm" : "px-3 py-2 text-[9px] text-[#667085]"}><Icon className="mr-1 inline size-3.5" />{children}</button>; }
function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label><span className="mb-1.5 block text-[10px] font-bold">{label}</span>{children}</label>; }
function editorTitle(kind: Editor["kind"]) { return kind === "banner" ? "Edit Banner" : kind === "popup" ? "Edit Pop-up" : kind === "news" ? "Edit Berita" : "Edit FAQ"; }
function emptyBanner(sortOrder: number): HomeBannerRecord { return { id: null, title: "", subtitle: "", imageUrl: "", mobileImageUrl: "", ctaLabel: "Lihat produk", ctaHref: "/catalog", showDesktop: true, showMobile: true, isActive: true, sortOrder }; }
function emptyPopup(sortOrder: number): SitePopupRecord { return { id: null, title: "", body: "", primaryLabel: "", primaryHref: "", secondaryLabel: "", secondaryHref: "", dismissDays: 7, isActive: true, sortOrder }; }
function emptyNews(sortOrder: number): NewsRecord { return { id: null, slug: "", title: "", summary: "", body: "", coverUrl: "", isPublished: false, sortOrder }; }
function slugify(value: string) { return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
function toLocalDate(value?: string) { if (!value) return ""; const date = new Date(value); if (Number.isNaN(date.getTime())) return ""; const offset = date.getTimezoneOffset() * 60_000; return new Date(date.getTime() - offset).toISOString().slice(0, 16); }
function formatDate(value?: string) { if (!value) return "Belum dijadwalkan"; const date = new Date(value); return Number.isNaN(date.getTime()) ? "-" : new Intl.DateTimeFormat("id-ID", { day: "2-digit", month: "short", year: "numeric" }).format(date); }
function updateAt<T>(setter: React.Dispatch<React.SetStateAction<T[]>>, index: number, patch: Partial<T>) { setter((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item)); }
async function readJson<T extends Record<string, unknown>>(response: Response): Promise<T> { const raw = await response.text(); if (!raw) return { error: "Server mengembalikan respons kosong." } as unknown as T; try { return JSON.parse(raw) as T; } catch { return { error: "Server mengembalikan respons tidak valid." } as unknown as T; } }
