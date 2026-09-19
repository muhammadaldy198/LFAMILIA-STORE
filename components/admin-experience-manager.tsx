"use client";
/* eslint-disable @next/next/no-img-element, react-hooks/set-state-in-effect */

import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import {
  ImageIcon,
  Pencil,
  Plus,
  Save,
  Smartphone,
  Monitor,
  Upload,
  X,
} from "lucide-react";

type ContentKind = "banner" | "popup" | "news" | "review" | "faq";
type Editor = { kind: ContentKind; id: number };
type BannerPayload = { id?: number | null; title: string; subtitle: string; imageUrl: string; mobileImageUrl?: string; ctaLabel: string; ctaHref: string; showDesktop: boolean; showMobile: boolean; isActive: boolean; sortOrder: number };
type PopupPayload = { id?: number | null; title: string; body: string; primaryLabel?: string; primaryHref: string; secondaryLabel?: string; secondaryHref: string; dismissDays: number; isActive: boolean; sortOrder: number };
type NewsPayload = { id?: number | null; slug: string; title: string; summary: string; body: string; coverUrl?: string; isPublished: boolean; publishedAt?: string; sortOrder: number };
type FaqPayload = { id?: number | null; question: string; answer: string; isActive: boolean; sortOrder: number };
type ReviewPayload = { id: number; customerName: string; body: string; rating: number; isVisible: boolean; createdAt: string; productSlug: string };
type Banner = { id: number; title: string; href: string; devices: string; order: number; active: boolean; tone: string; raw?: BannerPayload };
type MiniItem = { id: number; title: string; detail: string; active: boolean; raw?: PopupPayload | NewsPayload | FaqPayload | ReviewPayload };

const tabs: Array<{ value: ContentKind; label: string }> = [
  { value: "banner", label: "Banner" },
  { value: "popup", label: "Pop-up" },
  { value: "news", label: "Berita" },
  { value: "review", label: "Ulasan" },
  { value: "faq", label: "FAQ" },
];

function mapBanner(item: BannerPayload): Banner {
  return { id: Number(item.id), title: item.title, href: item.ctaHref, devices: item.showDesktop && item.showMobile ? "Desktop & Mobile" : item.showDesktop ? "Desktop" : "Mobile", order: item.sortOrder + 1, active: item.isActive, tone: "from-[#07152f] via-[#123a78] to-[#d33f68]", raw: item };
}
function mapPopup(item: PopupPayload): MiniItem { return { id: Number(item.id), title: item.title, detail: item.body, active: item.isActive, raw: item }; }
function mapNews(item: NewsPayload): MiniItem { return { id: Number(item.id), title: item.title, detail: item.publishedAt || "Belum diterbitkan", active: item.isPublished, raw: item }; }
function mapFaq(item: FaqPayload): MiniItem { return { id: Number(item.id), title: item.question, detail: item.answer, active: item.isActive, raw: item }; }
function mapReview(item: ReviewPayload): MiniItem { return { id: item.id, title: item.customerName, detail: item.body, active: item.isVisible, raw: item }; }

async function panelJson(response: Response) {
  const payload = await response.json().catch(() => ({})) as { error?: string };
  if (!response.ok) throw new Error(payload.error || "Permintaan konten gagal diproses.");
  return payload;
}

async function saveContentItem(kind: Exclude<ContentKind, "review" | "faq"> | "faq", item: BannerPayload | PopupPayload | NewsPayload | FaqPayload) {
  if (kind === "faq") return panelJson(await fetch("/api/panel/faqs", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(item) }));
  return panelJson(await fetch("/api/panel/content", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ kind, item }) }));
}

async function saveReview(item: ReviewPayload) {
  return panelJson(await fetch("/api/panel/reviews", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: item.id, isVisible: item.isVisible }) }));
}

function togglePayload(kind: ContentKind, raw: NonNullable<Banner["raw"]> | NonNullable<MiniItem["raw"]>) {
  if (kind === "banner") return { ...(raw as BannerPayload), isActive: !(raw as BannerPayload).isActive };
  if (kind === "popup") return { ...(raw as PopupPayload), isActive: !(raw as PopupPayload).isActive };
  if (kind === "news") return { ...(raw as NewsPayload), isPublished: !(raw as NewsPayload).isPublished };
  return { ...(raw as FaqPayload), isActive: !(raw as FaqPayload).isActive };
}

function editPayload(kind: Exclude<ContentKind, "review">, raw: NonNullable<Banner["raw"]> | NonNullable<MiniItem["raw"]>, data: FormData, title: string) {
  const active = data.get("active") === "on";
  const text = (name: string, fallback = "") => String(data.get(name) ?? fallback).trim();
  const order = Math.max(0, Number(data.get("order") || 1) - 1);
  if (kind === "banner") return { ...(raw as BannerPayload), title, imageUrl: text("imageUrl", (raw as BannerPayload).imageUrl), mobileImageUrl: text("mobileImageUrl", (raw as BannerPayload).mobileImageUrl || ""), ctaHref: text("href"), showDesktop: data.get("showDesktop") === "on", showMobile: data.get("showMobile") === "on", sortOrder: order, isActive: active };
  if (kind === "popup") return { ...(raw as PopupPayload), title, body: text("body", "Isi pop-up"), primaryLabel: text("primaryLabel"), primaryHref: text("primaryHref", "/"), secondaryLabel: text("secondaryLabel"), secondaryHref: text("secondaryHref"), dismissDays: Math.max(0, Number(data.get("dismissDays") || 0)), sortOrder: order, isActive: active };
  if (kind === "news") return { ...(raw as NewsPayload), slug: text("slug", (raw as NewsPayload).slug), title, summary: text("summary"), body: text("body", "Isi berita"), coverUrl: text("coverUrl", (raw as NewsPayload).coverUrl || ""), publishedAt: text("publishedAt"), sortOrder: order, isPublished: active };
  return { ...(raw as FaqPayload), question: title, answer: text("answer", "Jawaban FAQ"), sortOrder: order, isActive: active };
}

export function AdminExperienceManager({ role }: { role: "super_admin" | "admin" | "staff" }) {
  const [activeTab, setActiveTab] = useState<ContentKind>("banner");
  const [banners, setBanners] = useState<Banner[]>([]);
  const [popups, setPopups] = useState<MiniItem[]>([]);
  const [news, setNews] = useState<MiniItem[]>([]);
  const [reviews, setReviews] = useState<MiniItem[]>([]);
  const [faqs, setFaqs] = useState<MiniItem[]>([]);
  const [editor, setEditor] = useState<Editor>({ kind: "banner", id: 0 });
  const [previewMode, setPreviewMode] = useState<"desktop" | "mobile">("mobile");
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function loadContent() {
    setError("");
    try {
      const [contentResponse, faqResponse, reviewResponse] = await Promise.all([
        fetch("/api/panel/content", { cache: "no-store" }),
        fetch("/api/panel/faqs", { cache: "no-store" }),
        fetch("/api/panel/reviews", { cache: "no-store" }),
      ]);
      const content = await contentResponse.json().catch(() => ({})) as { banners?: BannerPayload[]; popups?: PopupPayload[]; news?: NewsPayload[]; error?: string };
      const faqData = await faqResponse.json().catch(() => ({})) as { faqs?: FaqPayload[]; error?: string };
      const reviewData = await reviewResponse.json().catch(() => ({})) as { reviews?: ReviewPayload[]; error?: string };
      if (!contentResponse.ok) throw new Error(content.error || "Konten gagal dimuat.");
      if (!faqResponse.ok) throw new Error(faqData.error || "FAQ gagal dimuat.");
      if (!reviewResponse.ok) throw new Error(reviewData.error || "Ulasan gagal dimuat.");
      const mappedBanners = (content.banners || []).map(mapBanner);
      const mappedPopups = (content.popups || []).map(mapPopup);
      const mappedNews = (content.news || []).map(mapNews);
      const mappedFaqs = (faqData.faqs || []).map(mapFaq);
      const mappedReviews = (reviewData.reviews || []).map(mapReview);
      setBanners(mappedBanners); setPopups(mappedPopups); setNews(mappedNews); setFaqs(mappedFaqs); setReviews(mappedReviews);
      const first = mappedBanners[0] || mappedPopups[0] || mappedNews[0] || mappedReviews[0] || mappedFaqs[0];
      if (first) setEditor({ kind: mappedBanners[0] ? "banner" : mappedPopups[0] ? "popup" : mappedNews[0] ? "news" : mappedReviews[0] ? "review" : "faq", id: first.id });
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Konten gagal dimuat."); }
  }

  useEffect(() => { void loadContent(); }, []);

  function focus(kind: ContentKind, id: number) {
    setActiveTab(kind);
    setEditor({ kind, id });

    if (window.matchMedia("(max-width: 1023px)").matches) {
      window.requestAnimationFrame(() => {
        document.getElementById("admin-content-editor")?.scrollIntoView({
          behavior: "smooth",
          block: "start",
        });
      });
    }
  }

  function add(kind: ContentKind) {
    const setters = { popup: setPopups, news: setNews, review: setReviews, faq: setFaqs };
    if (kind === "banner") {
      const item: Banner = { id: -Date.now(), title: "Banner Baru", href: "/", devices: "Desktop & Mobile", order: banners.length + 1, active: true, tone: "from-[#123776] via-[#1857ad] to-[#713bd4]", raw: { id: null, title: "Banner Baru", subtitle: "", imageUrl: "/logo-lfamilia.png", mobileImageUrl: "", ctaLabel: "", ctaHref: "", showDesktop: true, showMobile: true, isActive: true, sortOrder: banners.length } };
      setBanners((current) => [...current, item]); focus("banner", item.id); return;
    }
    if (kind === "review") { setNotice("Ulasan hanya dibuat oleh pelanggan yang sudah bertransaksi; admin dapat mengatur visibilitasnya."); return; }
    const id = -Date.now();
    const item: MiniItem = kind === "popup"
      ? { id, title: "Pop-up Baru", detail: "Isi pengumuman", active: true, raw: { id: null, title: "Pop-up Baru", body: "Isi pengumuman", primaryLabel: "Lihat", primaryHref: "/", secondaryLabel: "", secondaryHref: "", dismissDays: 1, isActive: true, sortOrder: popups.length } }
      : kind === "news"
        ? { id, title: "Berita Baru", detail: "Belum diterbitkan", active: false, raw: { id: null, slug: `berita-${Date.now()}`, title: "Berita Baru", summary: "Ringkasan berita", body: "Isi berita", coverUrl: "", isPublished: false, publishedAt: "", sortOrder: news.length } }
        : { id, title: "Pertanyaan Baru", detail: "Jawaban FAQ", active: true, raw: { id: null, question: "Pertanyaan baru?", answer: "Jawaban FAQ", isActive: true, sortOrder: faqs.length } };
    setters[kind]((current) => [...current, item]); focus(kind, item.id);
  }

  async function toggle(kind: ContentKind, id: number) {
    if (kind === "banner") setBanners((current) => current.map((item) => item.id === id ? { ...item, active: !item.active } : item));
    else if (kind === "popup") setPopups((current) => flip(current, id));
    else if (kind === "news") setNews((current) => flip(current, id));
    else if (kind === "review") setReviews((current) => flip(current, id));
    else setFaqs((current) => flip(current, id));
    const current = kind === "banner" ? banners.find((item) => item.id === id) : kind === "popup" ? popups.find((item) => item.id === id) : kind === "news" ? news.find((item) => item.id === id) : kind === "review" ? reviews.find((item) => item.id === id) : faqs.find((item) => item.id === id);
    if (!current?.raw || id < 1) return;
    try {
      if (kind === "review") await saveReview({ ...(current.raw as ReviewPayload), isVisible: !current.active });
      else await saveContentItem(kind, togglePayload(kind, current.raw));
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Status konten gagal disimpan."); await loadContent(); }
  }

  async function saveEditor(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const title = String(data.get("title") || "Tanpa judul");
    const current = editor.kind === "banner" ? banners.find((item) => item.id === editor.id) : editor.kind === "popup" ? popups.find((item) => item.id === editor.id) : editor.kind === "news" ? news.find((item) => item.id === editor.id) : editor.kind === "review" ? reviews.find((item) => item.id === editor.id) : faqs.find((item) => item.id === editor.id);
    if (!current?.raw) { setError("Pilih konten yang ingin diedit."); return; }
    setSaving(true); setError("");
    try {
      if (editor.kind === "review") await saveReview({ ...(current.raw as ReviewPayload), isVisible: data.get("active") === "on" });
      else await saveContentItem(editor.kind, editPayload(editor.kind, current.raw, data, title));
      setNotice("Perubahan konten berhasil disimpan ke database.");
      await loadContent();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Konten gagal disimpan."); }
    finally { setSaving(false); }
  }

  async function deleteEditor() {
    if (editor.id < 1 || editor.kind === "review") { setError(editor.kind === "review" ? "Ulasan tidak dihapus; nonaktifkan agar riwayat pelanggan tetap tersimpan." : "Konten baru belum tersimpan."); return; }
    setSaving(true); setError("");
    try {
      const endpoint = editor.kind === "faq" ? `/api/panel/faqs?id=${editor.id}` : `/api/panel/content?kind=${editor.kind}&id=${editor.id}`;
      await panelJson(await fetch(endpoint, { method: "DELETE" }));
      setNotice(`${labelKind(editor.kind)} berhasil dihapus.`);
      await loadContent();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Konten gagal dihapus."); }
    finally { setSaving(false); }
  }

  function updateImage(kind: ContentKind, id: number, url: string, target: "desktop" | "mobile" | "cover" = "desktop") {
    if (kind === "banner") {
      setBanners((current) => current.map((item) => item.id === id && item.raw
        ? { ...item, raw: { ...item.raw, ...(target === "mobile" ? { mobileImageUrl: url } : { imageUrl: url }) } }
        : item));
    } else if (kind === "news") {
      setNews((current) => current.map((item) => item.id === id && item.raw ? { ...item, raw: { ...(item.raw as NewsPayload), coverUrl: url } } : item));
    }
    setNotice("Gambar berhasil diunggah. Klik Simpan Perubahan untuk menerapkannya.");
  }

  return (
    <div className="admin-content-reference min-w-0 text-[#14213a]">
      <header>
        <h1 className="text-[21px] font-black tracking-[-0.035em] text-[#0c1933]">Banner, Pop-up, Berita & Ulasan</h1>
        <p className="mt-[4px] text-[9px] text-[#65768d]">Kelola semua konten tampilan pelanggan di halaman utama.</p>
      </header>

      {notice && <button type="button" onClick={() => setNotice("")} className="mt-[9px] flex w-full items-center justify-between rounded-[5px] border border-[#bce4cf] bg-[#edf9f3] px-[11px] py-[7px] text-left text-[8px] font-semibold text-[#168653]"><span>{notice}</span><X className="size-[11px]" /></button>}
      {error && <button type="button" onClick={() => setError("")} className="mt-[9px] w-full rounded-[5px] border border-red-200 bg-red-50 px-[11px] py-[7px] text-left text-[8px] text-red-700">{error}</button>}

      <nav className="mt-[10px] flex overflow-x-auto border-b border-[#dfe5ec]">{tabs.map((tab) => <button type="button" key={tab.value} onClick={() => setActiveTab(tab.value)} className={`h-[38px] border-b-2 px-[15px] text-[9px] font-bold ${activeTab === tab.value ? "border-[#0875ed] text-[#0875ed]" : "border-transparent text-[#53647c]"}`}>{tab.label}</button>)}</nav>

      <div className="mt-[10px] grid grid-cols-1 gap-[12px] xl:grid-cols-[minmax(0,1fr)_300px]">
        <main className="min-w-0 space-y-[10px]">
          <ContentPanel title="Daftar Banner" description="Atur banner yang tampil di halaman utama. Kamu bisa mengatur urutan, status, dan link tujuan." action="Tambah Banner" onAdd={() => add("banner")}>
            <BannerTable items={banners} onEdit={(id) => focus("banner", id)} onToggle={(id) => toggle("banner", id)} />
          </ContentPanel>

          <div className="grid grid-cols-1 gap-[10px] md:grid-cols-2">
            <MiniPanel title="Pop-up" description="Kelola pop-up informasi, promo, atau pengumuman." action="Tambah Pop-up" items={popups} kind="popup" onAdd={() => add("popup")} onEdit={focus} onToggle={toggle} />
            <MiniPanel title="Berita" description="Kelola berita yang ditampilkan di halaman utama." action="Tulis Berita" items={news} kind="news" onAdd={() => add("news")} onEdit={focus} onToggle={toggle} />
          </div>
          <div className="grid grid-cols-1 gap-[10px] md:grid-cols-2">
            <MiniPanel title="Ulasan Pelanggan" description="Kelola ulasan yang tampil di halaman utama." action="Tambah Ulasan" items={reviews} kind="review" onAdd={() => add("review")} onEdit={focus} onToggle={toggle} reviews />
            <MiniPanel title="FAQ" description="Kelola pertanyaan yang sering ditanyakan." action="Tambah FAQ" items={faqs} kind="faq" onAdd={() => add("faq")} onEdit={focus} onToggle={toggle} />
          </div>
        </main>

        <EditorPanel editor={editor} banners={banners} popups={popups} news={news} reviews={reviews} faqs={faqs} canDelete={role !== "staff"} saving={saving} onAdd={() => add(editor.kind)} onDelete={() => void deleteEditor()} onCancel={() => void loadContent()} onImage={updateImage} onError={setError} onSubmit={saveEditor} />
      </div>

      <PreviewPanel banners={banners} popups={popups} news={news} reviews={reviews} faqs={faqs} mode={previewMode} onMode={setPreviewMode} />
    </div>
  );
}

function BannerTable({ items, onEdit, onToggle }: { items: Banner[]; onEdit(id: number): void; onToggle(id: number): void }) {
  return <div className="overflow-x-auto border-t border-[#e5eaf0]"><table className="w-full min-w-[720px] table-fixed text-left"><thead className="bg-[#f3f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[30px] px-[10px] py-[8px]">#</th><th className="w-[120px]">Gambar</th><th className="w-[145px]">Nama Internal</th><th className="w-[150px]">Link Tujuan</th><th className="w-[105px]">Tampilan</th><th className="w-[55px]">Urutan</th><th className="w-[55px]">Status</th><th className="w-[85px]">Aksi</th></tr></thead><tbody>{items.map((item, index) => <tr key={item.id} className="border-t border-[#e5eaf0] text-[7px] text-[#35475f]"><td className="px-[10px] py-[7px]">{index + 1}</td><td><BannerArtwork banner={item} /></td><td className="truncate pr-[8px] font-semibold">{item.title}</td><td className="truncate pr-[8px] font-semibold text-[#0875df]">{item.href}</td><td><span className={`rounded-[4px] px-[6px] py-[4px] font-semibold ${item.devices === "Mobile" ? "bg-red-50 text-red-600" : "bg-[#edf2f7] text-[#4e6077]"}`}>{item.devices}</span></td><td><span className="grid size-[28px] place-items-center rounded-[4px] border border-[#dce3eb] bg-white">{item.order}</span></td><td><Switch enabled={item.active} onToggle={() => onToggle(item.id)} /></td><td><div className="flex items-center gap-[6px]"><button type="button" onClick={() => onEdit(item.id)} className="inline-flex h-[27px] items-center gap-[4px] rounded-[4px] border border-[#dce3eb] bg-white px-[9px] font-bold"><Pencil className="size-[10px]" />Edit</button></div></td></tr>)}</tbody></table></div>;
}

function MiniPanel({ title, description, action, items, kind, onAdd, onEdit, onToggle, reviews: isReviews }: { title: string; description: string; action: string; items: MiniItem[]; kind: ContentKind; onAdd(): void; onEdit(kind: ContentKind, id: number): void; onToggle(kind: ContentKind, id: number): void; reviews?: boolean }) {
  return <ContentPanel title={title} description={description} action={action} onAdd={onAdd}><div className="border-t border-[#e5eaf0]"><div className="grid grid-cols-[24px_1fr_90px_50px_70px] bg-[#f3f6fa] px-[9px] py-[8px] text-[6.5px] font-bold text-[#52647c]"><span>#</span><span>{isReviews ? "Pelanggan" : kind === "faq" ? "Pertanyaan" : "Judul"}</span><span>{isReviews ? "Rating / Pesan" : kind === "news" ? "Tanggal" : kind === "faq" ? "Urutan" : "Tampilan"}</span><span>Status</span><span>Aksi</span></div>{items.map((item, index) => <div key={item.id} className="grid min-h-[39px] grid-cols-[24px_1fr_90px_50px_70px] items-center border-t border-[#e7ebf0] px-[9px] text-[7px] text-[#35475f]"><span>{index + 1}</span><strong className="truncate pr-[5px]">{item.title}</strong><span className={`truncate pr-[4px] ${isReviews ? "text-amber-500" : ""}`}>{isReviews ? <><span className="block tracking-[-1px]">{"★".repeat(Math.max(0, Math.min(5, Number((item.raw as ReviewPayload | undefined)?.rating || 0))))}</span><small className="block truncate text-[6px] text-[#51627a]">{item.detail}</small></> : item.detail}</span><Switch enabled={item.active} onToggle={() => onToggle(kind, item.id)} /><div className="flex items-center gap-[5px]"><button type="button" onClick={() => onEdit(kind, item.id)} className="inline-flex h-[26px] items-center gap-[4px] rounded-[4px] border border-[#dce3eb] bg-white px-[8px] font-bold"><Pencil className="size-[9px]" />Edit</button></div></div>)}</div></ContentPanel>;
}

function ContentPanel({ title, description, action, onAdd, children }: { title: string; description: string; action: string; onAdd(): void; children: ReactNode }) { return <section className="overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]"><header className="flex items-start justify-between px-[13px] py-[11px]"><div><h2 className="text-[12px] font-extrabold text-[#101d35]">{title}</h2><p className="mt-[2px] text-[7.5px] text-[#6b7c92]">{description}</p></div><button type="button" onClick={onAdd} className="inline-flex h-[30px] items-center gap-[5px] rounded-[4px] bg-[#0875ed] px-[11px] text-[7.5px] font-bold text-white"><Plus className="size-[11px]" />{action}</button></header>{children}</section>; }

function EditorPanel({ editor, banners, popups, news, reviews, faqs, canDelete, saving, onAdd, onDelete, onCancel, onImage, onError, onSubmit }: { editor: Editor; banners: Banner[]; popups: MiniItem[]; news: MiniItem[]; reviews: MiniItem[]; faqs: MiniItem[]; canDelete: boolean; saving: boolean; onAdd(): void; onDelete(): void; onCancel(): void; onImage(kind: ContentKind, id: number, url: string, target?: "desktop" | "mobile" | "cover"): void; onError(message: string): void; onSubmit(event: FormEvent<HTMLFormElement>): void }) {
  const banner = editor.kind === "banner" ? banners.find((item) => item.id === editor.id) : undefined;
  const item = editor.kind === "popup" ? popups.find((entry) => entry.id === editor.id) : editor.kind === "news" ? news.find((entry) => entry.id === editor.id) : editor.kind === "review" ? reviews.find((entry) => entry.id === editor.id) : editor.kind === "faq" ? faqs.find((entry) => entry.id === editor.id) : undefined;
  const title = editor.kind === "banner" ? "Edit Banner" : editor.kind === "popup" ? "Edit Pop-up" : editor.kind === "news" ? "Edit Berita" : editor.kind === "review" ? "Edit Ulasan" : "Edit FAQ";
  const currentImage = banner?.raw?.imageUrl || (editor.kind === "news" ? (item?.raw as NewsPayload | undefined)?.coverUrl : "");
  const popup = editor.kind === "popup" ? item?.raw as PopupPayload | undefined : undefined;
  const article = editor.kind === "news" ? item?.raw as NewsPayload | undefined : undefined;
  const faq = editor.kind === "faq" ? item?.raw as FaqPayload | undefined : undefined;
  const review = editor.kind === "review" ? item?.raw as ReviewPayload | undefined : undefined;
  async function uploadImage(file?: File, target: "desktop" | "mobile" | "cover" = "desktop") {
    if (!file || !["banner", "news"].includes(editor.kind)) return;
    const form = new FormData(); form.set("file", file);
    try {
      const response = await fetch("/api/panel/media", { method: "POST", body: form });
      const payload = await response.json().catch(() => ({})) as { url?: string; error?: string };
      if (!response.ok || !payload.url) throw new Error(payload.error || "Gambar gagal diunggah.");
      onImage(editor.kind, editor.id, payload.url, editor.kind === "news" ? "cover" : target);
    } catch (reason) { onError(reason instanceof Error ? reason.message : "Gambar gagal diunggah."); }
  }
  return (
    <aside id="admin-content-editor" className="sticky top-[70px] self-start overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white shadow-[0_8px_24px_rgba(18,35,60,.08)]">
      <header className="flex h-[47px] items-center justify-between border-b border-[#e5eaf0] px-[13px]">
        <h2 className="text-[12px] font-extrabold">{title}</h2>
        <button type="button" onClick={onAdd} className="inline-flex h-[29px] items-center gap-[5px] rounded-[4px] bg-[#0875ed] px-[10px] text-[7.5px] font-bold text-white"><Plus className="size-[11px]" />Tambah {labelKind(editor.kind)}</button>
      </header>
      <form key={`${editor.kind}-${editor.id}`} onSubmit={onSubmit} className="p-[13px]">
        {editor.kind === "banner" ? (
          <div className="grid gap-[10px] sm:grid-cols-2">
            <BannerImageUpload label="Gambar Desktop" value={banner?.raw?.imageUrl ?? ""} hint="1920 × 600" onUpload={(file) => void uploadImage(file, "desktop")} />
            <BannerImageUpload label="Gambar Mobile" value={banner?.raw?.mobileImageUrl ?? ""} hint="1080 × 1080 (opsional)" onUpload={(file) => void uploadImage(file, "mobile")} />
          </div>
        ) : (
          <>
            <label className="text-[8px] font-bold">Gambar {labelKind(editor.kind)}
              <div className="mt-[5px] grid h-[75px] place-items-center overflow-hidden rounded-[5px] border border-[#dce3eb]">
                {currentImage ? <img src={currentImage} alt="" className="size-full object-cover" /> : <span className="text-center text-[#718198]"><ImageIcon className="mx-auto size-[22px]" /><small className="mt-[4px] block">Preview gambar</small></span>}
              </div>
            </label>
            {editor.kind === "news" && <label className="mt-[6px] inline-flex h-[30px] w-full cursor-pointer items-center justify-center gap-[6px] rounded-[4px] border border-[#dce3eb] bg-white text-[8px] font-bold"><Upload className="size-[11px]" />Ganti Gambar<input type="file" accept="image/*" className="sr-only" onChange={(event) => void uploadImage(event.target.files?.[0], "cover")} /></label>}
          </>
        )}
        <EditorField label={editor.kind === "banner" ? "Nama internal / alt banner" : editor.kind === "faq" ? "Pertanyaan" : editor.kind === "review" ? "Pelanggan" : "Judul"} name="title" defaultValue={banner?.title ?? item?.title ?? ""} readOnly={editor.kind === "review"} />
        {editor.kind === "banner" && <>
          <EditorField label="URL gambar desktop" name="imageUrl" defaultValue={banner?.raw?.imageUrl ?? ""} />
          <EditorField label="URL gambar mobile (opsional)" name="mobileImageUrl" defaultValue={banner?.raw?.mobileImageUrl ?? ""} />
          <EditorField label="Link Tujuan (opsional — klik gambar)" name="href" defaultValue={banner?.raw?.ctaHref ?? ""} />
          <p className="mt-[11px] text-[8px] font-bold">Tampilkan di</p>
          <div className="mt-[7px] flex justify-between text-[8px] font-semibold">
            <label className="flex items-center gap-[5px]"><input type="checkbox" name="showDesktop" defaultChecked={banner?.raw?.showDesktop ?? true} />Desktop</label>
            <label className="flex items-center gap-[5px]"><input type="checkbox" name="showMobile" defaultChecked={banner?.raw?.showMobile ?? true} />Mobile</label>
          </div>
          <EditorField label="Urutan" name="order" type="number" defaultValue={String(banner?.order ?? 1)} />
        </>}
        {editor.kind === "popup" && <>
          <EditorTextArea label="Isi pop-up" name="body" defaultValue={popup?.body ?? ""} />
          <EditorField label="Teks tombol utama" name="primaryLabel" defaultValue={popup?.primaryLabel ?? ""} />
          <EditorField label="Link tombol utama" name="primaryHref" defaultValue={popup?.primaryHref ?? "/"} />
          <EditorField label="Teks tombol kedua (opsional)" name="secondaryLabel" defaultValue={popup?.secondaryLabel ?? ""} />
          <EditorField label="Link tombol kedua (opsional)" name="secondaryHref" defaultValue={popup?.secondaryHref ?? ""} />
          <EditorField label="Sembunyikan lagi setelah (hari)" name="dismissDays" type="number" defaultValue={String(popup?.dismissDays ?? 0)} />
          <EditorField label="Urutan" name="order" type="number" defaultValue={String((popup?.sortOrder ?? 0) + 1)} />
        </>}
        {editor.kind === "news" && <>
          <EditorField label="Slug URL" name="slug" defaultValue={article?.slug ?? ""} />
          <EditorTextArea label="Ringkasan" name="summary" defaultValue={article?.summary ?? ""} />
          <EditorTextArea label="Isi berita" name="body" defaultValue={article?.body ?? ""} rows={7} />
          <EditorField label="URL cover" name="coverUrl" defaultValue={article?.coverUrl ?? ""} />
          <EditorField label="Tanggal terbit (ISO, opsional)" name="publishedAt" defaultValue={article?.publishedAt ?? ""} />
          <EditorField label="Urutan" name="order" type="number" defaultValue={String((article?.sortOrder ?? 0) + 1)} />
        </>}
        {editor.kind === "faq" && <>
          <EditorTextArea label="Jawaban" name="answer" defaultValue={faq?.answer ?? ""} rows={6} />
          <EditorField label="Urutan" name="order" type="number" defaultValue={String((faq?.sortOrder ?? 0) + 1)} />
        </>}
        {editor.kind === "review" && <div className="mt-[11px] rounded-[4px] border border-[#e1e7ef] bg-[#f8fafc] p-[9px] text-[8px] text-[#53647c]"><p className="font-bold text-[#263953]">{review?.rating ?? 0} / 5 bintang</p><p className="mt-[4px] leading-4">{review?.body || "Pesan ulasan pelanggan"}</p><p className="mt-[4px] text-[7px]">Produk: {review?.productSlug || "Tidak tercatat"}</p></div>}
        <label className="mt-[12px] flex items-center gap-[8px] text-[8px] font-bold"><input type="checkbox" name="active" defaultChecked={banner?.active ?? item?.active ?? true} className="sr-only peer" /><span className="relative h-[19px] w-[34px] rounded-full bg-[#ccd6e2] peer-checked:bg-[#0875ed] after:absolute after:left-[2px] after:top-[2px] after:size-[15px] after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-[15px]" />Aktif</label>
        <div className="mt-[30px] flex justify-end gap-[7px]">
          <button type="button" onClick={onCancel} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[13px] text-[8px] font-bold">Batal</button>
          {canDelete && <button type="button" disabled={saving} onClick={onDelete} className="h-[32px] rounded-[4px] border border-red-100 bg-red-50 px-[10px] text-[8px] font-bold text-red-500 disabled:opacity-50">Hapus</button>}
          <button type="submit" disabled={saving} className="inline-flex h-[32px] items-center gap-[5px] rounded-[4px] bg-[#0875ed] px-[13px] text-[8px] font-bold text-white disabled:opacity-50"><Save className="size-[11px]" />{saving ? "Menyimpan..." : "Simpan Perubahan"}</button>
        </div>
      </form>
    </aside>
  );
}

function BannerImageUpload({ label, value, hint, onUpload }: { label: string; value: string; hint: string; onUpload(file?: File): void }) { return <label className="text-[8px] font-bold">{label}<div className="mt-[5px] grid h-[90px] place-items-center overflow-hidden rounded-[5px] border border-[#dce3eb] bg-[#f8fafc]">{value ? <img src={value} alt="" className="size-full object-cover" /> : <span className="text-center text-[#718198]"><ImageIcon className="mx-auto size-[22px]" /><small className="mt-[4px] block">Belum ada gambar</small></span>}</div><span className="mt-[6px] inline-flex h-[30px] w-full cursor-pointer items-center justify-center gap-[6px] rounded-[4px] border border-[#dce3eb] bg-white text-[8px] font-bold"><Upload className="size-[11px]" />{value ? "Ganti Gambar" : "Unggah Gambar"}<input type="file" accept="image/*" className="sr-only" onChange={(event) => { const file = event.target.files?.[0]; event.currentTarget.value = ""; onUpload(file); }} /></span><small className="mt-[4px] block font-normal text-[#718198]">{hint} · Maks. 2MB</small></label>; }

function EditorField({ label, name, defaultValue, type = "text", readOnly = false }: { label: string; name: string; defaultValue: string; type?: string; readOnly?: boolean }) { return <label className="mt-[11px] block text-[8px] font-bold">{label}<input key={defaultValue} name={name} type={type} defaultValue={defaultValue} readOnly={readOnly} className="mt-[5px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px] font-medium outline-none focus:border-[#2781ed] read-only:bg-[#f5f7fa]" /></label>; }
function EditorTextArea({ label, name, defaultValue, rows = 4 }: { label: string; name: string; defaultValue: string; rows?: number }) { return <label className="mt-[11px] block text-[8px] font-bold">{label}<textarea key={defaultValue} name={name} defaultValue={defaultValue} rows={rows} className="mt-[5px] w-full resize-y rounded-[4px] border border-[#dce3eb] px-[9px] py-[8px] text-[8px] font-medium outline-none focus:border-[#2781ed]" /></label>; }

function PreviewPanel({ banners, popups, news, reviews, faqs, mode, onMode }: { banners: Banner[]; popups: MiniItem[]; news: MiniItem[]; reviews: MiniItem[]; faqs: MiniItem[]; mode: "desktop" | "mobile"; onMode(value: "desktop" | "mobile"): void }) {
  const popup = popups.find((item) => item.active);
  return <section className="mt-[12px] overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white p-[13px]"><header className="flex items-start justify-between"><div><h2 className="text-[12px] font-extrabold">Preview Tampilan di Website</h2><p className="mt-[2px] text-[7.5px] text-[#6a7b91]">Preview memakai data yang sedang dikelola untuk banner, pop-up, berita, ulasan, dan FAQ.</p></div><div className="flex overflow-hidden rounded-[4px] border border-[#dce3eb]"><button type="button" onClick={() => onMode("desktop")} className={`inline-flex h-[29px] items-center gap-[5px] px-[10px] text-[7.5px] font-bold ${mode === "desktop" ? "bg-[#0875ed] text-white" : "bg-white text-[#4f6078]"}`}><Monitor className="size-[11px]" />Tampilan Desktop</button><button type="button" onClick={() => onMode("mobile")} className={`inline-flex h-[29px] items-center gap-[5px] px-[10px] text-[7.5px] font-bold ${mode === "mobile" ? "bg-[#0875ed] text-white" : "bg-white text-[#4f6078]"}`}><Smartphone className="size-[11px]" />Tampilan Mobile</button></div></header><div className={`mt-[12px] grid gap-[10px] ${mode === "mobile" ? "mx-auto max-w-[300px] grid-cols-1" : "grid-cols-1 md:grid-cols-2 xl:grid-cols-3"}`}><PreviewCard title="Banner"><Phone mode={mode}><div className="p-[7px]"><div className="mb-[7px] h-[25px] rounded bg-white px-[7px] py-[6px] text-[6px] text-[#8290a3]">⌕ Cari game atau layanan...</div>{banners.find((item) => item.active) ? <BannerArtwork banner={banners.find((item) => item.active)!} large mode={mode} /> : <EmptyPreview text="Belum ada banner aktif" />}</div></Phone></PreviewCard><PreviewCard title="Pop-up"><Phone mode={mode}><div className="relative h-full bg-[#1b2940]/55 p-[24px_12px]">{popup ? <div className="rounded-[6px] bg-gradient-to-br from-[#0875ed] via-[#2b7ee8] to-[#d64db6] p-[13px] text-center text-white shadow"><strong className="text-[11px]">{popup.title}</strong><p className="mt-[4px] line-clamp-3 text-[7px]">{popup.detail}</p><button type="button" className="mt-[15px] h-[25px] w-full rounded-[4px] bg-[#075bd4] text-[7px] font-bold">{(popup.raw as PopupPayload).primaryLabel || "Tutup"}</button></div> : <EmptyPreview text="Belum ada pop-up aktif" />}</div></Phone></PreviewCard><PreviewCard title="Berita"><Phone mode={mode}><div className="p-[8px]"><h3 className="text-[10px] font-black">Berita Terbaru</h3>{news.filter((item) => item.active).slice(0, 2).map((item) => <div key={item.id} className="mt-[7px] rounded-[5px] border bg-white p-[7px]"><strong className="block text-[7px]">{item.title}</strong><span className="text-[6px] text-[#718198]">{item.detail}</span></div>)}</div></Phone></PreviewCard><PreviewCard title="Ulasan"><Phone mode={mode}><div className="p-[8px]"><h3 className="text-[10px] font-black">Ulasan Pelanggan</h3>{reviews.filter((item) => item.active).slice(0, 2).map((item) => <div key={item.id} className="mt-[7px] rounded-[5px] border bg-white p-[7px]"><strong className="text-[7px]">{item.title}</strong><div className="text-[7px] text-amber-500">{"★".repeat(Number((item.raw as ReviewPayload).rating || 0))}</div><span className="text-[6px] text-[#607189]">{item.detail}</span></div>)}</div></Phone></PreviewCard><PreviewCard title="FAQ"><Phone mode={mode}><div className="p-[8px]"><h3 className="text-[10px] font-black">Pertanyaan Umum</h3>{faqs.filter((item) => item.active).slice(0, 2).map((item) => <div key={item.id} className="mt-[7px] rounded-[5px] border bg-white p-[7px]"><strong className="block text-[7px]">{item.title}</strong><span className="line-clamp-2 text-[6px] text-[#718198]">{item.detail}</span></div>)}</div></Phone></PreviewCard></div></section>;
}

function PreviewCard({ title, children }: { title: string; children: ReactNode }) { return <div><h3 className="mb-[6px] text-center text-[8px] font-extrabold">{title}</h3>{children}</div>; }
function Phone({ children, mode }: { children: ReactNode; mode: "desktop" | "mobile" }) { return <div className={`mx-auto overflow-hidden border-[5px] border-[#101923] bg-[#f4f7fa] shadow-[0_7px_18px_rgba(17,34,58,.15)] ${mode === "mobile" ? "h-[245px] max-w-[220px] rounded-[25px]" : "h-[230px] w-full rounded-[10px]"}`}><div className="flex h-[22px] items-center justify-between bg-white px-[10px] text-[6px] font-bold"><span>9:41</span><span>● ◔ ▰</span></div><div className="h-[218px] overflow-hidden">{children}</div></div>; }
function EmptyPreview({ text }: { text: string }) { return <div className="grid h-full place-items-center text-center text-[7px] text-[#718198]">{text}</div>; }
function BannerArtwork({ banner, large, mode = "desktop" }: { banner: Banner; large?: boolean; mode?: "desktop" | "mobile" }) { const imageUrl = mode === "mobile" ? (banner.raw?.mobileImageUrl || banner.raw?.imageUrl) : banner.raw?.imageUrl; return <div className={`grid place-items-center overflow-hidden rounded-[4px] bg-[#edf2f7] ${large ? "h-full w-full" : "h-[38px] w-[108px]"}`}>{imageUrl ? <img src={imageUrl} alt={banner.title} className="size-full object-cover" /> : <span className="px-2 text-center text-[6px] font-bold text-[#718198]">Belum ada gambar</span>}</div>; }
function Switch({ enabled, onToggle }: { enabled: boolean; onToggle(): void }) { return <button type="button" aria-pressed={enabled} onClick={onToggle} className={`relative h-[18px] w-[33px] rounded-full ${enabled ? "bg-[#0875ed]" : "bg-[#c9d4e0]"}`}><span className={`absolute top-[2px] size-[14px] rounded-full bg-white shadow transition ${enabled ? "left-[17px]" : "left-[2px]"}`} /></button>; }
function flip(items: MiniItem[], id: number) { return items.map((item) => item.id === id ? { ...item, active: !item.active } : item); }
function labelKind(kind: ContentKind) { return kind === "banner" ? "Banner" : kind === "popup" ? "Pop-up" : kind === "news" ? "Berita" : kind === "review" ? "Ulasan" : "FAQ"; }
