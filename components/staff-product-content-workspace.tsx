"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Save, Upload } from "lucide-react";

type Notice = { title: string; body: string; isActive: boolean; sortOrder: number };
type ProductContent = { dbId: number; name: string; slug: string; imageUrl: string; bannerUrl: string; manualInstructions: string; manualOpenTime: string; manualCloseTime: string; manualTimezone: "Asia/Jakarta" | "Asia/Makassar" | "Asia/Jayapura"; notices: Notice[] };

export function StaffProductContentWorkspace() {
  const [products, setProducts] = useState<ProductContent[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [draft, setDraft] = useState<ProductContent | null>(null);
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);
  const load = useCallback(async () => {
    const response = await fetch("/api/panel/product-content", { cache: "no-store" });
    const payload = await response.json().catch(() => ({})) as { products?: ProductContent[]; error?: string };
    if (!response.ok) { setMessage(payload.error || "Konten produk gagal dimuat."); return; }
    const next = payload.products || []; setProducts(next);
    setSelectedId((currentSelectedId) => {
      const current = next.find((item) => item.dbId === currentSelectedId) || next[0] || null;
      setDraft(current ? { ...current, notices: current.notices.map((notice) => ({ ...notice })) } : null);
      return current?.dbId || null;
    });
  }, []);
  useEffect(() => { void load(); }, [load]);
  function select(id: number) { const current = products.find((item) => item.dbId === id) || null; setSelectedId(id); setDraft(current ? { ...current, notices: current.notices.map((notice) => ({ ...notice })) } : null); }
  async function upload(file: File | undefined, field: "imageUrl" | "bannerUrl") {
    if (!file) return; const form = new FormData(); form.set("file", file);
    const response = await fetch("/api/panel/media", { method: "POST", body: form });
    const payload = await response.json().catch(() => ({})) as { url?: string; error?: string };
    if (!response.ok || !payload.url) { setMessage(payload.error || "Gambar gagal diunggah."); return; }
    setDraft((current) => current ? { ...current, [field]: payload.url! } : current);
  }
  async function save(event: FormEvent) {
    event.preventDefault(); if (!draft) return; setSaving(true); setMessage("");
    const response = await fetch("/api/panel/product-content", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(draft) });
    const payload = await response.json().catch(() => ({})) as { error?: string };
    setSaving(false); if (!response.ok) { setMessage(payload.error || "Konten produk gagal disimpan."); return; }
    setMessage("Konten produk tersimpan dan langsung dipakai katalog pelanggan."); await load();
  }
  return <section className="mt-5 rounded-lg border border-[#dfe6ef] bg-white p-5 text-[#14213a] shadow-[0_1px_4px_rgba(20,33,58,.04)]">
    <header><h2 className="text-base font-extrabold">Konten Produk</h2><p className="mt-1 text-xs text-[#718198]">Staff hanya dapat mengubah gambar, banner, instruksi, jam layanan, dan notice produk.</p></header>
    {message && <p className="mt-3 rounded border border-blue-100 bg-blue-50 p-3 text-xs text-[#155bb5]">{message}</p>}
    <div className="mt-4 grid gap-4 lg:grid-cols-[260px_1fr]"><aside><label className="text-xs font-bold">Pilih produk<select value={selectedId || ""} onChange={(event) => select(Number(event.target.value))} className="mt-2 h-10 w-full rounded border border-[#dce3eb] bg-white px-3 text-sm">{products.map((product) => <option key={product.dbId} value={product.dbId}>{product.name}</option>)}</select></label></aside>{draft && <form onSubmit={save} className="grid gap-3 md:grid-cols-2"><Field label="URL gambar" value={draft.imageUrl} onChange={(value) => setDraft({ ...draft, imageUrl: value })} /><UploadButton label="Unggah gambar" onFile={(file) => void upload(file, "imageUrl")} /><Field label="URL banner" value={draft.bannerUrl} onChange={(value) => setDraft({ ...draft, bannerUrl: value })} /><UploadButton label="Unggah banner" onFile={(file) => void upload(file, "bannerUrl")} /><label className="md:col-span-2 text-xs font-bold">Instruksi pemenuhan<textarea value={draft.manualInstructions} onChange={(event) => setDraft({ ...draft, manualInstructions: event.target.value })} className="mt-2 min-h-24 w-full rounded border border-[#dce3eb] p-3 text-sm" /></label><Field label="Buka (HH:MM)" value={draft.manualOpenTime} onChange={(value) => setDraft({ ...draft, manualOpenTime: value })} /><Field label="Tutup (HH:MM)" value={draft.manualCloseTime} onChange={(value) => setDraft({ ...draft, manualCloseTime: value })} /><label className="text-xs font-bold">Zona waktu<select value={draft.manualTimezone} onChange={(event) => setDraft({ ...draft, manualTimezone: event.target.value as ProductContent["manualTimezone"] })} className="mt-2 h-10 w-full rounded border border-[#dce3eb] bg-white px-3 text-sm"><option>Asia/Jakarta</option><option>Asia/Makassar</option><option>Asia/Jayapura</option></select></label><div /><label className="md:col-span-2 text-xs font-bold">Notice pelanggan<textarea value={draft.notices.map((notice) => notice.title + "|" + notice.body).join("\n")} onChange={(event) => setDraft({ ...draft, notices: event.target.value.split("\n").filter(Boolean).slice(0, 10).map((line, index) => { const [title, ...body] = line.split("|"); return { title: title.trim() || "Informasi", body: body.join("|").trim() || "-", isActive: true, sortOrder: index }; }) })} placeholder="Judul|Isi notice (satu baris per notice)" className="mt-2 min-h-24 w-full rounded border border-[#dce3eb] p-3 text-sm" /></label><div className="md:col-span-2 flex justify-end"><button disabled={saving} className="inline-flex h-10 items-center gap-2 rounded bg-[#0875ed] px-4 text-sm font-bold text-white disabled:opacity-50"><Save className="size-4" />{saving ? "Menyimpan..." : "Simpan Konten Produk"}</button></div></form>}</div>
  </section>;
}
function Field({ label, value, onChange }: { label: string; value: string; onChange(value: string): void }) { return <label className="text-xs font-bold">{label}<input value={value} onChange={(event) => onChange(event.target.value)} className="mt-2 h-10 w-full rounded border border-[#dce3eb] px-3 text-sm" /></label>; }
function UploadButton({ label, onFile }: { label: string; onFile(file: File | undefined): void }) { return <label className="mt-5 inline-flex h-10 cursor-pointer items-center justify-center gap-2 rounded border border-[#dce3eb] text-sm font-bold"><Upload className="size-4" />{label}<input className="sr-only" type="file" accept="image/*" onChange={(event) => onFile(event.target.files?.[0])} /></label>; }
