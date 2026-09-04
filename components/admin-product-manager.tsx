"use client";

import { FormEvent, Fragment, useCallback, useEffect, useState } from "react";
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
  description: "",
  initials: "",
  accent: "from-[#b9ff35] to-[#347a21]",
  inputLabel: "User ID",
  inputPlaceholder: "Masukkan User ID",
  inputFields: [{ id: "user-id", label: "User ID", placeholder: "Masukkan User ID", required: true }],
  needsServer: false,
  popular: false,
  instant: true,
  fulfillmentType: "automatic",
  targetTemplate: "{{destination}}{{server}}",
  manualInstructions: "",
  manualOpenTime: "09:00",
  manualCloseTime: "21:00",
  manualTimezone: "Asia/Jakarta",
  packageTabsEnabled: false,
  packageTabs: [],
  isActive: false,
  sortOrder: 0,
  notices: [],
  packages: [],
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
  const [expandedProduct, setExpandedProduct] = useState<string | null>(null);
  const [catalogSaving, setCatalogSaving] = useState<string | null>(null);
  const [syncingPackage, setSyncingPackage] = useState<number | null>(null);

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

  function itemKey(item: ManagedProduct) {
    return String(item.dbId ?? item.slug);
  }

  function updateCatalogProduct(key: string, update: (item: ManagedProduct) => ManagedProduct) {
    setItems((current) => current.map((item) => itemKey(item) === key ? update(item) : item));
  }

  function updateCatalogPackage<K extends keyof DraftPackage>(
    key: string,
    index: number,
    field: K,
    value: DraftPackage[K],
  ) {
    updateCatalogProduct(key, (item) => ({
      ...item,
      packages: item.packages.map((entry, itemIndex) =>
        itemIndex === index ? { ...entry, [field]: value } : entry,
      ),
    }));
  }

  function addCatalogPackage(item: ManagedProduct) {
    const key = itemKey(item);
    setExpandedProduct(key);
    updateCatalogProduct(key, (current) => ({
      ...current,
      packages: [
        ...current.packages,
        {
          dbId: null,
          id: "",
          label: "",
          price: 0,
          note: "",
          group: current.packageTabsEnabled ? current.packageTabs[0] ?? "" : "",
          providerCode: current.category === "voucher" ? "voucher-stock" : undefined,
          providerSku: undefined,
          supplierPrice: null,
          pricingMode: "auto",
          marginType: "fixed",
          marginValue: 0,
          isActive: true,
          sortOrder: current.packages.length,
        },
      ],
    }));
  }

  function addCatalogTab(item: ManagedProduct) {
    const key = itemKey(item);
    updateCatalogProduct(key, (current) => {
      const existing = new Set(current.packageTabs.map((entry) => entry.toLowerCase()));
      let name = "Tab baru";
      let suffix = 2;
      while (existing.has(name.toLowerCase())) name = `Tab baru ${suffix++}`;
      return {
        ...current,
        packageTabsEnabled: true,
        packageTabs: [...current.packageTabs, name],
      };
    });
  }

  function updateCatalogTab(item: ManagedProduct, index: number, value: string) {
    const key = itemKey(item);
    updateCatalogProduct(key, (current) => {
      const previous = current.packageTabs[index];
      return {
        ...current,
        packageTabs: current.packageTabs.map((entry, itemIndex) => itemIndex === index ? value : entry),
        packages: current.packages.map((entry) => entry.group === previous ? { ...entry, group: value } : entry),
      };
    });
  }

  function moveCatalogTab(item: ManagedProduct, index: number, direction: -1 | 1) {
    const key = itemKey(item);
    updateCatalogProduct(key, (current) => {
      const nextIndex = index + direction;
      if (nextIndex < 0 || nextIndex >= current.packageTabs.length) return current;
      const packageTabs = [...current.packageTabs];
      [packageTabs[index], packageTabs[nextIndex]] = [packageTabs[nextIndex], packageTabs[index]];
      return { ...current, packageTabs };
    });
  }

  async function removeCatalogTab(item: ManagedProduct, index: number) {
    const removed = item.packageTabs[index];
    const next: ManagedProduct = {
      ...item,
      packageTabs: item.packageTabs.filter((_, itemIndex) => itemIndex !== index),
      packageTabsEnabled: item.packageTabs.length > 1,
      packages: item.packages.map((entry) => entry.group === removed ? { ...entry, group: "" } : entry),
    };
    setItems((current) => current.map((entry) => itemKey(entry) === itemKey(item) ? next : entry));
    await saveCatalogProduct(next, "Tab pemisah berhasil dihapus.");
  }

  async function removeCatalogPackage(item: ManagedProduct, index: number) {
    const entry = item.packages[index];
    if (entry.dbId && !window.confirm(`Hapus nominal ${entry.label}?`)) return;
    const next = { ...item, packages: item.packages.filter((_, itemIndex) => itemIndex !== index) };
    setItems((current) => current.map((product) => itemKey(product) === itemKey(item) ? next : product));
    if (entry.dbId) await saveCatalogProduct(next, "Nominal berhasil dihapus.");
  }

  function buildProductPayload(product: ManagedProduct) {
    const packageTabs = product.packageTabs.map((item) => item.trim()).filter(Boolean);
    return {
      ...product,
      slug: slugify(product.slug || product.name),
      initials: product.initials.toUpperCase(),
      packageTabs,
      inputFields: (product.inputFields ?? []).map((item, index) => ({
        ...item,
        id: slugify(item.id || item.label || `kolom-${index + 1}`),
        label: item.label.trim(),
        placeholder: item.placeholder?.trim() || "",
        required: item.required !== false,
      })).filter((item) => item.label),
      inputLabel: product.inputFields?.[0]?.label?.trim() || "Data pelanggan",
      inputPlaceholder: product.inputFields?.[0]?.placeholder?.trim() || "Tidak diperlukan",
      needsServer: Boolean(product.inputFields?.[1]),
      targetTemplate: product.inputFields && product.inputFields.length > 1 ? "{{destination}}{{server}}" : "{{destination}}",
      packages: product.packages.map((item, index) => {
        const id = slugify(item.id || `${product.slug || product.name}-${item.label || index + 1}`);
        const providerCode = item.providerCode || (product.category === "voucher" ? "voucher-stock" : undefined);
        const providerSku = item.providerSku || (providerCode === "voucher-stock" ? id : undefined);
        return {
          ...item,
          id,
          price: Number(item.price),
          group: item.group?.trim() || undefined,
          providerCode,
          providerSku,
          sortOrder: index,
        };
      }),
      notices: product.notices.map((item, index) => ({ ...item, sortOrder: index })),
    };
  }

  async function saveCatalogProduct(item: ManagedProduct, successMessage = "Pengaturan nominal berhasil disimpan.") {
    if (!item.dbId) {
      setError("Simpan produk terlebih dahulu sebelum mengatur nominal.");
      return false;
    }
    const key = itemKey(item);
    setCatalogSaving(key);
    setError("");
    try {
      const response = await fetch("/api/panel/products", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(buildProductPayload(item)),
      });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Pengaturan katalog gagal disimpan.");
      setMessage(successMessage);
      await loadProducts();
      return true;
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan katalog gagal disimpan.");
      return false;
    } finally {
      setCatalogSaving(null);
    }
  }

  async function syncCatalogPackage(item: ManagedProduct, index: number) {
    const entry = item.packages[index];
    if (entry.providerCode !== "digiflazz") {
      setError("Sync provider saat ini tersedia untuk nominal DigiFlazz.");
      return;
    }
    if (!entry.dbId) {
      setError("Simpan nominal terlebih dahulu sebelum melakukan sync provider.");
      return;
    }
    setSyncingPackage(entry.dbId);
    setError("");
    try {
      const saved = await saveCatalogProduct(item, "Nominal disimpan. Menyinkronkan DigiFlazz…");
      if (!saved) return;
      const response = await fetch("/api/panel/digiflazz-pricing", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ packageId: entry.dbId }),
      });
      const data = await response.json() as { error?: string; result?: { updated?: number } };
      if (!response.ok) throw new Error(data.error ?? "Sync provider gagal.");
      setMessage(`DigiFlazz berhasil disinkronkan untuk ${entry.label}.`);
      await loadProducts();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Sync provider gagal.");
    } finally {
      setSyncingPackage(null);
    }
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

  function addInputField() {
    setDraft((current) => {
      const inputFields = current.inputFields ?? [];
      let suffix = inputFields.length + 1;
      let id = `kolom-${suffix}`;
      const ids = new Set(inputFields.map((item) => item.id));
      while (ids.has(id)) id = `kolom-${++suffix}`;
      return { ...current, inputFields: [...inputFields, { id, label: "", placeholder: "", required: true }] };
    });
  }

  function updateInputField(index: number, patch: Partial<NonNullable<ManagedProduct["inputFields"]>[number]>) {
    setDraft((current) => ({
      ...current,
      inputFields: (current.inputFields ?? []).map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item),
    }));
  }

  function removeInputField(index: number) {
    setDraft((current) => ({ ...current, inputFields: (current.inputFields ?? []).filter((_, itemIndex) => itemIndex !== index) }));
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
    const payload = buildProductPayload(draft);
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
          <TableBody>{items.map((item) => {
            const key = itemKey(item);
            const expanded = expandedProduct === key;
            return <Fragment key={key}>
              <TableRow className="border-white/[0.07] hover:bg-white/[0.025]">
                <TableCell><div className="flex items-center gap-3"><span className="block size-9 overflow-hidden rounded-lg"><ProductArtwork product={item} compact /></span><div><strong className="text-xs">{item.name}</strong><p className="mt-1 text-[9px] text-white/28">{item.category} • {item.publisher}</p></div></div></TableCell>
                <TableCell className="text-xs text-[#d8ff8d]">{item.packages.length ? formatRupiah(Math.min(...item.packages.map((entry) => entry.price))) : <span className="text-white/30">Belum ada nominal</span>}</TableCell>
                <TableCell className="text-xs text-white/42">{item.fulfillmentType === "manual" ? "Manual" : `${item.packages.filter((entry) => entry.providerSku).length}/${item.packages.length} SKU`}</TableCell>
                <TableCell><span className={`rounded-full px-2 py-1 text-[9px] font-bold ${item.isActive ? "bg-[#b9ff35]/10 text-[#d8ff8d]" : "bg-white/[0.06] text-white/35"}`}>{item.isActive ? "Aktif" : "Nonaktif"}</span></TableCell>
                <TableCell><div className="flex justify-end gap-1">
                  {role === "owner" && <Button type="button" onClick={() => addCatalogPackage(item)} variant="ghost" size="icon-sm" className="text-[#d8ff8d]/70 hover:bg-[#b9ff35]/10 hover:text-[#d8ff8d]" aria-label={`Tambah nominal ${item.name}`}><Plus className="size-3.5" /></Button>}
                  <Button type="button" onClick={() => setExpandedProduct(expanded ? null : key)} variant="ghost" size="icon-sm" className="text-white/45 hover:bg-white/[0.08] hover:text-white" aria-label={expanded ? "Tutup daftar harga" : "Buka daftar harga"}>{expanded ? <ChevronUp className="size-3.5" /> : <ChevronDown className="size-3.5" />}</Button>
                  <Button onClick={() => openEdit(item)} variant="ghost" size="icon-sm" className="text-white/45 hover:bg-white/[0.08] hover:text-white" aria-label={`Edit ${item.name}`}><Edit3 className="size-3.5" /></Button>
                  {role === "owner" && item.dbId && <AlertDialog><AlertDialogTrigger asChild><Button variant="ghost" size="icon-sm" className="text-red-300/45 hover:bg-red-400/[0.08] hover:text-red-200"><Trash2 className="size-3.5" /></Button></AlertDialogTrigger><AlertDialogContent className="border-white/10 bg-[#10141d] text-white"><AlertDialogHeader><AlertDialogTitle>Hapus {item.name}?</AlertDialogTitle><AlertDialogDescription className="text-white/42">Produk dan semua nominalnya akan dihapus dari katalog. Tindakan ini tidak dapat dibatalkan.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel className="border-white/10 bg-white/[0.04] text-white hover:bg-white/[0.08] hover:text-white">Batal</AlertDialogCancel><AlertDialogAction onClick={() => void removeProduct(item.dbId!)} className="bg-red-500 text-white hover:bg-red-400">Hapus</AlertDialogAction></AlertDialogFooter></AlertDialogContent></AlertDialog>}
                </div></TableCell>
              </TableRow>
              {expanded && <TableRow className="border-white/[0.07] bg-black/15 hover:bg-black/15"><TableCell colSpan={5} className="p-0">
                <div className="px-3 py-3 sm:px-4">
                  {role === "owner" && <div className="flex flex-wrap items-center justify-between gap-2 border-b border-white/[0.07] pb-3">
                    <div><strong className="text-[10px] text-white/65">Pengaturan nominal</strong><p className="mt-0.5 text-[8px] text-white/28">Tambah tab pemisah, pindahkan nominal, atur provider, lalu simpan.</p></div>
                    <div className="flex flex-wrap gap-2">
                      <Button type="button" onClick={() => addCatalogTab(item)} variant="outline" size="sm" className="h-8 rounded-lg border-white/10 bg-white/[0.03] px-2.5 text-[9px] text-white"><Plus className="mr-1 size-3" />Tambah Tab Pemisah</Button>
                      <Button type="button" onClick={() => addCatalogPackage(item)} variant="outline" size="sm" className="h-8 rounded-lg border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] px-2.5 text-[9px] text-[#d8ff8d]"><PackagePlus className="mr-1 size-3" />Tambah nominal</Button>
                    </div>
                  </div>}

                  {role === "owner" && item.packageTabs.length > 0 && <div className="border-b border-white/[0.07] py-3">
                    <p className="mb-2 text-[9px] font-bold text-white/40">Tab pemisah</p>
                    <div className="space-y-2">{item.packageTabs.map((tab, tabIndex) => <div key={`${tabIndex}-${tab}`} className="flex items-center gap-2">
                      <div className="flex shrink-0 flex-col"><button type="button" disabled={tabIndex === 0} onClick={() => moveCatalogTab(item, tabIndex, -1)} className="text-white/30 enabled:hover:text-white disabled:opacity-20"><ChevronUp className="size-3.5" /></button><button type="button" disabled={tabIndex === item.packageTabs.length - 1} onClick={() => moveCatalogTab(item, tabIndex, 1)} className="text-white/30 enabled:hover:text-white disabled:opacity-20"><ChevronDown className="size-3.5" /></button></div>
                      <Input value={tab} onChange={(event) => updateCatalogTab(item, tabIndex, event.target.value)} className="admin-input h-9 min-w-0 flex-1" placeholder="Nama tab pemisah" />
                      <Button type="button" disabled={catalogSaving === key} onClick={() => void saveCatalogProduct(item, "Tab pemisah berhasil disimpan.")} variant="ghost" size="sm" className="h-8 px-2 text-[9px] text-[#d8ff8d]">Simpan</Button>
                      <Button type="button" onClick={() => void removeCatalogTab(item, tabIndex)} variant="ghost" size="icon-sm" className="text-red-300/50 hover:text-red-200"><Trash2 className="size-3.5" /></Button>
                    </div>)}</div>
                  </div>}

                  <div className="divide-y divide-white/[0.07]">
                    {item.packages.map((entry, index) => <div key={`${entry.dbId ?? "new"}-${index}`} className="py-3">
                      {role === "owner" ? <>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-[1.25fr_1fr_120px_110px]">
                          <label><span className="field-label">Nominal</span><Input value={entry.label} onChange={(event) => updateCatalogPackage(key, index, "label", event.target.value)} className="admin-input" placeholder="59 Diamonds" /></label>
                          <label><span className="field-label">Tab pemisah</span><select value={entry.group ?? ""} onChange={(event) => updateCatalogPackage(key, index, "group", event.target.value)} className="admin-input"><option value="">Tanpa tab</option>{item.packageTabs.map((tab, tabIndex) => <option key={`${tab}-${tabIndex}`} value={tab}>{tab}</option>)}</select></label>
                          <label><span className="field-label">Harga jual</span><Input type="number" min={1} value={entry.price || ""} onChange={(event) => updateCatalogPackage(key, index, "price", Number(event.target.value))} className="admin-input" /></label>
                          <label><span className="field-label">Status</span><select value={entry.isActive ? "1" : "0"} onChange={(event) => updateCatalogPackage(key, index, "isActive", event.target.value === "1")} className="admin-input"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></label>
                        </div>
                        {item.fulfillmentType === "automatic" && <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-[1fr_1.2fr]">
                          <label><span className="field-label">Provider</span><select value={entry.providerCode ?? ""} onChange={(event) => updateCatalogPackage(key, index, "providerCode", event.target.value || undefined)} className="admin-input"><option value="">Pilih provider</option>{providerOptions.map((provider) => <option key={provider.code} value={provider.code}>{provider.name}</option>)}</select></label>
                          <label><span className="field-label">SKU provider</span><Input value={entry.providerSku ?? ""} onChange={(event) => updateCatalogPackage(key, index, "providerSku", entry.providerCode === "voucher-stock" ? event.target.value.toLowerCase().replace(/[^a-z0-9._:-]/g, "-") : event.target.value)} className="admin-input" placeholder="SKU provider" /></label>
                        </div>}
                        {entry.providerCode === "digiflazz" && <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-[160px_140px_1fr_auto]">
                          <label><span className="field-label">Jenis margin</span><select value={entry.marginType ?? "fixed"} onChange={(event) => updateCatalogPackage(key, index, "marginType", event.target.value as "fixed" | "percent")} className="admin-input"><option value="fixed">Margin Rupiah</option><option value="percent">Margin Persen</option></select></label>
                          <label><span className="field-label">Margin</span><Input type="number" min={0} value={entry.marginValue ?? 0} onChange={(event) => updateCatalogPackage(key, index, "marginValue", Number(event.target.value))} className="admin-input" /></label>
                          <div><span className="field-label">Modal supplier</span><div className="flex h-10 items-center rounded-xl border border-white/10 bg-white/[0.025] px-3 text-[10px] text-[#d8ff8d]">{entry.supplierPrice ? formatRupiah(entry.supplierPrice) : "Belum sinkron"}</div></div>
                          <Button type="button" disabled={!entry.dbId || syncingPackage === entry.dbId || catalogSaving === key} onClick={() => void syncCatalogPackage(item, index)} variant="outline" className="mt-auto h-10 rounded-xl border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] px-3 text-[9px] text-[#d8ff8d]">{syncingPackage === entry.dbId ? <LoaderCircle className="mr-1 size-3 animate-spin" /> : <RefreshCw className="mr-1 size-3" />}Sync provider</Button>
                        </div>}
                        <div className="mt-2 flex justify-end gap-2">
                          <Button type="button" disabled={catalogSaving === key} onClick={() => void saveCatalogProduct(item)} size="sm" className="h-8 rounded-lg bg-[#b9ff35] px-3 text-[9px] font-black text-[#091006]">{catalogSaving === key && <LoaderCircle className="mr-1 size-3 animate-spin" />}Simpan nominal</Button>
                          <Button type="button" onClick={() => void removeCatalogPackage(item, index)} variant="ghost" size="sm" className="h-8 px-2 text-[9px] text-red-300/60 hover:text-red-200"><Trash2 className="mr-1 size-3" />Hapus</Button>
                        </div>
                      </> : <div className="grid grid-cols-[1fr_auto] gap-3 text-[10px]"><div><strong className="text-white/70">{entry.label}</strong><p className="mt-0.5 text-white/30">{entry.group || "Tanpa tab"} • {entry.providerCode || "Manual"}</p></div><strong className="text-[#d8ff8d]">{formatRupiah(entry.price)}</strong></div>}
                    </div>)}
                    {!item.packages.length && <div className="py-6 text-center text-[10px] text-white/30">Belum ada nominal. Tekan + pada produk atau tombol Tambah nominal.</div>}
                  </div>
                </div>
              </TableCell></TableRow>}
            </Fragment>;
          })}</TableBody>
        </Table>
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-h-[92dvh] w-[calc(100vw-1rem)] max-w-[calc(100vw-1rem)] overflow-y-auto overflow-x-hidden border-white/10 bg-[#10141d] p-4 text-white sm:max-w-2xl sm:p-6">
          <form onSubmit={save}>
            <DialogHeader><DialogTitle>{role === "staff" ? `Edit informasi ${draft.name}` : draft.dbId ? "Edit produk" : "Tambah produk"}</DialogTitle><DialogDescription className="text-white/38">{role === "staff" ? "Staff dapat mengubah media, jam layanan, instruksi, dan pop-up tanpa akses ke harga atau provider." : "Data ini akan digunakan oleh katalog dan checkout."}</DialogDescription></DialogHeader>
            <div className="grid gap-3 py-3 sm:grid-cols-2">
              {role === "owner" && <><Field label="Nama produk"><Input required value={draft.name} onChange={(event) => { updateDraft("name", event.target.value); if (!draft.dbId) updateDraft("slug", slugify(event.target.value)); }} className="admin-input" placeholder="Mobile Legends" /></Field>
              <Field label="Slug URL"><Input required value={draft.slug} onChange={(event) => updateDraft("slug", slugify(event.target.value))} className="admin-input" placeholder="mobile-legends" /></Field>
              <Field label="Publisher"><Input value={draft.publisher} onChange={(event) => updateDraft("publisher", event.target.value)} className="admin-input" placeholder="Moonton" /></Field>
              <Field label="Kategori"><select value={draft.category} onChange={(event) => updateDraft("category", event.target.value)} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white">{(categories.length ? categories : [{ slug: "game", name: "Top Up Game" }, { slug: "voucher", name: "Voucher & Gift Card" }]).map((category) => <option key={category.slug} value={category.slug}>{category.name}</option>)}</select></Field></>}
              <div className="sm:col-span-2"><AdminMediaUpload label="Gambar produk (wajib kotak 1:1)" value={draft.imageUrl ?? ""} onChange={(value) => updateDraft("imageUrl", value)} help="Gunakan gambar kotak 1:1, misalnya 1080 × 1080. Checkout dan pencarian menampilkan penuh; beranda otomatis memotong bagian tengah menjadi 2:3." previewClassName="h-24 sm:h-28" /></div>
              <div className="sm:col-span-2"><Field label="Deskripsi produk" wide><Textarea value={draft.description ?? ""} onChange={(event) => updateDraft("description", event.target.value)} className="min-h-28 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 py-2 text-xs text-white" placeholder="Deskripsi khusus produk ini yang tampil di tab Keterangan." /></Field></div>
              <div className="sm:col-span-2"><AdminMediaUpload label="Banner halaman produk" value={draft.bannerUrl ?? ""} onChange={(value) => updateDraft("bannerUrl", value)} help="Banner penuh di atas checkout. Gunakan banner landscape; desktop menampilkan lebar penuh, ponsel otomatis memotong sisi kiri/kanan dengan fokus di tengah. Jika kosong, gambar produk digunakan." previewClassName="h-24 sm:h-28" /></div>
              {role === "owner" && <><Field label="Inisial kartu"><Input required maxLength={3} value={draft.initials} onChange={(event) => updateDraft("initials", event.target.value.toUpperCase())} className="admin-input" placeholder="ML" /></Field>
              <Field label="Urutan"><Input type="number" min={0} value={draft.sortOrder} onChange={(event) => updateDraft("sortOrder", Number(event.target.value))} className="admin-input" /></Field>
              <Field label="Jenis proses" wide><select value={draft.fulfillmentType} onChange={(event) => { const value = event.target.value as "automatic" | "manual"; updateDraft("fulfillmentType", value); updateDraft("instant", value === "automatic"); if (value === "manual" && draft.notices.length === 0) updateDraft("notices", [{ id: null, title: "JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}", body: "Estimasi proses 30 menit sampai 2 jam.\n\nAdmin akan menghubungi melalui WhatsApp setelah pembayaran berhasil.", isActive: true, sortOrder: 0 }]); }} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white"><option value="automatic">Otomatis via provider</option><option value="manual">Manual oleh admin</option></select></Field></>}
              {draft.fulfillmentType === "manual" && <Field label="Instruksi manual" wide><Input value={draft.manualInstructions ?? ""} onChange={(event) => updateDraft("manualInstructions", event.target.value)} className="admin-input" placeholder="Instruksi aman untuk pelanggan, tanpa meminta password atau OTP" /></Field>}
              {draft.fulfillmentType === "manual" && <><Field label="Jam buka"><Input type="time" required value={draft.manualOpenTime ?? "09:00"} onChange={(event) => updateDraft("manualOpenTime", event.target.value)} className="admin-input" /></Field><Field label="Jam tutup"><Input type="time" required value={draft.manualCloseTime ?? "21:00"} onChange={(event) => updateDraft("manualCloseTime", event.target.value)} className="admin-input" /></Field><Field label="Zona waktu" wide><select value={draft.manualTimezone ?? "Asia/Jakarta"} onChange={(event) => updateDraft("manualTimezone", event.target.value)} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white"><option value="Asia/Jakarta">WIB — Asia/Jakarta</option><option value="Asia/Makassar">WITA — Asia/Makassar</option><option value="Asia/Jayapura">WIT — Asia/Jayapura</option></select></Field></>}
              {role === "owner" && <Field label="Warna kartu" wide><select value={draft.accent} onChange={(event) => updateDraft("accent", event.target.value)} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs text-white"><option value="from-[#5577ff] via-[#314fc0] to-[#16276c]">Biru</option><option value="from-[#ffad32] via-[#ea6825] to-[#7c2714]">Oranye</option><option value="from-[#ff5f65] via-[#c42f50] to-[#5b1530]">Merah</option><option value="from-[#58d68d] via-[#2b8f9a] to-[#174862]">Hijau</option><option value="from-[#f6d878] via-[#7c4fc9] to-[#2b174c]">Ungu</option><option value="from-[#b9ff35] to-[#347a21]">Neon</option></select></Field>}
            </div>
            {role === "owner" && <>
              <div className="grid grid-cols-2 gap-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 sm:grid-cols-3">{[["Aktif", "isActive"], ["Populer", "popular"], ["Instan", "instant"]].map(([label, key]) => <label key={key} className="flex items-center justify-between gap-2 text-[11px] text-white/55"><span>{label}</span><Switch checked={Boolean(draft[key as keyof ManagedProduct])} onCheckedChange={(checked) => updateDraft(key as keyof ManagedProduct, checked as never)} /></label>)}</div>
              <div className="mt-4 overflow-hidden rounded-xl border border-white/[0.08]"><div className="flex items-center justify-between gap-3 border-b border-white/[0.08] px-3 py-2.5"><div><h3 className="text-xs font-bold">Data yang diisi pelanggan</h3><p className="mt-0.5 text-[9px] text-white/30">Buat kolom sendiri sesuai kebutuhan produk.</p></div><Button type="button" onClick={addInputField} size="sm" variant="outline" className="h-8 rounded-lg border-white/10 bg-white/[0.03] px-2.5 text-[9px] text-white"><Plus className="mr-1 size-3" />Tambah kolom</Button></div><div className="overflow-x-auto"><table className="w-full min-w-[620px] text-left text-[10px]"><thead className="bg-white/[0.025] text-white/30"><tr><th className="px-3 py-2">Nama kolom</th><th className="px-3 py-2">Contoh isi</th><th className="px-3 py-2 text-center">Wajib</th><th className="px-3 py-2 text-right">Aksi</th></tr></thead><tbody>{(draft.inputFields ?? []).map((field, index) => <tr key={field.id} className="border-t border-white/[0.06]"><td className="p-2"><Input required value={field.label} onChange={(event) => updateInputField(index, { label: event.target.value, id: slugify(event.target.value) || field.id })} className="admin-input" placeholder="User ID / Username / Email" /></td><td className="p-2"><Input value={field.placeholder ?? ""} onChange={(event) => updateInputField(index, { placeholder: event.target.value })} className="admin-input" placeholder="Contoh: 123456789" /></td><td className="p-2 text-center"><Switch checked={field.required !== false} onCheckedChange={(checked) => updateInputField(index, { required: checked })} /></td><td className="p-2 text-right"><Button type="button" onClick={() => removeInputField(index)} variant="ghost" size="icon-sm" className="text-red-300/50 hover:bg-red-400/[0.08] hover:text-red-200"><Trash2 className="size-3.5" /></Button></td></tr>)}{!(draft.inputFields ?? []).length && <tr><td colSpan={4} className="px-3 py-5 text-center text-[10px] text-white/28">Produk ini tidak meminta data tambahan dari pelanggan.</td></tr>}</tbody></table></div></div>
            </>}
            <div className="mt-5 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4"><div className="flex items-center justify-between gap-3"><div><h3 className="flex items-center gap-2 text-sm font-bold"><BellRing className="size-4 text-[#b9ff35]" />Pop-up informasi produk</h3><p className="mt-1 text-[10px] text-white/30">Muncul sebelum pelanggan memilih nominal. Tambahkan slide sebanyak kebutuhan dan atur urutannya.</p><p className="mt-1 text-[9px] text-[#cfff72]/55">Token jam: {"{{jam_buka}}"}, {"{{jam_tutup}}"}, dan {"{{zona_waktu}}"}.</p></div><Button type="button" onClick={addNotice} size="sm" variant="outline" className="shrink-0 rounded-lg border-white/10 bg-white/[0.03] text-[10px] text-white hover:bg-white/[0.08] hover:text-white"><Plus className="mr-1 size-3.5" />Tambah slide</Button></div><div className="mt-3 space-y-3">{draft.notices.length ? draft.notices.map((notice, index) => <div key={`${notice.id}-${index}`} className="rounded-xl border border-white/[0.08] bg-[#111620] p-3"><div className="flex items-center gap-2"><span className="grid size-7 shrink-0 place-items-center rounded-lg bg-white/[0.05] text-[9px] font-bold text-white/40">{index + 1}</span><div className="flex shrink-0 flex-col"><button type="button" disabled={index === 0} onClick={() => moveNotice(index, -1)} className="text-white/30 enabled:hover:text-white disabled:opacity-20" aria-label="Geser slide ke atas"><ChevronUp className="size-3.5" /></button><button type="button" disabled={index === draft.notices.length - 1} onClick={() => moveNotice(index, 1)} className="text-white/30 enabled:hover:text-white disabled:opacity-20" aria-label="Geser slide ke bawah"><ChevronDown className="size-3.5" /></button></div><Input required value={notice.title} onChange={(event) => updateNotice(index, "title", event.target.value)} className="admin-input" placeholder="JAM OPERASIONAL 09.00 – 23.00 WIB" /><Switch checked={notice.isActive} onCheckedChange={(checked) => updateNotice(index, "isActive", checked)} /><Button type="button" onClick={() => removeNotice(index)} variant="ghost" size="icon-sm" className="text-red-300/50 hover:bg-red-400/[0.08] hover:text-red-200"><Trash2 className="size-3.5" /></Button></div><Textarea required value={notice.body} onChange={(event) => updateNotice(index, "body", event.target.value)} className="mt-2 min-h-28 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" placeholder={"Estimasi proses 30 menit sampai 2 jam.\n\nAdmin akan menghubungi melalui WhatsApp setelah pembayaran."} /></div>) : <div className="rounded-xl border border-dashed border-white/10 py-6 text-center text-[10px] text-white/28">Tidak ada pop-up untuk produk ini.</div>}</div></div>
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
