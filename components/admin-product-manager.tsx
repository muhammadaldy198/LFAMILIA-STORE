"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import { BellRing, ChevronDown, ChevronUp, Database, Edit3, LoaderCircle, PackagePlus, Plus, RefreshCw, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger } from "@/components/ui/alert-dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatRupiah } from "@/lib/store-data";
import { providerOptions } from "@/lib/provider-options";
import type { ManagedProduct } from "@/lib/server/products";
import { ProductArtwork } from "@/components/product-artwork";
import type { ProductCategoryRecord } from "@/lib/server/storefront";
import { AdminMediaUpload } from "@/components/admin-media-upload";

type DraftPackage = ManagedProduct["packages"][number];
type DraftNotice = ManagedProduct["notices"][number];

const emptyProduct: ManagedProduct = {
  dbId: null,
  slug: "",
  name: "",
  publisher: "",
  category: "game",
  imageUrl: "",
  bannerUrl: "",
  initials: "",
  accent: "from-[#b9ff35] to-[#347a21]",
  inputLabel: "User ID",
  inputPlaceholder: "Masukkan User ID",
  needsServer: false,
  popular: false,
  instant: true,
  fulfillmentType: "automatic",
  targetTemplate: "{{destination}}{{server}}",
  manualInstructions: "",
  manualOpenTime: "09:00",
  manualCloseTime: "21:00",
  manualTimezone: "Asia/Jakarta",
  isActive: true,
  sortOrder: 0,
  notices: [],
  packages: [{ dbId: null, id: "", label: "", price: 0, pricingMode: "auto", marginType: "fixed", marginValue: 0, isActive: true, sortOrder: 0 }],
};

export function AdminProductManager() {
  const [items, setItems] = useState<ManagedProduct[]>([]);
  const [databaseReady, setDatabaseReady] = useState(true);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [seeding, setSeeding] = useState(false);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [draft, setDraft] = useState<ManagedProduct>(emptyProduct);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [role, setRole] = useState<"owner" | "staff">("staff");
  const [categories, setCategories] = useState<ProductCategoryRecord[]>([]);

  const loadProducts = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const data = await requestProducts();
      setItems(data.products ?? []);
      setRole(data.role ?? "staff");
      setDatabaseReady(data.databaseReady !== false);
    } catch (reason) {
      setDatabaseReady(false);
      setError(reason instanceof Error ? reason.message : "Produk gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let active = true;
    void requestProducts().then((data) => {
      if (!active) return;
      setItems(data.products ?? []);
      setRole(data.role ?? "staff");
      setDatabaseReady(data.databaseReady !== false);
    }).catch((reason) => {
      if (!active) return;
      setDatabaseReady(false);
      setError(reason instanceof Error ? reason.message : "Produk gagal dimuat.");
    }).finally(() => {
      if (active) setLoading(false);
    });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    void fetch("/api/panel/categories", { cache: "no-store" }).then((response) => response.json()).then((data: { categories?: ProductCategoryRecord[] }) => setCategories(data.categories ?? []));
  }, []);

  function openNew() {
    setDraft(structuredClone(emptyProduct));
    setError("");
    setDialogOpen(true);
  }

  function openEdit(item: ManagedProduct) {
    setDraft(structuredClone(item));
    setError("");
    setDialogOpen(true);
  }

  function updateDraft<K extends keyof ManagedProduct>(key: K, value: ManagedProduct[K]) {
    setDraft((current) => ({ ...current, [key]: value }));
  }

  function updatePackage<K extends keyof DraftPackage>(index: number, key: K, value: DraftPackage[K]) {
    setDraft((current) => ({
      ...current,
      packages: current.packages.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: value } : item),
    }));
  }

  function addPackage() {
    setDraft((current) => ({
      ...current,
      packages: [...current.packages, { dbId: null, id: "", label: "", price: 0, pricingMode: "auto", marginType: "fixed", marginValue: 0, isActive: true, sortOrder: current.packages.length }],
    }));
  }

  function removePackage(index: number) {
    setDraft((current) => ({ ...current, packages: current.packages.filter((_, itemIndex) => itemIndex !== index) }));
  }

  function addNotice() {
    setDraft((current) => ({
      ...current,
      notices: [...current.notices, { id: null, title: "Informasi penting", body: "", isActive: true, sortOrder: current.notices.length }],
    }));
  }

  function updateNotice<K extends keyof DraftNotice>(index: number, key: K, value: DraftNotice[K]) {
    setDraft((current) => ({ ...current, notices: current.notices.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: value } : item) }));
  }

  function removeNotice(index: number) {
    setDraft((current) => ({ ...current, notices: current.notices.filter((_, itemIndex) => itemIndex !== index) }));
  }

  function moveNotice(index: number, direction: -1 | 1) {
    setDraft((current) => {
      const nextIndex = index + direction;
      if (nextIndex < 0 || nextIndex >= current.notices.length) return current;
      const notices = [...current.notices];
      [notices[index], notices[nextIndex]] = [notices[nextIndex], notices[index]];
      return { ...current, notices };
    });
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    const payload = {
      ...draft,
      slug: slugify(draft.slug || draft.name),
      initials: draft.initials.toUpperCase(),
      packages: draft.packages.map((item, index) => ({
        ...item,
        id: slugify(item.id || `${draft.slug || draft.name}-${item.label || index + 1}`),
        price: Number(item.price),
        providerCode: item.providerCode || undefined,
        providerSku: item.providerSku || undefined,
        sortOrder: index,
      })),
      notices: draft.notices.map((item, index) => ({ ...item, sortOrder: index })),
    };
    try {
      const staffContentOnly = role === "staff";
      const response = await fetch(staffContentOnly ? "/api/panel/product-content" : "/api/panel/products", {
        method: staffContentOnly ? "PUT" : draft.dbId ? "PATCH" : "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Produk gagal disimpan.");
      setDialogOpen(false);
      setMessage(staffContentOnly ? "Informasi produk berhasil diperbarui." : draft.dbId ? "Produk berhasil diperbarui." : "Produk berhasil ditambahkan.");
      await loadProducts();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Produk gagal disimpan.");
    } finally {
      setSaving(false);
    }
  }

  async function seedProducts() {
    setSeeding(true);
    setError("");
    try {
      const response = await fetch("/api/panel/products/seed", { method: "POST" });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Katalog utama gagal diimpor.");
      setMessage("Katalog utama berhasil dilengkapi tanpa menimpa perubahan yang sudah ada.");
      await loadProducts();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Katalog utama gagal diimpor.");
    } finally {
      setSeeding(false);
    }
  }

  async function removeProduct(id: number) {
    setError("");
    const response = await fetch(`/api/panel/products?id=${id}`, { method: "DELETE" });
    const data = await response.json() as { error?: string };
    if (!response.ok) {
      setError(data.error ?? "Produk gagal dihapus.");
      return;
    }
    setMessage("Produk berhasil dihapus.");
    await loadProducts();
  }

  if (loading) return <div className="flex min-h-48 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" /> Memuat produk…</div>;

  if (!databaseReady) {
    return <div className="rounded-2xl border border-amber-300/20 bg-amber-300/[0.06] p-6 text-center"><Database className="mx-auto size-7 text-amber-300" /><h3 className="mt-4 font-bold">Database belum siap</h3><p className="mx-auto mt-2 max-w-md text-xs leading-6 text-white/42">Buat database D1 dan jalankan file migrasi terlebih dahulu. Setelah itu tekan Muat ulang.</p>{error && <p className="mt-2 text-[10px] text-red-200/70">{error}</p>}<Button onClick={() => void loadProducts()} variant="outline" className="mt-5 rounded-xl border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white"><RefreshCw className="mr-2 size-4" />Muat ulang</Button></div>;
  }

  return (
    <>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><p className="text-xs font-semibold">{items.length} produk tersimpan</p><p className="mt-1 text-[10px] text-white/30">Perubahan aktif langsung dipakai katalog publik.</p></div>
        <div className="flex gap-2">
          {role === "owner" && <Button onClick={() => void seedProducts()} disabled={seeding} variant="outline" className="rounded-xl border-white/10 bg-white/[0.03] text-xs text-white hover:bg-white/[0.08] hover:text-white">{seeding ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <Database className="mr-2 size-4" />}Lengkapi katalog utama</Button>}
          {role === "owner" && <Button onClick={openNew} className="rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006] hover:bg-[#d0ff75]"><Plus className="mr-2 size-4" />Tambah produk</Button>}
        </div>
      </div>
      {message && <div className="mb-4 rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">{message}</div>}
      {error && <div className="mb-4 rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
      <div className="overflow-x-auto rounded-xl border border-white/[0.08]">
        <Table>
          <TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Produk</TableHead><TableHead className="text-[10px] text-white/35">Harga mulai</TableHead><TableHead className="text-[10px] text-white/35">Proses</TableHead><TableHead className="text-[10px] text-white/35">Status</TableHead><TableHead className="text-right text-[10px] text-white/35">Aksi</TableHead></TableRow></TableHeader>
          <TableBody>{items.map((item) => <TableRow key={item.dbId ?? item.slug} className="border-white/[0.07] hover:bg-white/[0.025]"><TableCell><div className="flex items-center gap-3"><span className="block size-9 overflow-hidden rounded-lg"><ProductArtwork product={item} compact /></span><div><strong className="text-xs">{item.name}</strong><p className="mt-1 text-[9px] text-white/28">{item.category} • {item.publisher}</p></div></div></TableCell><TableCell className="text-xs text-[#d8ff8d]">{formatRupiah(Math.min(...item.packages.map((entry) => entry.price)))}</TableCell><TableCell className="text-xs text-white/42">{item.fulfillmentType === "manual" ? "Manual" : `${item.packages.filter((entry) => entry.providerSku).length}/${item.packages.length} SKU`}</TableCell><TableCell><span className={`rounded-full px-2 py-1 text-[9px] font-bold ${item.isActive ? "bg-[#b9ff35]/10 text-[#d8ff8d]" : "bg-white/[0.06] text-white/35"}`}>{item.isActive ? "Aktif" : "Nonaktif"}</span></TableCell><TableCell><div className="flex justify-end gap-1"><Button onClick={() => openEdit(item)} variant="ghost" size="icon-sm" className="text-white/45 hover:bg-white/[0.08] hover:text-white" aria-label={`Edit ${item.name}`}><Edit3 className="size-3.5" /></Button>{role === "owner" && item.dbId && <AlertDialog><AlertDialogTrigger asChild><Button variant="ghost" size="icon-sm" className="text-red-300/45 hover:bg-red-400/[0.08] hover:text-red-200"><Trash2 className="size-3.5" /></Button></AlertDialogTrigger><AlertDialogContent className="border-white/10 bg-[#10141d] text-white"><AlertDialogHeader><AlertDialogTitle>Hapus {item.name}?</AlertDialogTitle><AlertDialogDescription className="text-white/42">Produk dan semua nominalnya akan dihapus dari katalog. Tindakan ini tidak dapat dibatalkan.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel className="border-white/10 bg-white/[0.04] text-white hover:bg-white/[0.08] hover:text-white">Batal</AlertDialogCancel><AlertDialogAction onClick={() => void removeProduct(item.dbId!)} className="bg-red-500 text-white hover:bg-red-400">Hapus</AlertDialogAction></AlertDialogFooter></AlertDialogContent></AlertDialog>}</div></TableCell></TableRow>)}</TableBody>
        </Table>
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto border-white/10 bg-[#10141d] text-white sm:max-w-2xl">
          <form onSubmit={save}>
            <DialogHeader><DialogTitle>{role === "staff" ? `Edit informasi ${draft.name}` : draft.dbId ? "Edit produk" : "Tambah produk"}</DialogTitle><DialogDescription className="text-white/38">{role === "staff" ? "Staff dapat mengubah media, jam layanan, instruksi, dan pop-up tanpa akses ke harga atau provider." : "Data ini akan digunakan oleh katalog dan checkout."}</DialogDescription></DialogHeader>
            <div className="grid gap-4 py-5 sm:grid-cols-2">
              {role === "owner" && <><Field label="Nama produk"><Input required value={draft.name} onChange={(event) => { updateDraft("name", event.target.value); if (!draft.dbId) updateDraft("slug", slugify(event.target.value)); }} className="admin-input" placeholder="Mobile Legends" /></Field>
              <Field label="Slug URL"><Input required value={draft.slug} onChange={(event) => updateDraft("slug", slugify(event.target.value))} className="admin-input" placeholder="mobile-legends" /></Field>
              <Field label="Publisher"><Input value={draft.publisher} onChange={(event) => updateDraft("publisher", event.target.value)} className="admin-input" placeholder="Moonton" /></Field>
              <Field label="Kategori"><select value={draft.category} onChange={(event) => updateDraft("category", event.target.value)} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white">{(categories.length ? categories : [{ slug: "game", name: "Top Up Game" }, { slug: "voucher", name: "Voucher & Gift Card" }]).map((category) => <option key={category.slug} value={category.slug}>{category.name}</option>)}</select></Field></>}
              <div className="sm:col-span-2"><AdminMediaUpload label="Gambar produk" value={draft.imageUrl ?? ""} onChange={(value) => updateDraft("imageUrl", value)} help="Unggah dari HP atau tempel URL HTTPS. Jika kosong, kartu memakai inisial 8-bit dan warna produk." /></div>
              <div className="sm:col-span-2"><AdminMediaUpload label="Banner halaman produk" value={draft.bannerUrl ?? ""} onChange={(value) => updateDraft("bannerUrl", value)} help="Banner landscape tampil di atas halaman pemilihan nominal. Jika kosong, gambar produk digunakan." /></div>
              {role === "owner" && <><Field label="Inisial kartu"><Input required maxLength={3} value={draft.initials} onChange={(event) => updateDraft("initials", event.target.value.toUpperCase())} className="admin-input" placeholder="ML" /></Field>
              <Field label="Urutan"><Input type="number" min={0} value={draft.sortOrder} onChange={(event) => updateDraft("sortOrder", Number(event.target.value))} className="admin-input" /></Field>
              <Field label="Label tujuan"><Input required value={draft.inputLabel} onChange={(event) => updateDraft("inputLabel", event.target.value)} className="admin-input" placeholder="User ID" /></Field>
              <Field label="Contoh tujuan"><Input required value={draft.inputPlaceholder} onChange={(event) => updateDraft("inputPlaceholder", event.target.value)} className="admin-input" placeholder="Masukkan User ID" /></Field>
              <Field label="Jenis proses"><select value={draft.fulfillmentType} onChange={(event) => { const value = event.target.value as "automatic" | "manual"; updateDraft("fulfillmentType", value); updateDraft("instant", value === "automatic"); if (value === "manual" && draft.notices.length === 0) updateDraft("notices", [{ id: null, title: "JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}", body: "Estimasi proses 30 menit sampai 2 jam.\n\nAdmin akan menghubungi melalui WhatsApp setelah pembayaran berhasil.", isActive: true, sortOrder: 0 }]); }} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white"><option value="automatic">Otomatis via API</option><option value="manual">Manual oleh admin</option></select></Field>
              <Field label="Format tujuan provider"><Input required value={draft.targetTemplate} onChange={(event) => updateDraft("targetTemplate", event.target.value)} className="admin-input" placeholder="{{destination}}{{server}}" /></Field></>}
              {draft.fulfillmentType === "manual" && <Field label="Instruksi manual" wide><Input value={draft.manualInstructions ?? ""} onChange={(event) => updateDraft("manualInstructions", event.target.value)} className="admin-input" placeholder="Instruksi aman untuk pelanggan, tanpa meminta password atau OTP" /></Field>}
              {draft.fulfillmentType === "manual" && <><Field label="Jam buka"><Input type="time" required value={draft.manualOpenTime ?? "09:00"} onChange={(event) => updateDraft("manualOpenTime", event.target.value)} className="admin-input" /></Field><Field label="Jam tutup"><Input type="time" required value={draft.manualCloseTime ?? "21:00"} onChange={(event) => updateDraft("manualCloseTime", event.target.value)} className="admin-input" /></Field><Field label="Zona waktu" wide><select value={draft.manualTimezone ?? "Asia/Jakarta"} onChange={(event) => updateDraft("manualTimezone", event.target.value)} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white"><option value="Asia/Jakarta">WIB — Asia/Jakarta</option><option value="Asia/Makassar">WITA — Asia/Makassar</option><option value="Asia/Jayapura">WIT — Asia/Jayapura</option></select></Field></>}
              {role === "owner" && <Field label="Warna kartu" wide><select value={draft.accent} onChange={(event) => updateDraft("accent", event.target.value)} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white"><option value="from-[#5577ff] via-[#314fc0] to-[#16276c]">Biru</option><option value="from-[#ffad32] via-[#ea6825] to-[#7c2714]">Oranye</option><option value="from-[#ff5f65] via-[#c42f50] to-[#5b1530]">Merah</option><option value="from-[#58d68d] via-[#2b8f9a] to-[#174862]">Hijau</option><option value="from-[#f6d878] via-[#7c4fc9] to-[#2b174c]">Ungu</option><option value="from-[#b9ff35] to-[#347a21]">Neon</option></select></Field>}
            </div>
            {role === "owner" && <div className="grid grid-cols-2 gap-3 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4 sm:grid-cols-4">{[["Aktif", "isActive"], ["Populer", "popular"], ["Instan", "instant"], ["Butuh server", "needsServer"]].map(([label, key]) => <label key={key} className="flex items-center justify-between gap-2 text-[11px] text-white/55"><span>{label}</span><Switch checked={Boolean(draft[key as keyof ManagedProduct])} onCheckedChange={(checked) => updateDraft(key as keyof ManagedProduct, checked as never)} /></label>)}</div>}
            <div className="mt-5 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4"><div className="flex items-center justify-between gap-3"><div><h3 className="flex items-center gap-2 text-sm font-bold"><BellRing className="size-4 text-[#b9ff35]" />Pop-up informasi produk</h3><p className="mt-1 text-[10px] text-white/30">Muncul sebelum pelanggan memilih nominal. Tambahkan slide sebanyak kebutuhan dan atur urutannya.</p><p className="mt-1 text-[9px] text-[#cfff72]/55">Token jam: {"{{jam_buka}}"}, {"{{jam_tutup}}"}, dan {"{{zona_waktu}}"}.</p></div><Button type="button" onClick={addNotice} size="sm" variant="outline" className="shrink-0 rounded-lg border-white/10 bg-white/[0.03] text-[10px] text-white hover:bg-white/[0.08] hover:text-white"><Plus className="mr-1 size-3.5" />Tambah slide</Button></div><div className="mt-3 space-y-3">{draft.notices.length ? draft.notices.map((notice, index) => <div key={`${notice.id}-${index}`} className="rounded-xl border border-white/[0.08] bg-[#111620] p-3"><div className="flex items-center gap-2"><span className="grid size-7 shrink-0 place-items-center rounded-lg bg-white/[0.05] text-[9px] font-bold text-white/40">{index + 1}</span><div className="flex shrink-0 flex-col"><button type="button" disabled={index === 0} onClick={() => moveNotice(index, -1)} className="text-white/30 enabled:hover:text-white disabled:opacity-20" aria-label="Geser slide ke atas"><ChevronUp className="size-3.5" /></button><button type="button" disabled={index === draft.notices.length - 1} onClick={() => moveNotice(index, 1)} className="text-white/30 enabled:hover:text-white disabled:opacity-20" aria-label="Geser slide ke bawah"><ChevronDown className="size-3.5" /></button></div><Input required value={notice.title} onChange={(event) => updateNotice(index, "title", event.target.value)} className="admin-input" placeholder="JAM OPERASIONAL 09.00 – 23.00 WIB" /><Switch checked={notice.isActive} onCheckedChange={(checked) => updateNotice(index, "isActive", checked)} /><Button type="button" onClick={() => removeNotice(index)} variant="ghost" size="icon-sm" className="text-red-300/50 hover:bg-red-400/[0.08] hover:text-red-200"><Trash2 className="size-3.5" /></Button></div><Textarea required value={notice.body} onChange={(event) => updateNotice(index, "body", event.target.value)} className="mt-2 min-h-28 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" placeholder={"Estimasi proses 30 menit sampai 2 jam.\n\nAdmin akan menghubungi melalui WhatsApp setelah pembayaran."} /></div>) : <div className="rounded-xl border border-dashed border-white/10 py-6 text-center text-[10px] text-white/28">Tidak ada pop-up untuk produk ini.</div>}</div></div>
            {role === "owner" && <div className="mt-5"><div className="flex items-center justify-between"><div><h3 className="text-sm font-bold">Nominal & harga</h3><p className="mt-1 text-[10px] text-white/30">Atur margin setiap nominal secara terpisah.</p></div><Button type="button" onClick={addPackage} size="sm" variant="outline" className="rounded-lg border-white/10 bg-white/[0.03] text-[10px] text-white hover:bg-white/[0.08] hover:text-white"><PackagePlus className="mr-1.5 size-3.5" />Tambah nominal</Button></div><div className="mt-3 space-y-3">{draft.packages.map((item, index) => <div key={`${item.dbId}-${index}`} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3"><div className="grid gap-2 sm:grid-cols-[1fr_150px_110px_32px]"><Input required value={item.label} onChange={(event) => updatePackage(index, "label", event.target.value)} className="admin-input" placeholder="59 Diamonds" /><Input required type="number" min={1} value={item.price || ""} onChange={(event) => updatePackage(index, "price", Number(event.target.value))} className="admin-input" placeholder="Harga jual" /><Input value={item.note ?? ""} onChange={(event) => updatePackage(index, "note", event.target.value)} className="admin-input" placeholder="Populer" /><Button type="button" disabled={draft.packages.length === 1} onClick={() => removePackage(index)} variant="ghost" size="icon-sm" className="text-red-300/50 hover:bg-red-400/[0.08] hover:text-red-200"><Trash2 className="size-3.5" /></Button></div>{draft.fulfillmentType === "automatic" && <><div className="mt-2 grid gap-2 sm:grid-cols-2"><select value={item.providerCode ?? ""} onChange={(event) => updatePackage(index, "providerCode", event.target.value)} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white"><option value="">Pilih provider</option>{providerOptions.map((provider) => <option key={provider.code} value={provider.code}>{provider.name}</option>)}</select><Input value={item.providerSku ?? ""} onChange={(event) => updatePackage(index, "providerSku", item.providerCode === "voucher-stock" ? event.target.value.toLowerCase().replace(/[^a-z0-9._:-]/g, "-") : event.target.value)} className="admin-input" placeholder="SKU provider" /></div>{item.providerCode === "digiflazz" && <div className="mt-2 grid gap-2 sm:grid-cols-3"><select value={item.marginType ?? "fixed"} onChange={(event) => updatePackage(index, "marginType", event.target.value as "fixed" | "percent")} className="h-10 rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white"><option value="fixed">Margin Rupiah</option><option value="percent">Margin Persen</option></select><Input type="number" min={0} value={item.marginValue ?? 0} onChange={(event) => updatePackage(index, "marginValue", Number(event.target.value))} className="admin-input" placeholder="Margin" /><span className="rounded-xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.05] px-3 py-2 text-[10px] text-[#d8ff8d]">Modal: {item.supplierPrice ? formatRupiah(item.supplierPrice) : "Belum sinkron"}</span></div>}</>}</div>)}</div></div>}
            {error && <p className="mt-4 rounded-xl bg-red-400/[0.07] p-3 text-xs text-red-200">{error}</p>}
            <DialogFooter className="mt-5"><Button type="button" variant="outline" onClick={() => setDialogOpen(false)} className="border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white">Batal</Button><Button disabled={saving} className="bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]">{saving && <LoaderCircle className="mr-2 size-4 animate-spin" />}{role === "staff" ? "Simpan informasi" : "Simpan produk"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}

function Field({ label, wide = false, children }: { label: string; wide?: boolean; children: React.ReactNode }) {
  return <label className={wide ? "sm:col-span-2" : ""}><span className="field-label">{label}</span>{children}</label>;
}

function slugify(value: string) {
  return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

async function requestProducts() {
  const response = await fetch("/api/panel/products", { cache: "no-store" });
  const data = await response.json() as { products?: ManagedProduct[]; databaseReady?: boolean; role?: "owner" | "staff"; error?: string };
  if (!response.ok) throw new Error(data.error ?? "Produk gagal dimuat.");
  return data;
}
