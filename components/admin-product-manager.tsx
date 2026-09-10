"use client";
/* eslint-disable @next/next/no-img-element */

import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import {
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  ChevronLeft,
  ChevronRight,
  Copy,
  ExternalLink,
  GripVertical,
  ImageIcon,
  Info,
  Monitor,
  MoreVertical,
  Pencil,
  Plus,
  RefreshCw,
  Save,
  Search,
  Settings2,
  SlidersHorizontal,
  Smartphone,
  Trash2,
  Upload,
  X,
} from "lucide-react";
import { announceAdminAction } from "@/components/admin-workspace-ui";

type ProductProvider = "Digiflazz" | "Manual";
type EditorTab = "Informasi Produk" | "Nominal & Harga" | "Tabel Pemisah" | "Tampilan Produk" | "Input Customer" | "Fulfillment";

type Product = {
  id: number;
  name: string;
  slug: string;
  description: string;
  category: "Mobile Games" | "PC Games" | "Game Voucher";
  provider: ProductProvider;
  nominalCount: number;
  startPrice: number;
  active: boolean;
  visible: boolean;
  updated: string;
  image: string;
};

type Nominal = {
  id: string;
  name: string;
  sku: string;
  group: string;
  cost: number;
  margin: number;
  sell: number;
  active: boolean;
  imageKind: "diamond" | "weekly" | "double" | "twilight";
  provider: ProductProvider;
};

type NominalSection = {
  id: string;
  name: string;
  description: string;
  position: "Di atas" | "Di bawah";
  active: boolean;
};

const initialProducts: Product[] = [
  { id: 1, name: "Mobile Legends", slug: "mobile-legends", description: "Top up diamond Mobile Legends", category: "Mobile Games", provider: "Digiflazz", nominalCount: 24, startPrice: 1000, active: true, visible: true, updated: "24 Apr 2025 10:24", image: "/products/mobile-legends-card.webp" },
  { id: 2, name: "Free Fire", slug: "free-fire", description: "Top up diamond Free Fire", category: "Mobile Games", provider: "Digiflazz", nominalCount: 18, startPrice: 1000, active: true, visible: true, updated: "24 Apr 2025 09:12", image: "/products/free-fire-card.webp" },
  { id: 3, name: "PUBG Mobile", slug: "pubg-mobile", description: "Top up UC PUBG Mobile", category: "Mobile Games", provider: "Digiflazz", nominalCount: 15, startPrice: 1000, active: true, visible: true, updated: "23 Apr 2025 21:43", image: "/products/pubg-mobile-card.webp" },
  { id: 4, name: "Valorant", slug: "valorant", description: "Top up Valorant Points", category: "PC Games", provider: "Digiflazz", nominalCount: 12, startPrice: 5000, active: true, visible: true, updated: "23 Apr 2025 18:20", image: "/products/valorant-card.webp" },
  { id: 5, name: "Genshin Impact", slug: "genshin-impact", description: "Top up Genesis Crystals", category: "Mobile Games", provider: "Digiflazz", nominalCount: 10, startPrice: 16000, active: true, visible: true, updated: "23 Apr 2025 16:11", image: "/products/genshin-impact-card.webp" },
  { id: 6, name: "Roblox", slug: "roblox", description: "Robux Gift / Login", category: "Game Voucher", provider: "Manual", nominalCount: 8, startPrice: 25000, active: true, visible: true, updated: "22 Apr 2025 14:08", image: "/products/roblox-card.webp" },
];

const initialNominals: Nominal[] = [
  { id: "n1", name: "5 Diamonds", sku: "ML5", group: "Diamonds", cost: 1000, margin: 50, sell: 1639, active: true, imageKind: "diamond", provider: "Digiflazz" },
  { id: "n2", name: "12 Diamonds", sku: "ML12", group: "Diamonds", cost: 2500, margin: 57, sell: 3919, active: true, imageKind: "diamond", provider: "Digiflazz" },
  { id: "n3", name: "Weekly Diamond Pass", sku: "MLWDP", group: "Special Items", cost: 20000, margin: 40, sell: 28082, active: true, imageKind: "weekly", provider: "Digiflazz" },
  { id: "n4", name: "2x Weekly Diamond Pass", sku: "MLWDP2", group: "Special Items", cost: 45000, margin: 43, sell: 64264, active: true, imageKind: "weekly", provider: "Digiflazz" },
  { id: "n5", name: "3x Weekly Diamond Pass", sku: "MLWDP3", group: "Special Items", cost: 68000, margin: 42, sell: 96396, active: true, imageKind: "weekly", provider: "Digiflazz" },
  { id: "n6", name: "100 (50+50) Diamonds", sku: "ML100", group: "First Top Up", cost: 9500, margin: 54, sell: 14659, active: true, imageKind: "double", provider: "Digiflazz" },
  { id: "n7", name: "300 (150+150) Diamonds", sku: "ML300", group: "First Top Up", cost: 28500, margin: 54, sell: 43865, active: true, imageKind: "double", provider: "Digiflazz" },
  { id: "n8", name: "Twilight Pass", sku: "MLTP", group: "Weekly Pass", cost: 95000, margin: 53, sell: 145200, active: true, imageKind: "twilight", provider: "Digiflazz" },
];

const initialSections: NominalSection[] = [
  { id: "s1", name: "Special Items", description: "Item spesial seperti Weekly Pass, Twilight Pass, dll.", position: "Di atas", active: true },
  { id: "s2", name: "Weekly Pass", description: "Semua paket Weekly Pass", position: "Di atas", active: true },
  { id: "s3", name: "First Top Up", description: "Bonus double diamonds untuk top up pertama", position: "Di atas", active: true },
  { id: "s4", name: "Diamonds", description: "Semua nominal diamonds reguler", position: "Di atas", active: true },
];

const digiflazzCatalog = [
  { sku: "ML5", name: "Mobile Legends 5 Diamonds", cost: 1050, status: "Normal" },
  { sku: "ML12", name: "Mobile Legends 12 Diamonds", cost: 2200, status: "Normal" },
  { sku: "ML28", name: "Mobile Legends 28 Diamonds", cost: 4900, status: "Normal" },
  { sku: "ML56", name: "Mobile Legends 56 Diamonds", cost: 9650, status: "Normal" },
  { sku: "ML86", name: "Mobile Legends 86 Diamonds", cost: 14800, status: "Normal" },
  { sku: "ML112", name: "Mobile Legends 112 Diamonds", cost: 18900, status: "Normal" },
];

export function AdminProductManager() {
  const [products, setProducts] = useState(initialProducts);
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("Semua Kategori");
  const [provider, setProvider] = useState("Semua Provider");
  const [status, setStatus] = useState("Semua Status");
  const [sort, setSort] = useState("Urutkan: Terbaru");
  const [editorProduct, setEditorProduct] = useState<Product | null>(null);
  const [manualProductOpen, setManualProductOpen] = useState(false);
  const [notice, setNotice] = useState("");

  const visibleProducts = useMemo(() => {
    const term = query.trim().toLowerCase();
    const filtered = products.filter((product) =>
      (!term || `${product.name} ${product.category} ${product.slug}`.toLowerCase().includes(term)) &&
      (category === "Semua Kategori" || product.category === category) &&
      (provider === "Semua Provider" || product.provider === provider) &&
      (status === "Semua Status" || (status === "Aktif" ? product.active : !product.active)),
    );
    return sort === "Urutkan: Nama A-Z" ? [...filtered].sort((a, b) => a.name.localeCompare(b.name)) : filtered;
  }, [category, products, provider, query, sort, status]);

  function resetFilters() {
    setQuery(""); setCategory("Semua Kategori"); setProvider("Semua Provider"); setStatus("Semua Status"); setSort("Urutkan: Terbaru");
  }

  function addProduct(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const name = String(form.get("name") || "Produk Baru");
    const next: Product = {
      id: products.length + 1,
      name,
      slug: String(form.get("slug") || slugify(name)),
      description: String(form.get("description") || "Produk digital LFAMILIA"),
      category: String(form.get("category") || "Mobile Games") as Product["category"],
      provider: String(form.get("provider") || "Manual") as ProductProvider,
      nominalCount: 0,
      startPrice: 0,
      active: true,
      visible: true,
      updated: "Baru saja",
      image: "",
    };
    setProducts((current) => [...current, next]);
    setManualProductOpen(false);
    setEditorProduct(next);
    setNotice(`${name} ditambahkan secara manual. Silakan atur nominalnya.`);
  }

  if (editorProduct) {
    return <ProductEditor product={editorProduct} onBack={() => setEditorProduct(null)} onNotice={setNotice} />;
  }

  return (
    <div className="admin-products-reference min-w-0 text-[#14213a]">
      <div className="flex items-start justify-between gap-[16px]">
        <div><h1 className="text-[23px] font-black tracking-[-0.04em] text-[#0b1834]">Produk</h1><p className="mt-[3px] text-[10px] text-[#64758c]">Kelola semua produk top up, voucher, dan layanan digital.</p></div>
        <div className="flex items-center gap-[8px]">
          <button type="button" onClick={() => setNotice("Pilih produk lalu klik Edit untuk mengimpor nominal Digiflazz.")} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] border border-[#dce3eb] bg-white px-[13px] text-[9px] font-bold text-[#34465f] hover:bg-[#f8fafc]"><SlidersHorizontal className="size-[13px]" />Import Nominal Digiflazz</button>
          <button type="button" onClick={() => setManualProductOpen(true)} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] bg-[#0875ed] px-[15px] text-[9px] font-bold text-white shadow-[0_5px_14px_rgba(8,117,237,.2)] hover:bg-[#0668d5]"><Plus className="size-[14px]" />Tambah Produk Manual</button>
        </div>
      </div>

      {notice && <button type="button" onClick={() => setNotice("")} className="mt-[10px] flex w-full items-center justify-between rounded-[6px] border border-[#b9dfca] bg-[#edf9f2] px-[12px] py-[8px] text-left text-[9px] font-semibold text-[#168553]"><span>{notice}</span><X className="size-[12px]" /></button>}

      <div className="mt-[15px] grid grid-cols-[1.65fr_.75fr_.78fr_.72fr_.85fr_auto] gap-[8px]">
        <label className="relative"><Search className="absolute left-[10px] top-1/2 size-[13px] -translate-y-1/2 text-[#708198]" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari nama produk, kategori, atau slug..." className="h-[34px] w-full rounded-[5px] border border-[#dce3eb] bg-white pl-[31px] pr-[9px] text-[9px] outline-none placeholder:text-[#8290a2] focus:border-[#2680eb]" /></label>
        <CompactSelect value={category} onChange={setCategory} options={["Semua Kategori", "Mobile Games", "PC Games", "Game Voucher"]} />
        <CompactSelect value={provider} onChange={setProvider} options={["Semua Provider", "Digiflazz", "Manual"]} />
        <CompactSelect value={status} onChange={setStatus} options={["Semua Status", "Aktif", "Nonaktif"]} />
        <CompactSelect value={sort} onChange={setSort} options={["Urutkan: Terbaru", "Urutkan: Nama A-Z"]} />
        <button type="button" onClick={resetFilters} className="inline-flex h-[34px] items-center gap-[6px] rounded-[5px] border border-[#dce3eb] bg-white px-[14px] text-[9px] font-semibold text-[#40516a]"><RefreshCw className="size-[12px]" />Reset</button>
      </div>

      <section className="mt-[10px] overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]">
        <ProductTable products={visibleProducts} onEdit={setEditorProduct} onToggle={(id) => setProducts((current) => current.map((product) => product.id === id ? { ...product, visible: !product.visible } : product))} />
        <div className="flex h-[48px] items-center justify-between border-t border-[#e4e9ef] px-[12px] text-[8px] text-[#586980]"><span>Menampilkan 1–{visibleProducts.length} dari 56 produk</span><div className="flex items-center gap-[5px]"><PageButton><ChevronLeft className="size-[11px]" /></PageButton>{[1, 2, 3, 4, 5].map((page) => <PageButton key={page} active={page === 1}>{page}</PageButton>)}<span className="px-[3px]">...</span><PageButton>10</PageButton><PageButton><ChevronRight className="size-[11px]" /></PageButton></div><CompactSelect value="10 per halaman" onChange={() => {}} options={["10 per halaman", "25 per halaman", "50 per halaman"]} /></div>
      </section>

      {manualProductOpen && <ManualProductModal onClose={() => setManualProductOpen(false)} onSubmit={addProduct} />}
    </div>
  );
}

function ProductTable({ products, onEdit, onToggle }: { products: Product[]; onEdit(product: Product): void; onToggle(id: number): void }) {
  return (
    <div className="overflow-x-auto"><table className="w-full min-w-[960px] table-fixed text-left">
      <thead className="bg-[#f3f6fa] text-[7px] font-bold text-[#52637b]"><tr><th className="w-[35px] px-[12px] py-[10px]"><Box /></th><th className="w-[28px] py-[10px]">#</th><th className="w-[62px] py-[10px]">Gambar</th><th className="w-[170px] py-[10px]">Nama Produk</th><th className="w-[108px] py-[10px]">Kategori</th><th className="w-[86px] py-[10px]">Provider</th><th className="w-[90px] py-[10px]">Total Nominal</th><th className="w-[88px] py-[10px]">Harga Mulai</th><th className="w-[73px] py-[10px]">Status</th><th className="w-[78px] py-[10px]">Ditampilkan</th><th className="w-[112px] py-[10px]">Terakhir Update</th><th className="w-[112px] py-[10px]">Aksi</th></tr></thead>
      <tbody>{products.map((product, index) => <tr key={product.id} className="border-t border-[#e4e9ef] text-[7.5px] text-[#34465e] hover:bg-[#fafbfd]"><td className="px-[12px] py-[7px]"><Box /></td><td>{index + 1}</td><td className="py-[5px]"><ProductImage product={product} /></td><td className="pr-[8px]"><strong className="block truncate text-[8px] text-[#21344e]">{product.name}</strong><span className="block truncate text-[6.5px] text-[#718198]">{product.description}</span></td><td><CategoryBadge category={product.category} /></td><td>{product.provider}</td><td>{product.nominalCount}</td><td>{formatRupiah(product.startPrice)}</td><td><span className="rounded-[4px] bg-[#dff8e9] px-[7px] py-[4px] font-bold text-[#15965b]">Aktif</span></td><td><Switch enabled={product.visible} onToggle={() => onToggle(product.id)} /></td><td>{product.updated}</td><td><div className="flex items-center gap-[7px]"><button type="button" onClick={() => onEdit(product)} className="inline-flex h-[29px] items-center gap-[5px] rounded-[4px] border border-[#dbe2eb] bg-white px-[12px] font-bold text-[#40516a] hover:bg-[#f5f8fb]"><Pencil className="size-[10px]" />Edit</button><button type="button" aria-label={`Menu ${product.name}`}><MoreVertical className="size-[13px]" /></button></div></td></tr>)}</tbody>
    </table></div>
  );
}

function ProductEditor({ product, onBack, onNotice }: { product: Product; onBack(): void; onNotice(message: string): void }) {
  const [tab, setTab] = useState<EditorTab>("Nominal & Harga");
  const [nominals, setNominals] = useState(initialNominals);
  const [sections, setSections] = useState(initialSections);
  const [importOpen, setImportOpen] = useState(false);
  const [manualOpen, setManualOpen] = useState(false);
  const [sectionOpen, setSectionOpen] = useState(false);
  const [previewMode, setPreviewMode] = useState<"Mobile" | "Desktop">("Mobile");
  const [dragging, setDragging] = useState<{ kind: "nominal" | "section"; id: string } | null>(null);
  const [message, setMessage] = useState("");
  const [monitorRefreshing, setMonitorRefreshing] = useState(false);
  const [error, setError] = useState("");
  const [checkoutType, setCheckoutType] = useState<"id" | "id-server">("id-server");
  const [labelId, setLabelId] = useState("User ID");
  const [labelServer, setLabelServer] = useState("Server ID");
  const [inputLoading, setInputLoading] = useState(true);
  const [inputSaving, setInputSaving] = useState(false);
  const inputFields = checkoutType === "id-server"
    ? [{ id: "destination", label: labelId }, { id: "server", label: labelServer }]
    : [{ id: "destination", label: labelId }];
  const targetTemplate = inputFields.map((item) => `{{${item.id}}}`).join("");

  useEffect(() => {
    const controller = new AbortController();
    setInputLoading(true);
    fetch(`/api/panel/product-input?slug=${encodeURIComponent(product.slug)}`, { cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        const payload = await response.json().catch(() => ({})) as { error?: string; input?: { checkoutType: "id" | "id-server"; labelId: string; labelServer: string } };
        if (!response.ok || !payload.input) throw new Error(payload.error || "Pengaturan input pelanggan gagal dimuat.");
        setCheckoutType(payload.input.checkoutType);
        setLabelId(payload.input.labelId);
        setLabelServer(payload.input.labelServer);
      })
      .catch((reason) => {
        if (reason instanceof DOMException && reason.name === "AbortError") return;
        setError(reason instanceof Error ? reason.message : "Pengaturan input pelanggan gagal dimuat.");
      })
      .finally(() => setInputLoading(false));
    return () => controller.abort();
  }, [product.slug]);

  async function saveInputSettings() {
    setError(""); setMessage("");
    if (!labelId.trim() || (checkoutType === "id-server" && !labelServer.trim())) {
      setError("Label ID dan Label Server tidak boleh kosong.");
      return;
    }
    setInputSaving(true);
    try {
      const response = await fetch("/api/panel/product-input", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ slug: product.slug, checkoutType, labelId, labelServer }),
      });
      const payload = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(payload.error || "Pengaturan input pelanggan gagal disimpan.");
      setMessage("Checkout Type dan label input berhasil disimpan ke backend.");
      onNotice(`${product.name} diperbarui.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan input pelanggan gagal disimpan.");
    } finally { setInputSaving(false); }
  }

  function refreshSellerMonitor() {
    setMonitorRefreshing(true);
    setError("");
    setMessage("");
    window.setTimeout(() => { setMonitorRefreshing(false); setMessage("Harga tampilan berhasil disinkronkan."); }, 350);
  }

  function moveNominal(id: string, direction: -1 | 1) { setNominals((current) => moveItem(current, id, direction)); }
  function moveSection(id: string, direction: -1 | 1) { setSections((current) => moveItem(current, id, direction)); }
  function dropNominal(targetId: string) { if (dragging?.kind === "nominal") setNominals((current) => moveBefore(current, dragging.id, targetId)); setDragging(null); }
  function dropSection(targetId: string) { if (dragging?.kind === "section") setSections((current) => moveBefore(current, dragging.id, targetId)); setDragging(null); }

  function addManualNominal(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget); const cost = Number(form.get("cost") || 0); const margin = Number(form.get("margin") || 0);
    setNominals((current) => [...current, { id: crypto.randomUUID(), name: String(form.get("name")), sku: String(form.get("sku") || "MANUAL"), group: String(form.get("group") || sections[0]?.name || "Lainnya"), cost, margin, sell: Math.ceil(cost + cost * margin / 100), active: true, imageKind: "diamond", provider: "Manual" }]);
    setManualOpen(false); setMessage("Nominal manual berhasil ditambahkan.");
  }

  function addSection(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    setSections((current) => [...current, { id: crypto.randomUUID(), name: String(form.get("name")), description: String(form.get("description") || ""), position: "Di atas", active: true }]);
    setSectionOpen(false); setMessage("Tabel pemisah berhasil ditambahkan.");
  }

  return (
    <div className="admin-product-editor min-w-0 text-[#14213a]">
      <div className="mb-[8px] flex items-center gap-[6px] text-[8px] text-[#65758c]"><button type="button" onClick={onBack}>Produk</button><span>›</span><button type="button" onClick={onBack}>Daftar Produk</button><span>›</span><strong className="text-[#2f4159]">Edit Produk</strong></div>
      <header className="flex items-start justify-between gap-[18px]">
        <div className="flex items-center gap-[13px]"><ProductImage product={product} large /><div><div className="flex items-center gap-[8px]"><h1 className="text-[20px] font-black tracking-[-0.035em] text-[#0e1b35]">Edit Produk - {product.name}</h1><CategoryBadge category={product.category} /></div><p className="mt-[4px] text-[10px] text-[#607189]">Kelola nominal, tabel pemisah, gambar, harga dan urutan tampilan produk ini.</p></div></div>
        <div className="flex gap-[9px]"><button type="button" className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] border border-[#dce3eb] bg-white px-[15px] text-[9px] font-bold text-[#2f425b]"><ExternalLink className="size-[12px] text-[#0875ed]" />Lihat di Toko</button><button type="button" onClick={onBack} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] border border-[#dce3eb] bg-white px-[16px] text-[9px] font-bold text-[#2f425b]"><ArrowLeft className="size-[13px]" />Kembali</button></div>
      </header>

      <nav className="mt-[14px] flex border-b border-[#dce3eb]">{(["Informasi Produk", "Nominal & Harga", "Tabel Pemisah", "Tampilan Produk", "Input Customer", "Fulfillment"] as EditorTab[]).map((item) => <button type="button" key={item} onClick={() => setTab(item)} className={`h-[38px] border-b-2 px-[16px] text-[9px] font-semibold ${tab === item ? "border-[#0875ed] text-[#0875ed]" : "border-transparent text-[#4f6078]"}`}>{item}</button>)}</nav>

      {message && <button type="button" onClick={() => setMessage("")} className="mt-[10px] flex w-full items-center justify-between rounded-[5px] border border-[#bce3ce] bg-[#eef9f3] px-[11px] py-[7px] text-[8px] font-semibold text-[#158755]"><span>{message}</span><X className="size-[11px]" /></button>}
      {error && <button type="button" onClick={() => setError("")} className="mt-[10px] w-full rounded-[5px] border border-red-200 bg-red-50 px-[11px] py-[7px] text-left text-[8px] text-red-700">{error}</button>}

      {tab === "Nominal & Harga" || tab === "Tabel Pemisah" ? (
        <div className="mt-[12px] grid grid-cols-[minmax(0,1fr)_300px] gap-[12px]">
          <main className="min-w-0 space-y-[12px]">
            <section className={`overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white ${tab === "Tabel Pemisah" ? "opacity-60" : ""}`}>
              <div className="flex items-center justify-between px-[14px] py-[12px]"><div><h2 className="text-[13px] font-extrabold">Daftar Nominal</h2><p className="mt-[2px] text-[8px] text-[#6b7c92]">Kelola semua nominal, set gambar, harga dan tentukan posisi di tabel pemisah.</p></div></div>
              <div className="flex flex-wrap gap-[7px] border-t border-[#eef1f5] px-[14px] py-[9px]"><ActionButton onClick={() => setImportOpen(true)}><Plus className="size-[12px]" />Tambah dari Digiflazz</ActionButton><ActionButton onClick={() => setManualOpen(true)}><Plus className="size-[12px]" />Tambah Manual</ActionButton><ActionButton><Upload className="size-[12px]" />Upload Gambar Nominal</ActionButton><ActionButton><Settings2 className="size-[12px]" />Atur Urutan</ActionButton><ActionButton onClick={refreshSellerMonitor}><RefreshCw className={`size-[12px] ${monitorRefreshing ? "animate-spin" : ""}`} />{monitorRefreshing ? "Menyinkron..." : "Sync Harga"}</ActionButton><ActionButton><SlidersHorizontal className="size-[12px]" />Atur Margin Massal</ActionButton><button type="button" onClick={() => setMessage("Perubahan tampilan disimpan sementara di frontend.")} className="ml-auto inline-flex h-[31px] items-center gap-[6px] rounded-[4px] bg-[#0875ed] px-[13px] text-[8px] font-bold text-white"><Save className="size-[12px]" />Simpan Perubahan</button></div>
              <NominalTable nominals={nominals} sections={sections} onChange={setNominals} onMove={moveNominal} onDragStart={(id) => setDragging({ kind: "nominal", id })} onDrop={dropNominal} />
            </section>

            <section className="overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white">
              <div className="flex items-center justify-between px-[14px] py-[11px]"><div><h2 className="text-[13px] font-extrabold">Tabel Pemisah Nominal</h2><p className="mt-[2px] text-[8px] text-[#6b7c92]">Buat dan kelola kategori/section untuk mengelompokkan nominal produk.</p></div><button type="button" onClick={() => setSectionOpen(true)} className="inline-flex h-[32px] items-center gap-[6px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white"><Plus className="size-[13px]" />Tambah Tabel Pemisah</button></div>
              <SectionTable sections={sections} onChange={setSections} onMove={moveSection} onDragStart={(id) => setDragging({ kind: "section", id })} onDrop={dropSection} />
              <div className="m-[13px] flex items-start gap-[8px] rounded-[5px] border border-[#bcdcfb] bg-[#edf7ff] px-[12px] py-[9px] text-[7.5px] leading-[1.5] text-[#45627f]"><Info className="mt-[1px] size-[14px] shrink-0 fill-[#1678e7] text-white" /><span><strong>Tips:</strong> Atur urutan tabel pemisah untuk menentukan urutan section yang tampil di halaman produk. Setiap nominal harus dipilih grup/tabelnya agar muncul di section yang sesuai.</span></div>
            </section>
          </main>
          <StorePreview product={product} nominals={nominals} sections={sections} mode={previewMode} onMode={setPreviewMode} />
        </div>
      ) : (
        <EditorTabPanel tab={tab} product={product} targetTemplate={targetTemplate} checkoutType={checkoutType} labelId={labelId} labelServer={labelServer} onCheckoutType={setCheckoutType} onLabelId={setLabelId} onLabelServer={setLabelServer} inputLoading={inputLoading} saving={inputSaving} onSave={tab === "Input Customer" ? saveInputSettings : () => announceAdminAction(`Penyimpanan ${tab} akan memakai endpoint khusus berikutnya.`)} />
      )}

      {importOpen && <ImportNominalModal existing={nominals} onClose={() => setImportOpen(false)} onImport={(added) => { setNominals((current) => [...current, ...added]); setImportOpen(false); setMessage(`${added.length} nominal Digiflazz berhasil ditambahkan.`); }} />}
      {manualOpen && <SimpleModal title="Tambah Nominal Manual" description="Isi nominal sendiri tanpa mengambil data Digiflazz." onClose={() => setManualOpen(false)}><form onSubmit={addManualNominal} className="grid grid-cols-2 gap-[10px]"><Field label="Nama nominal" name="name" placeholder="Contoh: 50 Diamonds" required /><Field label="SKU internal" name="sku" placeholder="ML-MANUAL-50" /><Field label="Harga modal" name="cost" placeholder="10000" type="number" required /><Field label="Margin (%)" name="margin" placeholder="10" type="number" required /><label className="col-span-2 text-[8px] font-bold text-[#3d4f68]">Grup / Tabel<select name="group" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]">{sections.map((section) => <option key={section.id}>{section.name}</option>)}</select></label><ModalActions onCancel={() => setManualOpen(false)} submit="Tambah Nominal" /></form></SimpleModal>}
      {sectionOpen && <SimpleModal title="Tambah Tabel Pemisah" description="Buat section baru untuk mengelompokkan nominal." onClose={() => setSectionOpen(false)}><form onSubmit={addSection} className="grid gap-[10px]"><Field label="Nama tabel / section" name="name" placeholder="Contoh: Promo Spesial" required /><Field label="Deskripsi (opsional)" name="description" placeholder="Keterangan section" /><ModalActions onCancel={() => setSectionOpen(false)} submit="Tambah Tabel" /></form></SimpleModal>}
    </div>
  );
}

function NominalTable({ nominals, sections, onChange, onMove, onDragStart, onDrop }: { nominals: Nominal[]; sections: NominalSection[]; onChange(value: Nominal[]): void; onMove(id: string, direction: -1 | 1): void; onDragStart(id: string): void; onDrop(id: string): void }) {
  return <div className="overflow-x-auto"><table className="w-full min-w-[820px] table-fixed text-left"><thead className="bg-[#f2f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[24px]"></th><th className="w-[25px] py-[8px]">#</th><th className="w-[120px]">Nama Nominal</th><th className="w-[52px]">Gambar</th><th className="w-[72px]">SKU Digiflazz</th><th className="w-[98px]">Grup / Tabel</th><th className="w-[45px]">Urutan</th><th className="w-[65px]">Modal</th><th className="w-[52px]">Margin</th><th className="w-[70px]">Harga Jual</th><th className="w-[53px]">Status</th><th className="w-[95px]">Aksi</th></tr></thead><tbody>{nominals.map((nominal, index) => <tr key={nominal.id} draggable onDragStart={() => onDragStart(nominal.id)} onDragOver={(event) => event.preventDefault()} onDrop={() => onDrop(nominal.id)} className="border-t border-[#e5eaf0] text-[6.8px] text-[#34465e] hover:bg-[#fafbfd]"><td><GripVertical className="mx-auto size-[12px] cursor-grab text-[#7b8ba0]" /></td><td className="py-[6px]">{index + 1}</td><td className="truncate pr-[5px] font-semibold">{nominal.name}</td><td><NominalArtwork kind={nominal.imageKind} /></td><td>{nominal.provider === "Digiflazz" ? nominal.sku : "Manual"}</td><td><select value={nominal.group} onChange={(event) => onChange(nominals.map((item) => item.id === nominal.id ? { ...item, group: event.target.value } : item))} className="h-[27px] w-[92px] rounded-[4px] border border-[#dce3eb] bg-white px-[5px] text-[6.5px]">{sections.map((section) => <option key={section.id}>{section.name}</option>)}</select></td><td><input value={index + 1} readOnly className="h-[27px] w-[34px] rounded-[4px] border border-[#dce3eb] text-center" /></td><td>{formatRupiah(nominal.cost)}</td><td><span className="inline-flex h-[27px] items-center rounded-[4px] border border-[#dce3eb] bg-white px-[6px]">{nominal.margin} %</span></td><td className="font-semibold">{formatRupiah(nominal.sell)}</td><td><Switch enabled={nominal.active} onToggle={() => onChange(nominals.map((item) => item.id === nominal.id ? { ...item, active: !item.active } : item))} /></td><td><div className="flex gap-[3px]"><IconButton label="Naik" onClick={() => onMove(nominal.id, -1)}><ArrowUp /></IconButton><IconButton label="Turun" onClick={() => onMove(nominal.id, 1)}><ArrowDown /></IconButton><IconButton label="Salin"><Copy /></IconButton><IconButton label="Hapus" danger onClick={() => onChange(nominals.filter((item) => item.id !== nominal.id))}><Trash2 /></IconButton></div></td></tr>)}</tbody></table></div>;
}

function SectionTable({ sections, onChange, onMove, onDragStart, onDrop }: { sections: NominalSection[]; onChange(value: NominalSection[]): void; onMove(id: string, direction: -1 | 1): void; onDragStart(id: string): void; onDrop(id: string): void }) {
  return <div className="overflow-x-auto border-t border-[#e7ebf0]"><table className="w-full min-w-[720px] table-fixed text-left"><thead className="bg-[#f2f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[27px]"></th><th className="w-[25px] py-[8px]">#</th><th className="w-[130px]">Nama Tabel / Section</th><th>Deskripsi (Opsional)</th><th className="w-[105px]">Posisi di Halaman</th><th className="w-[50px]">Urutan</th><th className="w-[60px]">Status</th><th className="w-[90px]">Aksi</th></tr></thead><tbody>{sections.map((section, index) => <tr key={section.id} draggable onDragStart={() => onDragStart(section.id)} onDragOver={(event) => event.preventDefault()} onDrop={() => onDrop(section.id)} className="border-t border-[#e6eaf0] text-[7px] text-[#35475f]"><td><GripVertical className="mx-auto size-[12px] cursor-grab text-[#75869c]" /></td><td className="py-[7px]">{index + 1}</td><td className="font-semibold">{section.name}</td><td className="truncate pr-[8px]">{section.description}</td><td><select value={section.position} onChange={(event) => onChange(sections.map((item) => item.id === section.id ? { ...item, position: event.target.value as NominalSection["position"] } : item))} className="h-[27px] w-[90px] rounded-[4px] border border-[#dce3eb] bg-white px-[6px] text-[6.5px]"><option>Di atas</option><option>Di bawah</option></select></td><td><span className="grid size-[27px] place-items-center rounded-[4px] border border-[#dce3eb] bg-white">{index + 1}</span></td><td><span className="rounded-[4px] bg-[#dff8e9] px-[7px] py-[4px] font-bold text-[#15965b]">Aktif</span></td><td><div className="flex gap-[3px]"><IconButton label="Naik" onClick={() => onMove(section.id, -1)}><ArrowUp /></IconButton><IconButton label="Turun" onClick={() => onMove(section.id, 1)}><ArrowDown /></IconButton><IconButton label="Hapus" danger onClick={() => onChange(sections.filter((item) => item.id !== section.id))}><Trash2 /></IconButton></div></td></tr>)}</tbody></table></div>;
}

function StorePreview({ product, nominals, sections, mode, onMode }: { product: Product; nominals: Nominal[]; sections: NominalSection[]; mode: "Mobile" | "Desktop"; onMode(value: "Mobile" | "Desktop"): void }) {
  return <aside className="sticky top-[70px] self-start rounded-[7px] border border-[#dfe6ef] bg-white p-[13px]"><div className="flex items-start justify-between"><div><h2 className="text-[12px] font-extrabold">Preview Tampilan di Toko</h2><p className="mt-[3px] text-[7.5px] text-[#6c7d92]">Berikut adalah preview tampilan produk di sisi pelanggan.</p></div><div className="flex overflow-hidden rounded-[4px] border border-[#dce3eb]">{(["Mobile", "Desktop"] as const).map((item) => <button type="button" key={item} onClick={() => onMode(item)} className={`inline-flex h-[27px] items-center gap-[4px] px-[9px] text-[7px] font-semibold ${mode === item ? "bg-[#0875ed] text-white" : "bg-white text-[#4e6078]"}`}>{item === "Mobile" ? <Smartphone className="size-[10px]" /> : <Monitor className="size-[10px]" />}{item}</button>)}</div></div><div className={`mx-auto mt-[12px] overflow-hidden border-[6px] border-[#101820] bg-[#f4f7fa] shadow-[0_10px_25px_rgba(15,31,55,.2)] ${mode === "Mobile" ? "h-[500px] w-[250px] rounded-[32px]" : "h-[390px] w-full rounded-[12px]"}`}><div className="flex h-[25px] items-center justify-between bg-[#101820] px-[18px] text-[7px] font-bold text-white"><span>15.30</span><span>● ◔ ▰</span></div><div className="flex items-center gap-[7px] border-b bg-white p-[8px]"><ProductImage product={product} /><div><strong className="block text-[8px]">{product.name}</strong><span className="text-[6px] text-[#66778d]">Top up Diamonds, Weekly Pass, dan lainnya</span></div></div><div className="h-[405px] overflow-hidden p-[8px]">{sections.map((section) => { const entries = nominals.filter((item) => item.group === section.name && item.active).slice(0, 2); if (!entries.length) return null; return <div key={section.id} className="mb-[8px]"><h3 className="mb-[5px] text-[9px] font-extrabold">{section.name}{section.name.includes("Special") || section.name.includes("First") ? " ✨" : section.name === "Diamonds" ? " 💎" : ""}</h3><div className="grid grid-cols-2 gap-[6px]">{entries.map((nominal) => <div key={nominal.id} className="min-h-[76px] rounded-[5px] border border-[#e1e7ee] bg-white p-[6px] shadow-sm"><strong className="block truncate text-[6.5px]">{nominal.name}</strong><NominalArtwork kind={nominal.imageKind} large /><span className="block text-[8px] font-black text-[#e93643]">{formatRupiah(nominal.sell)}</span></div>)}</div></div>; })}</div><div className="absolute"></div></div></aside>;
}

function EditorTabPanel({ tab, product, targetTemplate, checkoutType, labelId, labelServer, onCheckoutType, onLabelId, onLabelServer, inputLoading, saving, onSave }: { tab: EditorTab; product: Product; targetTemplate: string; checkoutType: "id" | "id-server"; labelId: string; labelServer: string; onCheckoutType(value: "id" | "id-server"): void; onLabelId(value: string): void; onLabelServer(value: string): void; inputLoading: boolean; saving: boolean; onSave(): void }) {
  return <section className="mt-[12px] rounded-[7px] border border-[#dfe6ef] bg-white p-[16px]"><div className="flex items-center justify-between border-b border-[#e8ecf1] pb-[11px]"><div><h2 className="text-[13px] font-extrabold">{tab}</h2><p className="mt-[2px] text-[8px] text-[#6c7d92]">Pengaturan {tab.toLowerCase()} untuk {product.name}.</p></div><button type="button" disabled={saving || (tab === "Input Customer" && inputLoading)} onClick={onSave} className="inline-flex h-[32px] items-center gap-[6px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white disabled:opacity-50"><Save className="size-[12px]" />{saving ? "Menyimpan..." : "Simpan Perubahan"}</button></div>{tab === "Informasi Produk" && <div className="mt-[14px] grid grid-cols-2 gap-[12px]"><Field label="Nama produk" name="name" placeholder={product.name} /><Field label="Slug" name="slug" placeholder={product.slug} /><Field label="Gambar produk (opsional, rasio 1:1)" name="image" placeholder="Boleh dikosongkan dan ditambahkan nanti" /><Field label="Banner halaman produk (opsional)" name="banner" placeholder="Boleh dikosongkan dan ditambahkan nanti" /></div>}{tab === "Input Customer" && <div className="mt-[14px] grid max-w-[900px] grid-cols-[minmax(0,1fr)_300px] gap-[14px]"><div className="grid grid-cols-2 gap-[12px]"><label className="col-span-2 text-[8px] font-bold text-[#3d4f68]">Checkout Type<span className="mt-[3px] block font-normal text-[#718197]">Pilih data akun yang harus diisi pelanggan.</span><select disabled={inputLoading} value={checkoutType} onChange={(event) => onCheckoutType(event.target.value as "id" | "id-server")} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] bg-white px-[10px] text-[9px]"><option value="id">ID</option><option value="id-server">ID + Server</option></select></label><label className="text-[8px] font-bold text-[#3d4f68]">Label ID<span className="mt-[3px] block font-normal text-[#718197]">Nama field yang tampil di checkout customer.</span><input disabled={inputLoading} value={labelId} onChange={(event) => onLabelId(event.target.value)} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] px-[10px] text-[9px]" placeholder="Contoh: User ID" /></label>{checkoutType === "id-server" && <label className="text-[8px] font-bold text-[#3d4f68]">Label Server<span className="mt-[3px] block font-normal text-[#718197]">Nama field server/zone di checkout customer.</span><input disabled={inputLoading} value={labelServer} onChange={(event) => onLabelServer(event.target.value)} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] px-[10px] text-[9px]" placeholder="Contoh: Zone ID" /></label>}</div><aside className="rounded-[7px] border border-[#dce6f2] bg-[#f8fbff] p-[13px]"><p className="text-[9px] font-extrabold text-[#263b58]">Preview Input Checkout</p>{inputLoading ? <p className="mt-[10px] text-[8px] text-[#718197]">Memuat pengaturan dari backend...</p> : <><label className="mt-[10px] block text-[8px] font-bold text-[#4c6078]">{labelId || "ID"}<input disabled placeholder={`Masukkan ${labelId || "ID"}`} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]" /></label>{checkoutType === "id-server" && <label className="mt-[9px] block text-[8px] font-bold text-[#4c6078]">{labelServer || "Server"}<input disabled placeholder={`Masukkan ${labelServer || "Server"}`} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]" /></label>}</>}<p className="mt-[12px] text-[8px] font-bold text-[#2f4968]">Format customer_no</p><code className="mt-[5px] block rounded-[4px] bg-white px-[9px] py-[8px] text-[8px] text-[#0875ed]">{targetTemplate}</code><p className="mt-[6px] text-[7.5px] leading-4 text-[#718197]">Backend membentuk format tujuan ini dan mengunci aturan verifikasi nickname. Staff hanya mengatur jenis dan label input pelanggan.</p></aside></div>}{tab !== "Informasi Produk" && tab !== "Input Customer" && <div className="mt-[14px] grid grid-cols-3 gap-[10px]">{["Aktif", "Ditampilkan di katalog", "Gunakan pengaturan default"].map((label) => <label key={label} className="flex items-center justify-between rounded-[6px] border border-[#e1e7ee] px-[11px] py-[10px] text-[8px] font-semibold">{label}<Switch enabled onToggle={() => {}} /></label>)}</div>}</section>;
}

function ImportNominalModal({ existing, onClose, onImport }: { existing: Nominal[]; onClose(): void; onImport(items: Nominal[]): void }) {
  const [selected, setSelected] = useState<string[]>([]);
  const [step, setStep] = useState(1);
  const [margin, setMargin] = useState(10);
  const available = digiflazzCatalog.filter((item) => !existing.some((nominal) => nominal.sku === item.sku));
  function finish() { onImport(available.filter((item) => selected.includes(item.sku)).map((item) => ({ id: crypto.randomUUID(), name: item.name.replace("Mobile Legends ", ""), sku: item.sku, group: "Diamonds", cost: item.cost, margin, sell: Math.ceil(item.cost + item.cost * margin / 100), active: true, imageKind: "diamond" as const, provider: "Digiflazz" as const }))); }
  return <SimpleModal title="Import Nominal dari Digiflazz" description="Produk tetap dibuat manual. Hanya nominal terpilih yang diambil dari Digiflazz." onClose={onClose} wide><div className="mb-[13px] grid grid-cols-4 gap-[8px]">{["Pilih Kategori", "Pilih Nominal", "Atur Margin", "Konfirmasi"].map((label, index) => <div key={label} className={`flex items-center gap-[6px] text-[7px] font-semibold ${step === index + 1 ? "text-[#0875ed]" : "text-[#74849a]"}`}><span className={`grid size-[21px] place-items-center rounded-full ${step === index + 1 ? "bg-[#0875ed] text-white" : "bg-[#eef2f6]"}`}>{index + 1}</span>{label}</div>)}</div>{step === 1 && <div className="grid grid-cols-2 gap-[10px]"><label className="text-[8px] font-bold">Kategori Digiflazz<CompactSelect value="Mobile Games" onChange={() => {}} options={["Mobile Games"]} /></label><label className="text-[8px] font-bold">Pilih Game / Brand<CompactSelect value="Mobile Legends" onChange={() => {}} options={["Mobile Legends"]} /></label></div>}{step === 2 && <div><label className="relative block"><Search className="absolute left-[9px] top-1/2 size-[12px] -translate-y-1/2 text-[#7b899b]" /><input placeholder="Cari nama atau SKU di Digiflazz..." className="h-[32px] w-full rounded-[4px] border border-[#dce3eb] pl-[28px] text-[8px]" /></label><div className="mt-[8px] overflow-hidden rounded-[5px] border border-[#e0e6ed]">{available.map((item) => <label key={item.sku} className="grid grid-cols-[22px_1fr_60px_75px_55px] items-center border-t border-[#e8ecf1] px-[8px] py-[6px] text-[7px] first:border-0"><input type="checkbox" checked={selected.includes(item.sku)} onChange={() => setSelected((current) => current.includes(item.sku) ? current.filter((sku) => sku !== item.sku) : [...current, item.sku])} /><span>{item.name}</span><span>{item.sku}</span><span>{formatRupiah(item.cost)}</span><span className="rounded bg-[#ddf8e8] px-[6px] py-[3px] text-center font-bold text-[#15955a]">{item.status}</span></label>)}</div></div>}{step === 3 && <label className="block text-[8px] font-bold">Margin global untuk nominal Digiflazz (%)<input type="number" value={margin} onChange={(event) => setMargin(Number(event.target.value))} className="mt-[5px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label>}{step === 4 && <div className="rounded-[5px] border border-[#cfe4fa] bg-[#f0f7ff] p-[12px] text-[8px] text-[#415b75]"><strong>{selected.length} nominal dipilih</strong><p className="mt-[4px]">Provider: Digiflazz · Margin: {margin}% · Produk tidak dibuat otomatis.</p></div>}<div className="mt-[15px] flex justify-between"><button type="button" onClick={step === 1 ? onClose : () => setStep((value) => value - 1)} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[13px] text-[8px] font-bold">{step === 1 ? "Batal" : "Kembali"}</button><button type="button" disabled={step === 2 && !selected.length} onClick={step === 4 ? finish : () => setStep((value) => value + 1)} className="h-[32px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white disabled:opacity-50">{step === 4 ? "Import Nominal" : "Lanjut"}</button></div></SimpleModal>;
}

function ManualProductModal({ onClose, onSubmit }: { onClose(): void; onSubmit(event: FormEvent<HTMLFormElement>): void }) {
  return <SimpleModal title="Tambah Produk Manual" description="Semua produk dibuat sendiri. Nominal dapat ditambahkan setelah produk tersimpan." onClose={onClose} wide><form onSubmit={onSubmit}><div className="mb-[12px] flex border-b border-[#e2e7ed]"><span className="border-b-2 border-[#0875ed] px-[10px] pb-[8px] text-[8px] font-bold text-[#0875ed]">Informasi Produk</span><span className="px-[10px] pb-[8px] text-[8px] text-[#718197]">Nominal & Harga</span><span className="px-[10px] pb-[8px] text-[8px] text-[#718197]">Input Customer</span><span className="px-[10px] pb-[8px] text-[8px] text-[#718197]">Fulfillment</span><span className="px-[10px] pb-[8px] text-[8px] text-[#718197]">Tampilan</span></div><div className="grid grid-cols-[120px_1fr_1fr] gap-[12px]"><label className="row-span-3 text-[8px] font-bold text-[#3d4f68]">Gambar produk (opsional, rasio 1:1)<span className="mt-[5px] grid h-[110px] place-items-center rounded-[5px] border border-dashed border-[#cfd9e5] bg-[#fafbfd] text-center text-[#0875ed]"><span><ImageIcon className="mx-auto size-[24px]" /><small className="mt-[5px] block">Pilih gambar</small></span></span><small className="mt-[5px] block font-normal text-[#7a899c]">Boleh dikosongkan dan ditambahkan nanti</small></label><Field label="Nama Produk *" name="name" placeholder="Contoh: Roblox Robux" required /><Field label="Slug *" name="slug" placeholder="contoh: roblox-robux" /><label className="text-[8px] font-bold">Kategori *<select name="category" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]"><option>Mobile Games</option><option>PC Games</option><option>Game Voucher</option></select></label><label className="text-[8px] font-bold">Provider nominal *<select name="provider" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]"><option>Digiflazz</option><option>Manual</option></select></label><label className="col-span-2 text-[8px] font-bold">Deskripsi singkat<textarea name="description" placeholder="Deskripsi singkat produk..." className="mt-[4px] h-[72px] w-full resize-none rounded-[4px] border border-[#dce3eb] p-[9px] text-[8px]" /></label><label className="col-span-2 text-[8px] font-bold">Banner halaman produk (opsional)<input placeholder="Boleh dikosongkan dan ditambahkan nanti" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label></div><ModalActions onCancel={onClose} submit="Lanjut ke Nominal" /></form></SimpleModal>;
}

function SimpleModal({ title, description, children, onClose, wide }: { title: string; description: string; children: ReactNode; onClose(): void; wide?: boolean }) { return <div className="fixed inset-0 z-50 grid place-items-center bg-[#071426]/55 p-[24px]" role="dialog" aria-modal="true" aria-label={title}><section className={`max-h-[88vh] w-full overflow-auto rounded-[9px] bg-white shadow-2xl ${wide ? "max-w-[720px]" : "max-w-[480px]"}`}><header className="flex items-start justify-between border-b border-[#e3e8ef] px-[16px] py-[13px]"><div><h2 className="text-[14px] font-black text-[#101d35]">{title}</h2><p className="mt-[2px] text-[8px] text-[#6d7d92]">{description}</p></div><button type="button" onClick={onClose} className="grid size-[27px] place-items-center rounded-[4px] text-[#596b82] hover:bg-[#f2f5f8]"><X className="size-[14px]" /></button></header><div className="p-[16px]">{children}</div></section></div>; }

function ModalActions({ onCancel, submit }: { onCancel(): void; submit: string }) { return <div className="col-span-full mt-[5px] flex justify-end gap-[8px] border-t border-[#e5e9ef] pt-[12px]"><button type="button" onClick={onCancel} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[14px] text-[8px] font-bold">Batal</button><button type="submit" className="h-[32px] rounded-[4px] bg-[#0875ed] px-[15px] text-[8px] font-bold text-white">{submit}</button></div>; }
function Field({ label, ...props }: { label: string; name: string; placeholder: string; type?: string; required?: boolean }) { return <label className="text-[8px] font-bold text-[#3d4f68]">{label}<input {...props} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px] outline-none placeholder:text-[#929eae] focus:border-[#2580eb]" /></label>; }
function ActionButton({ children, onClick }: { children: ReactNode; onClick?: () => void }) { return <button type="button" onClick={onClick ?? (() => announceAdminAction("Aksi produk dijalankan di frontend."))} className="inline-flex h-[31px] items-center gap-[6px] rounded-[4px] border border-[#dce3eb] bg-white px-[11px] text-[7.5px] font-bold text-[#34506d] hover:bg-[#f6f9fc]">{children}</button>; }
function IconButton({ children, label, danger, onClick }: { children: ReactNode; label: string; danger?: boolean; onClick?: () => void }) { return <button type="button" aria-label={label} onClick={onClick} className={`grid size-[25px] place-items-center rounded-[4px] border ${danger ? "border-red-100 bg-red-50 text-red-500" : "border-[#dce3eb] bg-white text-[#52657d]"}`}>{children}</button>; }
function CompactSelect({ value, onChange, options }: { value: string; onChange(value: string): void; options: string[] }) { return <select value={value} onChange={(event) => onChange(event.target.value)} className="h-[34px] min-w-0 rounded-[5px] border border-[#dce3eb] bg-white px-[9px] text-[8px] font-medium text-[#40516a] outline-none">{options.map((option) => <option key={option}>{option}</option>)}</select>; }
function PageButton({ children, active }: { children: ReactNode; active?: boolean }) { return <button type="button" onClick={() => announceAdminAction("Halaman produk diperbarui.")} className={`grid size-[27px] place-items-center rounded-[4px] border text-[8px] font-bold ${active ? "border-[#0875ed] bg-[#0875ed] text-white" : "border-[#dde4ec] bg-white text-[#4c5e76]"}`}>{children}</button>; }
function Box() { return <span className="block size-[13px] rounded-[3px] border border-[#cdd7e2] bg-white" />; }
function Switch({ enabled, onToggle }: { enabled: boolean; onToggle(): void }) { return <button type="button" aria-pressed={enabled} onClick={onToggle} className={`relative h-[17px] w-[31px] rounded-full transition ${enabled ? "bg-[#0875ed]" : "bg-[#cad5e1]"}`}><span className={`absolute top-[2px] size-[13px] rounded-full bg-white shadow transition ${enabled ? "left-[16px]" : "left-[2px]"}`} /></button>; }
function ProductImage({ product, large }: { product: Product; large?: boolean }) { const size = large ? "size-[58px] rounded-[9px]" : "size-[36px] rounded-[6px]"; return product.image ? <img src={product.image} alt="" className={`${size} object-cover shadow-sm`} /> : <span className={`grid ${size} place-items-center bg-gradient-to-br from-[#2186ef] to-[#133a85] font-black text-white`}>{product.name.slice(0, 2).toUpperCase()}</span>; }
function CategoryBadge({ category }: { category: Product["category"] }) { const style = category === "PC Games" ? "bg-[#eee2ff] text-[#7142b3]" : category === "Game Voucher" ? "bg-[#fff0df] text-[#c46b1b]" : "bg-[#e2f1ff] text-[#1671c5]"; return <span className={`rounded-[4px] px-[7px] py-[4px] text-[6.5px] font-semibold ${style}`}>{category}</span>; }
function NominalArtwork({ kind, large }: { kind: Nominal["imageKind"]; large?: boolean }) { const label = kind === "diamond" ? "💎" : kind === "weekly" ? "🎟️" : kind === "double" ? "2X" : "🌙"; return <span className={`grid place-items-center rounded-[4px] bg-gradient-to-br from-[#b7efff] to-[#6d4fe8] font-black text-white ${large ? "mx-auto my-[7px] size-[35px] text-[16px]" : "size-[27px] text-[11px]"}`}>{label}</span>; }

function moveItem<T extends { id: string }>(items: T[], id: string, direction: -1 | 1) { const index = items.findIndex((item) => item.id === id); const target = index + direction; if (index < 0 || target < 0 || target >= items.length) return items; const next = [...items]; [next[index], next[target]] = [next[target], next[index]]; return next; }
function moveBefore<T extends { id: string }>(items: T[], sourceId: string, targetId: string) { const source = items.find((item) => item.id === sourceId); if (!source || sourceId === targetId) return items; const remaining = items.filter((item) => item.id !== sourceId); const target = remaining.findIndex((item) => item.id === targetId); remaining.splice(target < 0 ? remaining.length : target, 0, source); return remaining; }
function slugify(value: string) { return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
function formatRupiah(value: number) { return `Rp ${new Intl.NumberFormat("id-ID").format(value)}`; }
