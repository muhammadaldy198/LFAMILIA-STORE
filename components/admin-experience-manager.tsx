"use client";
/* eslint-disable @next/next/no-img-element */

import { useState, type FormEvent, type ReactNode } from "react";
import {
  ImageIcon,
  MoreVertical,
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
type Banner = { id: number; title: string; href: string; devices: string; order: number; active: boolean; tone: string };
type MiniItem = { id: number; title: string; detail: string; active: boolean };

const tabs: Array<{ value: ContentKind; label: string }> = [
  { value: "banner", label: "Banner" },
  { value: "popup", label: "Pop-up" },
  { value: "news", label: "Berita" },
  { value: "review", label: "Ulasan" },
  { value: "faq", label: "FAQ" },
];

const initialBanners: Banner[] = [
  { id: 1, title: "Top Up Game Termurah", href: "/produk/mobile-legends", devices: "Desktop & Mobile", order: 1, active: true, tone: "from-[#07152f] via-[#123a78] to-[#d33f68]" },
  { id: 2, title: "Weekly Diamond Pass", href: "/produk/mobile-legends", devices: "Desktop & Mobile", order: 2, active: true, tone: "from-[#111d5c] via-[#4238b8] to-[#20a4ef]" },
  { id: 3, title: "Promo Spesial Bulan Ini", href: "/promo", devices: "Desktop", order: 3, active: true, tone: "from-[#160d25] via-[#97132a] to-[#f39222]" },
  { id: 4, title: "Jadi Member Sekarang", href: "/membership", devices: "Mobile", order: 4, active: false, tone: "from-[#321062] via-[#801285] to-[#1378c9]" },
];

const initialPopups: MiniItem[] = [
  { id: 1, title: "Promo Ramadhan", detail: "Semua Halaman", active: true },
  { id: 2, title: "Pengumuman Maintenance", detail: "Halaman Utama", active: true },
  { id: 3, title: "Event Spesial", detail: "Semua Halaman", active: false },
];
const initialNews: MiniItem[] = [
  { id: 1, title: "Event Top Up Spesial", detail: "24 Apr 2025", active: true },
  { id: 2, title: "Update Layanan", detail: "22 Apr 2025", active: true },
  { id: 3, title: "Maintenance Sistem", detail: "20 Apr 2025", active: false },
];
const initialReviews: MiniItem[] = [
  { id: 1, title: "R*****", detail: "Proses cepat dan aman!", active: true },
  { id: 2, title: "D*****", detail: "Harga paling murah!", active: true },
  { id: 3, title: "A*****", detail: "Pelayanan sangat baik!", active: false },
];
const initialFaqs: MiniItem[] = [
  { id: 1, title: "Berapa lama proses top up?", detail: "1", active: true },
  { id: 2, title: "Metode pembayaran apa saja?", detail: "2", active: true },
  { id: 3, title: "Apakah transaksi aman?", detail: "3", active: true },
];

export function AdminExperienceManager({ role }: { role: "owner" | "staff" }) {
  const [activeTab, setActiveTab] = useState<ContentKind>("banner");
  const [banners, setBanners] = useState(initialBanners);
  const [popups, setPopups] = useState(initialPopups);
  const [news, setNews] = useState(initialNews);
  const [reviews, setReviews] = useState(initialReviews);
  const [faqs, setFaqs] = useState(initialFaqs);
  const [editor, setEditor] = useState<Editor>({ kind: "banner", id: 1 });
  const [previewMode, setPreviewMode] = useState<"desktop" | "mobile">("mobile");
  const [notice, setNotice] = useState("");

  function focus(kind: ContentKind, id: number) {
    setActiveTab(kind);
    setEditor({ kind, id });
  }

  function add(kind: ContentKind) {
    const setters = { popup: setPopups, news: setNews, review: setReviews, faq: setFaqs };
    if (kind === "banner") {
      const item: Banner = { id: Date.now(), title: "Banner Baru", href: "/", devices: "Desktop & Mobile", order: banners.length + 1, active: true, tone: "from-[#123776] via-[#1857ad] to-[#713bd4]" };
      setBanners((current) => [...current, item]); focus("banner", item.id); return;
    }
    const item: MiniItem = { id: Date.now(), title: kind === "popup" ? "Pop-up Baru" : kind === "news" ? "Berita Baru" : kind === "review" ? "Ulasan Baru" : "Pertanyaan Baru", detail: kind === "review" ? "Pesan pelanggan" : kind === "faq" ? String(faqs.length + 1) : "Belum dijadwalkan", active: true };
    setters[kind]((current) => [...current, item]); focus(kind, item.id);
  }

  function toggle(kind: ContentKind, id: number) {
    if (kind === "banner") setBanners((current) => current.map((item) => item.id === id ? { ...item, active: !item.active } : item));
    else if (kind === "popup") setPopups((current) => flip(current, id));
    else if (kind === "news") setNews((current) => flip(current, id));
    else if (kind === "review") setReviews((current) => flip(current, id));
    else setFaqs((current) => flip(current, id));
  }

  function saveEditor(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const title = String(data.get("title") || "Tanpa judul");
    if (editor.kind === "banner") setBanners((current) => current.map((item) => item.id === editor.id ? { ...item, title, href: String(data.get("href") || "/"), order: Number(data.get("order") || 1), active: data.get("active") === "on" } : item));
    else {
      const patch = (items: MiniItem[]) => items.map((item) => item.id === editor.id ? { ...item, title, detail: String(data.get("detail") || item.detail), active: data.get("active") === "on" } : item);
      if (editor.kind === "popup") setPopups(patch); else if (editor.kind === "news") setNews(patch); else if (editor.kind === "review") setReviews(patch); else setFaqs(patch);
    }
    setNotice("Perubahan tampilan berhasil disimpan sementara di frontend.");
  }

  return (
    <div className="admin-content-reference min-w-0 text-[#14213a]">
      <header>
        <h1 className="text-[21px] font-black tracking-[-0.035em] text-[#0c1933]">Banner, Pop-up, Berita & Ulasan</h1>
        <p className="mt-[4px] text-[9px] text-[#65768d]">Kelola semua konten tampilan pelanggan di halaman utama.</p>
      </header>

      {notice && <button type="button" onClick={() => setNotice("")} className="mt-[9px] flex w-full items-center justify-between rounded-[5px] border border-[#bce4cf] bg-[#edf9f3] px-[11px] py-[7px] text-left text-[8px] font-semibold text-[#168653]"><span>{notice}</span><X className="size-[11px]" /></button>}

      <nav className="mt-[10px] flex border-b border-[#dfe5ec]">{tabs.map((tab) => <button type="button" key={tab.value} onClick={() => setActiveTab(tab.value)} className={`h-[38px] border-b-2 px-[15px] text-[9px] font-bold ${activeTab === tab.value ? "border-[#0875ed] text-[#0875ed]" : "border-transparent text-[#53647c]"}`}>{tab.label}</button>)}</nav>

      <div className="mt-[10px] grid grid-cols-[minmax(0,1fr)_300px] gap-[12px]">
        <main className="min-w-0 space-y-[10px]">
          <ContentPanel title="Daftar Banner" description="Atur banner yang tampil di halaman utama. Kamu bisa mengatur urutan, status, dan link tujuan." action="Tambah Banner" onAdd={() => add("banner")}>
            <BannerTable items={banners} onEdit={(id) => focus("banner", id)} onToggle={(id) => toggle("banner", id)} />
          </ContentPanel>

          <div className="grid grid-cols-2 gap-[10px]">
            <MiniPanel title="Pop-up" description="Kelola pop-up informasi, promo, atau pengumuman." action="Tambah Pop-up" items={popups} kind="popup" onAdd={() => add("popup")} onEdit={focus} onToggle={toggle} />
            <MiniPanel title="Berita" description="Kelola berita yang ditampilkan di halaman utama." action="Tulis Berita" items={news} kind="news" onAdd={() => add("news")} onEdit={focus} onToggle={toggle} />
          </div>
          <div className="grid grid-cols-2 gap-[10px]">
            <MiniPanel title="Ulasan Pelanggan" description="Kelola ulasan yang tampil di halaman utama." action="Tambah Ulasan" items={reviews} kind="review" onAdd={() => add("review")} onEdit={focus} onToggle={toggle} reviews />
            <MiniPanel title="FAQ" description="Kelola pertanyaan yang sering ditanyakan." action="Tambah FAQ" items={faqs} kind="faq" onAdd={() => add("faq")} onEdit={focus} onToggle={toggle} />
          </div>
        </main>

        <EditorPanel editor={editor} banners={banners} popups={popups} news={news} reviews={reviews} faqs={faqs} canDelete={role === "owner"} onAdd={() => add(editor.kind)} onSubmit={saveEditor} />
      </div>

      <PreviewPanel banners={banners} popups={popups} news={news} reviews={reviews} mode={previewMode} onMode={setPreviewMode} />
    </div>
  );
}

function BannerTable({ items, onEdit, onToggle }: { items: Banner[]; onEdit(id: number): void; onToggle(id: number): void }) {
  return <div className="overflow-x-auto border-t border-[#e5eaf0]"><table className="w-full min-w-[720px] table-fixed text-left"><thead className="bg-[#f3f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[30px] px-[10px] py-[8px]">#</th><th className="w-[120px]">Gambar</th><th className="w-[145px]">Judul</th><th className="w-[150px]">Link Tujuan</th><th className="w-[105px]">Tampilan</th><th className="w-[55px]">Urutan</th><th className="w-[55px]">Status</th><th className="w-[85px]">Aksi</th></tr></thead><tbody>{items.map((item, index) => <tr key={item.id} className="border-t border-[#e5eaf0] text-[7px] text-[#35475f]"><td className="px-[10px] py-[7px]">{index + 1}</td><td><BannerArtwork banner={item} /></td><td className="truncate pr-[8px] font-semibold">{item.title}</td><td className="truncate pr-[8px] font-semibold text-[#0875df]">{item.href}</td><td><span className={`rounded-[4px] px-[6px] py-[4px] font-semibold ${item.devices === "Mobile" ? "bg-red-50 text-red-600" : "bg-[#edf2f7] text-[#4e6077]"}`}>{item.devices}</span></td><td><span className="grid size-[28px] place-items-center rounded-[4px] border border-[#dce3eb] bg-white">{item.order}</span></td><td><Switch enabled={item.active} onToggle={() => onToggle(item.id)} /></td><td><div className="flex items-center gap-[6px]"><button type="button" onClick={() => onEdit(item.id)} className="inline-flex h-[27px] items-center gap-[4px] rounded-[4px] border border-[#dce3eb] bg-white px-[9px] font-bold"><Pencil className="size-[10px]" />Edit</button><MoreVertical className="size-[12px]" /></div></td></tr>)}</tbody></table></div>;
}

function MiniPanel({ title, description, action, items, kind, onAdd, onEdit, onToggle, reviews: isReviews }: { title: string; description: string; action: string; items: MiniItem[]; kind: ContentKind; onAdd(): void; onEdit(kind: ContentKind, id: number): void; onToggle(kind: ContentKind, id: number): void; reviews?: boolean }) {
  return <ContentPanel title={title} description={description} action={action} onAdd={onAdd}><div className="border-t border-[#e5eaf0]"><div className="grid grid-cols-[24px_1fr_90px_50px_70px] bg-[#f3f6fa] px-[9px] py-[8px] text-[6.5px] font-bold text-[#52647c]"><span>#</span><span>{isReviews ? "Pelanggan" : kind === "faq" ? "Pertanyaan" : "Judul"}</span><span>{isReviews ? "Rating / Pesan" : kind === "news" ? "Tanggal" : kind === "faq" ? "Urutan" : "Tampilan"}</span><span>Status</span><span>Aksi</span></div>{items.map((item, index) => <div key={item.id} className="grid min-h-[39px] grid-cols-[24px_1fr_90px_50px_70px] items-center border-t border-[#e7ebf0] px-[9px] text-[7px] text-[#35475f]"><span>{index + 1}</span><strong className="truncate pr-[5px]">{item.title}</strong><span className={`truncate pr-[4px] ${isReviews ? "text-amber-500" : ""}`}>{isReviews ? <><span className="block tracking-[-1px]">★★★★★</span><small className="block truncate text-[6px] text-[#51627a]">{item.detail}</small></> : item.detail}</span><Switch enabled={item.active} onToggle={() => onToggle(kind, item.id)} /><div className="flex items-center gap-[5px]"><button type="button" onClick={() => onEdit(kind, item.id)} className="inline-flex h-[26px] items-center gap-[4px] rounded-[4px] border border-[#dce3eb] bg-white px-[8px] font-bold"><Pencil className="size-[9px]" />Edit</button><MoreVertical className="size-[11px]" /></div></div>)}</div></ContentPanel>;
}

function ContentPanel({ title, description, action, onAdd, children }: { title: string; description: string; action: string; onAdd(): void; children: ReactNode }) { return <section className="overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]"><header className="flex items-start justify-between px-[13px] py-[11px]"><div><h2 className="text-[12px] font-extrabold text-[#101d35]">{title}</h2><p className="mt-[2px] text-[7.5px] text-[#6b7c92]">{description}</p></div><button type="button" onClick={onAdd} className="inline-flex h-[30px] items-center gap-[5px] rounded-[4px] bg-[#0875ed] px-[11px] text-[7.5px] font-bold text-white"><Plus className="size-[11px]" />{action}</button></header>{children}</section>; }

function EditorPanel({ editor, banners, popups, news, reviews, faqs, canDelete, onAdd, onSubmit }: { editor: Editor; banners: Banner[]; popups: MiniItem[]; news: MiniItem[]; reviews: MiniItem[]; faqs: MiniItem[]; canDelete: boolean; onAdd(): void; onSubmit(event: FormEvent<HTMLFormElement>): void }) {
  const banner = editor.kind === "banner" ? banners.find((item) => item.id === editor.id) : undefined;
  const item = editor.kind === "popup" ? popups.find((entry) => entry.id === editor.id) : editor.kind === "news" ? news.find((entry) => entry.id === editor.id) : editor.kind === "review" ? reviews.find((entry) => entry.id === editor.id) : editor.kind === "faq" ? faqs.find((entry) => entry.id === editor.id) : undefined;
  const title = editor.kind === "banner" ? "Edit Banner" : editor.kind === "popup" ? "Edit Pop-up" : editor.kind === "news" ? "Edit Berita" : editor.kind === "review" ? "Edit Ulasan" : "Edit FAQ";
  return <aside className="sticky top-[70px] self-start overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white shadow-[0_8px_24px_rgba(18,35,60,.08)]"><header className="flex h-[47px] items-center justify-between border-b border-[#e5eaf0] px-[13px]"><h2 className="text-[12px] font-extrabold">{title}</h2><button type="button" onClick={onAdd} className="inline-flex h-[29px] items-center gap-[5px] rounded-[4px] bg-[#0875ed] px-[10px] text-[7.5px] font-bold text-white"><Plus className="size-[11px]" />Tambah {labelKind(editor.kind)}</button></header><form onSubmit={onSubmit} className="p-[13px]"><label className="text-[8px] font-bold">Gambar {labelKind(editor.kind)}<div className={`mt-[5px] grid place-items-center overflow-hidden rounded-[5px] border border-[#dce3eb] ${editor.kind === "banner" ? "h-[98px]" : "h-[75px]"}`}>{banner ? <BannerArtwork banner={banner} large /> : <span className="text-center text-[#718198]"><ImageIcon className="mx-auto size-[22px]" /><small className="mt-[4px] block">Preview gambar</small></span>}</div></label><button type="button" className="mt-[6px] inline-flex h-[30px] w-full items-center justify-center gap-[6px] rounded-[4px] border border-[#dce3eb] bg-white text-[8px] font-bold"><Upload className="size-[11px]" />Ganti Gambar</button><p className="mt-[6px] text-[6.5px] leading-[1.5] text-[#718198]">Rekomendasi ukuran: 1920 × 600 (Desktop)<br />1080 × 1080 (Mobile) | Maks. 2MB</p><EditorField label={editor.kind === "faq" ? "Pertanyaan" : editor.kind === "review" ? "Pelanggan" : "Judul"} name="title" defaultValue={banner?.title ?? item?.title ?? ""} /><EditorField label={editor.kind === "banner" ? "Link Tujuan" : editor.kind === "review" ? "Pesan" : editor.kind === "faq" ? "Jawaban" : "Tampilan / Tanggal"} name={editor.kind === "banner" ? "href" : "detail"} defaultValue={banner?.href ?? item?.detail ?? ""} />{editor.kind === "banner" && <><p className="mt-[11px] text-[8px] font-bold">Tampilkan di</p><div className="mt-[7px] flex justify-between text-[8px] font-semibold"><label className="flex items-center gap-[5px]"><input type="checkbox" defaultChecked />Desktop</label><label className="flex items-center gap-[5px]"><input type="checkbox" defaultChecked />Mobile</label></div><EditorField label="Urutan" name="order" type="number" defaultValue={String(banner?.order ?? 1)} /></>}<label className="mt-[12px] flex items-center gap-[8px] text-[8px] font-bold"><input type="checkbox" name="active" defaultChecked={banner?.active ?? item?.active ?? true} className="sr-only peer" /><span className="relative h-[19px] w-[34px] rounded-full bg-[#ccd6e2] peer-checked:bg-[#0875ed] after:absolute after:left-[2px] after:top-[2px] after:size-[15px] after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-[15px]" />Aktif</label><div className="mt-[30px] flex justify-end gap-[7px]"><button type="button" className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[13px] text-[8px] font-bold">Batal</button>{canDelete && <button type="button" className="h-[32px] rounded-[4px] border border-red-100 bg-red-50 px-[10px] text-[8px] font-bold text-red-500">Hapus</button>}<button type="submit" className="inline-flex h-[32px] items-center gap-[5px] rounded-[4px] bg-[#0875ed] px-[13px] text-[8px] font-bold text-white"><Save className="size-[11px]" />Simpan Perubahan</button></div></form></aside>;
}

function EditorField({ label, name, defaultValue, type = "text" }: { label: string; name: string; defaultValue: string; type?: string }) { return <label className="mt-[11px] block text-[8px] font-bold">{label}<input key={defaultValue} name={name} type={type} defaultValue={defaultValue} className="mt-[5px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px] font-medium outline-none focus:border-[#2781ed]" /></label>; }

function PreviewPanel({ banners, popups, news, reviews, mode, onMode }: { banners: Banner[]; popups: MiniItem[]; news: MiniItem[]; reviews: MiniItem[]; mode: "desktop" | "mobile"; onMode(value: "desktop" | "mobile"): void }) {
  return <section className="mt-[12px] overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white p-[13px]"><header className="flex items-start justify-between"><div><h2 className="text-[12px] font-extrabold">Preview Tampilan di Website</h2><p className="mt-[2px] text-[7.5px] text-[#6a7b91]">Berikut contoh tampilan banner, pop-up, berita, dan ulasan di halaman utama (mobile & desktop).</p></div><div className="flex overflow-hidden rounded-[4px] border border-[#dce3eb]"><button type="button" onClick={() => onMode("desktop")} className={`inline-flex h-[29px] items-center gap-[5px] px-[10px] text-[7.5px] font-bold ${mode === "desktop" ? "bg-[#0875ed] text-white" : "bg-white text-[#4f6078]"}`}><Monitor className="size-[11px]" />Tampilan Desktop</button><button type="button" onClick={() => onMode("mobile")} className={`inline-flex h-[29px] items-center gap-[5px] px-[10px] text-[7.5px] font-bold ${mode === "mobile" ? "bg-[#0875ed] text-white" : "bg-white text-[#4f6078]"}`}><Smartphone className="size-[11px]" />Tampilan Mobile</button></div></header><div className={`mt-[12px] grid gap-[10px] ${mode === "mobile" ? "grid-cols-4" : "grid-cols-2"}`}><PreviewCard title="Banner"><Phone mode={mode}><div className="p-[7px]"><div className="mb-[7px] h-[25px] rounded bg-white px-[7px] py-[6px] text-[6px] text-[#8290a3]">⌕ Cari game atau layanan...</div>{banners[0] && <BannerArtwork banner={banners[0]} large />}</div></Phone></PreviewCard><PreviewCard title="Pop-up"><Phone mode={mode}><div className="relative h-full bg-[#1b2940]/55 p-[24px_12px]"><div className="rounded-[6px] bg-gradient-to-br from-[#0875ed] via-[#2b7ee8] to-[#d64db6] p-[13px] text-center text-white shadow"><strong className="text-[11px]">PROMO SPESIAL</strong><p className="mt-[4px] text-[7px]">Top Up Sekarang</p><button type="button" className="mt-[15px] h-[25px] w-full rounded-[4px] bg-[#075bd4] text-[7px] font-bold">Top Up Sekarang</button></div></div></Phone></PreviewCard><PreviewCard title="Berita"><Phone mode={mode}><div className="p-[8px]"><h3 className="text-[10px] font-black">Berita Terbaru</h3>{news.slice(0, 2).map((item) => <div key={item.id} className="mt-[7px] rounded-[5px] border bg-white p-[7px]"><strong className="block text-[7px]">{item.title}</strong><span className="text-[6px] text-[#718198]">{item.detail}</span></div>)}</div></Phone></PreviewCard><PreviewCard title="Ulasan"><Phone mode={mode}><div className="p-[8px]"><h3 className="text-[10px] font-black">Ulasan Pelanggan</h3>{reviews.slice(0, 2).map((item) => <div key={item.id} className="mt-[7px] rounded-[5px] border bg-white p-[7px]"><strong className="text-[7px]">{item.title}</strong><div className="text-[7px] text-amber-500">★★★★★</div><span className="text-[6px] text-[#607189]">{item.detail}</span></div>)}</div></Phone></PreviewCard></div></section>;
}

function PreviewCard({ title, children }: { title: string; children: ReactNode }) { return <div><h3 className="mb-[6px] text-center text-[8px] font-extrabold">{title}</h3>{children}</div>; }
function Phone({ children, mode }: { children: ReactNode; mode: "desktop" | "mobile" }) { return <div className={`mx-auto overflow-hidden border-[5px] border-[#101923] bg-[#f4f7fa] shadow-[0_7px_18px_rgba(17,34,58,.15)] ${mode === "mobile" ? "h-[245px] max-w-[220px] rounded-[25px]" : "h-[230px] w-full rounded-[10px]"}`}><div className="flex h-[22px] items-center justify-between bg-white px-[10px] text-[6px] font-bold"><span>9:41</span><span>● ◔ ▰</span></div><div className="h-[218px] overflow-hidden">{children}</div></div>; }
function BannerArtwork({ banner, large }: { banner: Banner; large?: boolean }) { return <div className={`relative overflow-hidden rounded-[4px] bg-gradient-to-r ${banner.tone} text-white ${large ? "h-full w-full" : "h-[38px] w-[108px]"}`}><div className={`absolute inset-0 bg-[radial-gradient(circle_at_85%_30%,rgba(255,255,255,.35),transparent_28%)] ${large ? "p-[13px]" : "p-[5px]"}`}><strong className={`block max-w-[65%] font-black leading-[1.05] ${large ? "text-[12px]" : "text-[6px]"}`}>{banner.title.toUpperCase()}</strong><small className={`font-bold text-[#dcff35] ${large ? "text-[7px]" : "text-[4px]"}`}>PROSES CEPAT & AMAN</small></div></div>; }
function Switch({ enabled, onToggle }: { enabled: boolean; onToggle(): void }) { return <button type="button" aria-pressed={enabled} onClick={onToggle} className={`relative h-[18px] w-[33px] rounded-full ${enabled ? "bg-[#0875ed]" : "bg-[#c9d4e0]"}`}><span className={`absolute top-[2px] size-[14px] rounded-full bg-white shadow transition ${enabled ? "left-[17px]" : "left-[2px]"}`} /></button>; }
function flip(items: MiniItem[], id: number) { return items.map((item) => item.id === id ? { ...item, active: !item.active } : item); }
function labelKind(kind: ContentKind) { return kind === "banner" ? "Banner" : kind === "popup" ? "Pop-up" : kind === "news" ? "Berita" : kind === "review" ? "Ulasan" : "FAQ"; }
