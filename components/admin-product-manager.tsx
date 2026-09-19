"use client";
/* eslint-disable @next/next/no-img-element, react-hooks/set-state-in-effect */

import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import {
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  Copy,
  ExternalLink,
  GripVertical,
  ImageIcon,
  Info,
  Monitor,
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
import {
  PRODUCT_CATEGORIES,
  PRODUCT_CATEGORY_LABELS,
  productCategoryLabel,
  productCategorySlug,
  type ProductCategoryLabel,
} from "@/lib/product-categories";

type ProductProvider = "Digiflazz" | "Manual";
type EditorTab = "Informasi Produk" | "Nominal & Harga" | "Tabel Pemisah" | "Tampilan Produk" | "Input Customer" | "Fulfillment";

type ManagedPackagePayload = {
  dbId?: number | null;
  id: string;
  label: string;
  price: number;
  note?: string;
  group?: string;
  imageUrl?: string;
  providerCode?: "digiflazz" | "voucher-stock";
  providerSku?: string;
  supplierPrice?: number | null;
  providerMaxPrice?: number | null;
  pricingMode?: "manual" | "auto";
  marginType?: "fixed" | "percent";
  marginValue?: number;
  isActive: boolean;
  sortOrder: number;
};

type ManagedProductPayload = {
  dbId: number | null;
  slug: string;
  name: string;
  publisher: string;
  category: string;
  imageUrl?: string;
  bannerUrl?: string;
  description?: string;
  initials: string;
  accent: string;
  inputLabel: string;
  inputPlaceholder: string;
  inputFields: Array<{ id: string; label: string; placeholder?: string; required?: boolean }>;
  needsServer: boolean;
  popular: boolean;
  instant: boolean;
  fulfillmentType: "automatic" | "manual";
  targetTemplate: string;
  manualInstructions?: string;
  manualOpenTime?: string;
  manualCloseTime?: string;
  manualTimezone: string;
  packageTabsEnabled: boolean;
  packageTabs: string[];
  isActive: boolean;
  sortOrder: number;
  packages: ManagedPackagePayload[];
  notices: Array<{ id?: number | null; title: string; body: string; isActive: boolean; sortOrder: number }>;
};

type Product = {
  id: number;
  name: string;
  slug: string;
  description: string;
  category: ProductCategoryLabel;
  provider: ProductProvider;
  nominalCount: number;
  startPrice: number;
  active: boolean;
  visible: boolean;
  updated: string;
  image: string;
  raw: ManagedProductPayload;
};

type Nominal = {
  id: string;
  name: string;
  sku: string;
  group: string;
  cost: number;
  margin: number;
  marginType: "fixed" | "percent";
  sell: number;
  active: boolean;
  imageUrl: string;
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

type DigiflazzCatalogItem = {
  buyerSkuCode: string;
  productName: string;
  category: string;
  brand: string;
  price: number;
  buyerProductStatus: boolean;
  sellerProductStatus: boolean;
};

type DigiflazzImportItem = DigiflazzCatalogItem & { sku: string };

function displayCategory(value: string): Product["category"] {
  return productCategoryLabel(value);
}

function apiCategory(value: Product["category"]) {
  return productCategorySlug(value);
}

function mapProduct(raw: ManagedProductPayload): Product {
  const activePackages = raw.packages.filter((item) => item.isActive);
  return {
    id: raw.dbId ?? raw.sortOrder + 1,
    name: raw.name,
    slug: raw.slug,
    description: raw.description || raw.publisher || "Produk digital LFAMILIA",
    category: displayCategory(raw.category),
    provider: raw.packages.some((item) => item.providerCode === "digiflazz") || raw.fulfillmentType === "automatic" ? "Digiflazz" : "Manual",
    nominalCount: raw.packages.length,
    startPrice: activePackages.length ? Math.min(...activePackages.map((item) => item.price)) : 0,
    active: raw.isActive,
    visible: raw.isActive,
    updated: "Tersimpan",
    image: raw.imageUrl || "",
    raw,
  };
}

async function readJson<T>(response: Response): Promise<T> {
  const payload = await response.json().catch(() => ({})) as T & { error?: string };
  if (!response.ok) throw new Error(payload.error || "Permintaan panel gagal diproses.");
  return payload;
}

export function AdminProductManager() {
  const [products, setProducts] = useState<Product[]>([]);
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("Semua Kategori");
  const [provider, setProvider] = useState("Semua Provider");
  const [status, setStatus] = useState("Semua Status");
  const [sort, setSort] = useState("Urutkan: Terbaru");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);
  const [editorProduct, setEditorProduct] = useState<Product | null>(null);
  const [manualProductOpen, setManualProductOpen] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  async function loadProducts(signal?: AbortSignal) {
    setLoading(true);
    setError("");
    try {
      const payload = await readJson<{ products: ManagedProductPayload[] }>(await fetch("/api/panel/products", { cache: "no-store", signal }));
      setProducts(payload.products.map(mapProduct));
    } catch (reason) {
      if (reason instanceof DOMException && reason.name === "AbortError") return;
      setError(reason instanceof Error ? reason.message : "Daftar produk gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const controller = new AbortController();
    void loadProducts(controller.signal);
    return () => controller.abort();
  }, []);

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
  const pageCount = Math.max(1, Math.ceil(visibleProducts.length / pageSize));
  const activePage = Math.min(page, pageCount);
  const pagedProducts = visibleProducts.slice((activePage - 1) * pageSize, activePage * pageSize);

  useEffect(() => {
    setPage(1);
  }, [category, provider, query, sort, status, pageSize]);

  function resetFilters() {
    setQuery(""); setCategory("Semua Kategori"); setProvider("Semua Provider"); setStatus("Semua Status"); setSort("Urutkan: Terbaru"); setPage(1);
  }

  async function addProduct(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const name = String(form.get("name") || "Produk Baru");
    const slug = String(form.get("slug") || slugify(name));
    const selectedProvider = String(form.get("provider") || "Manual") as ProductProvider;
    const category = String(form.get("category") || "Top Up Game") as Product["category"];
    const image = form.get("image");
    const raw: ManagedProductPayload = {
      dbId: null,
      name,
      slug,
      publisher: "",
      category: apiCategory(category),
      imageUrl: "",
      bannerUrl: String(form.get("banner") || ""),
      description: String(form.get("description") || "Produk digital LFAMILIA"),
      initials: name.split(/\s+/).map((item) => item[0]).join("").slice(0, 3).toUpperCase() || "LF",
      accent: "linear-gradient(135deg,#2186ef,#133a85)",
      inputLabel: "User ID",
      inputPlaceholder: "Masukkan User ID",
      inputFields: [{ id: "destination", label: "User ID", placeholder: "Masukkan User ID", required: true }],
      needsServer: false,
      popular: false,
      instant: selectedProvider === "Digiflazz",
      fulfillmentType: selectedProvider === "Digiflazz" ? "automatic" : "manual",
      targetTemplate: "{{destination}}",
      manualInstructions: "",
      manualTimezone: "Asia/Jakarta",
      packageTabsEnabled: false,
      packageTabs: [],
      isActive: false,
      sortOrder: products.length,
      packages: [],
      notices: [],
    };
    setSaving(true); setError("");
    try {
      if (image instanceof File && image.size > 0) {
        if (image.size > 2 * 1024 * 1024) throw new Error("Ukuran gambar produk maksimal 2MB.");
        const upload = new FormData();
        upload.set("file", image);
        const uploaded = await readJson<{ url: string }>(await fetch("/api/panel/media", { method: "POST", body: upload }));
        raw.imageUrl = uploaded.url;
      }
      const result = await readJson<{ id: number }>(await fetch("/api/panel/products", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(raw) }));
      const next = mapProduct({ ...raw, dbId: result.id });
      setProducts((current) => [...current, next]);
      setManualProductOpen(false);
      setEditorProduct(next);
      setNotice(`${name} ditambahkan. Tambahkan nominal sebelum mengaktifkannya.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Produk gagal ditambahkan.");
    } finally { setSaving(false); }
  }

  async function toggleProduct(id: number) {
    const product = products.find((item) => item.id === id);
    if (!product?.raw.dbId) return;
    const nextRaw = { ...product.raw, isActive: !product.raw.isActive };
    setError("");
    try {
      await readJson(await fetch("/api/panel/products", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify(nextRaw) }));
      setProducts((current) => current.map((item) => item.id === id ? mapProduct(nextRaw) : item));
      setNotice(`${product.name} ${nextRaw.isActive ? "ditampilkan" : "disembunyikan"} dari katalog.`);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Status produk gagal diperbarui."); }
  }

  if (editorProduct) {
    return <ProductEditor product={editorProduct} onBack={() => { setEditorProduct(null); void loadProducts(); }} onNotice={setNotice} />;
  }

  return (
    <div className="admin-products-reference min-w-0 text-[#14213a]">
      <div className="flex flex-col items-start justify-between gap-[12px] sm:flex-row sm:gap-[16px]">
        <div><h1 className="text-[23px] font-black tracking-[-0.04em] text-[#0b1834]">Produk</h1><p className="mt-[3px] text-[10px] text-[#64758c]">Kelola semua produk top up, voucher, dan layanan digital.</p></div>
        <div className="flex w-full flex-wrap items-center gap-[8px] sm:w-auto sm:justify-end">
          <button type="button" onClick={() => setNotice("Gunakan tombol Nominal pada baris produk untuk membuka editor nominal dan import Digiflazz.")} className="inline-flex h-[34px] flex-1 items-center justify-center gap-[7px] rounded-[5px] border border-[#dce3eb] bg-white px-[13px] text-[9px] font-bold text-[#34465f] hover:bg-[#f8fafc] sm:flex-none"><SlidersHorizontal className="size-[13px]" />Kelola Nominal</button>
          <button type="button" onClick={() => setManualProductOpen(true)} className="inline-flex h-[34px] flex-1 items-center justify-center gap-[7px] rounded-[5px] bg-[#0875ed] px-[15px] text-[9px] font-bold text-white shadow-[0_5px_14px_rgba(8,117,237,.2)] hover:bg-[#0668d5] sm:flex-none"><Plus className="size-[14px]" />Tambah Produk</button>
        </div>
      </div>

      {notice && <button type="button" onClick={() => setNotice("")} className="mt-[10px] flex w-full items-center justify-between rounded-[6px] border border-[#b9dfca] bg-[#edf9f2] px-[12px] py-[8px] text-left text-[9px] font-semibold text-[#168553]"><span>{notice}</span><X className="size-[12px]" /></button>}
      {error && <button type="button" onClick={() => setError("")} className="mt-[10px] w-full rounded-[6px] border border-red-200 bg-red-50 px-[12px] py-[8px] text-left text-[9px] text-red-700">{error}</button>}

      <div className="mt-[15px] grid grid-cols-1 gap-[8px] sm:grid-cols-2 xl:grid-cols-[1.65fr_.75fr_.78fr_.72fr_.85fr_auto]">
        <label className="relative"><Search className="absolute left-[10px] top-1/2 size-[13px] -translate-y-1/2 text-[#708198]" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari nama produk, kategori, atau slug..." className="h-[34px] w-full rounded-[5px] border border-[#dce3eb] bg-white pl-[31px] pr-[9px] text-[9px] outline-none placeholder:text-[#8290a2] focus:border-[#2680eb]" /></label>
        <CompactSelect value={category} onChange={setCategory} options={["Semua Kategori", ...PRODUCT_CATEGORY_LABELS]} />
        <CompactSelect value={provider} onChange={setProvider} options={["Semua Provider", "Digiflazz", "Manual"]} />
        <CompactSelect value={status} onChange={setStatus} options={["Semua Status", "Aktif", "Nonaktif"]} />
        <CompactSelect value={sort} onChange={setSort} options={["Urutkan: Terbaru", "Urutkan: Nama A-Z"]} />
        <button type="button" onClick={resetFilters} className="inline-flex h-[34px] items-center gap-[6px] rounded-[5px] border border-[#dce3eb] bg-white px-[14px] text-[9px] font-semibold text-[#40516a]"><RefreshCw className="size-[12px]" />Reset</button>
      </div>

      <section className="mt-[10px] overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]">
        {loading ? <div className="grid h-[190px] place-items-center text-[9px] text-[#64758c]">Memuat produk dari database...</div> : <ProductTable products={pagedProducts} startIndex={(activePage - 1) * pageSize} onEdit={setEditorProduct} onToggle={(id) => void toggleProduct(id)} />}
        <div className="flex min-h-[48px] flex-wrap items-center justify-between gap-2 border-t border-[#e4e9ef] px-[12px] py-2 text-[8px] text-[#586980]">
          <span>Menampilkan {visibleProducts.length ? (activePage - 1) * pageSize + 1 : 0}–{Math.min(activePage * pageSize, visibleProducts.length)} dari {visibleProducts.length} produk</span>
          <div className="flex items-center gap-[5px]">
            <button type="button" disabled={activePage <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))} className="h-[27px] rounded-[4px] border border-[#dde4ec] bg-white px-2 font-bold disabled:opacity-40">‹</button>
            <PageButton active>{activePage}/{pageCount}</PageButton>
            <button type="button" disabled={activePage >= pageCount} onClick={() => setPage((value) => Math.min(pageCount, value + 1))} className="h-[27px] rounded-[4px] border border-[#dde4ec] bg-white px-2 font-bold disabled:opacity-40">›</button>
          </div>
          <select value={pageSize} onChange={(event) => setPageSize(Number(event.target.value))} className="h-[29px] rounded-[5px] border border-[#dce3eb] bg-white px-[8px]">
            <option value={25}>25 / halaman</option><option value={50}>50 / halaman</option><option value={100}>100 / halaman</option>
          </select>
        </div>
      </section>

      {manualProductOpen && <ManualProductModal saving={saving} onClose={() => setManualProductOpen(false)} onSubmit={addProduct} />}
    </div>
  );
}

function ProductTable({ products, startIndex, onEdit, onToggle }: { products: Product[]; startIndex: number; onEdit(product: Product): void; onToggle(id: number): void }) {
  return (
    <div className="overflow-x-auto"><table className="w-full min-w-[960px] table-fixed text-left">
      <thead className="bg-[#f3f6fa] text-[7px] font-bold text-[#52637b]"><tr><th className="w-[35px] px-[12px] py-[10px]"><Box /></th><th className="w-[28px] py-[10px]">#</th><th className="w-[62px] py-[10px]">Gambar</th><th className="w-[170px] py-[10px]">Nama Produk</th><th className="w-[108px] py-[10px]">Kategori</th><th className="w-[86px] py-[10px]">Provider</th><th className="w-[90px] py-[10px]">Total Nominal</th><th className="w-[88px] py-[10px]">Harga Mulai</th><th className="w-[73px] py-[10px]">Status</th><th className="w-[78px] py-[10px]">Ditampilkan</th><th className="w-[112px] py-[10px]">Terakhir Update</th><th className="w-[112px] py-[10px]">Aksi</th></tr></thead>
      <tbody>{products.map((product, index) => <tr key={product.id} className="border-t border-[#e4e9ef] text-[7.5px] text-[#34465e] hover:bg-[#fafbfd]"><td className="px-[12px] py-[7px]"><Box /></td><td>{startIndex + index + 1}</td><td className="py-[5px]"><ProductImage product={product} /></td><td className="pr-[8px]"><strong className="block truncate text-[8px] text-[#21344e]">{product.name}</strong><span className="block truncate text-[6.5px] text-[#718198]">{product.description}</span></td><td><CategoryBadge category={product.category} /></td><td>{product.provider}</td><td>{product.nominalCount}</td><td>{formatRupiah(product.startPrice)}</td><td><span className={`rounded-[4px] px-[7px] py-[4px] font-bold ${product.active ? "bg-[#dff8e9] text-[#15965b]" : "bg-[#eef1f5] text-[#6f7f92]"}`}>{product.active ? "Aktif" : "Nonaktif"}</span></td><td><Switch enabled={product.visible} onToggle={() => onToggle(product.id)} /></td><td>{product.updated}</td><td><div className="flex items-center gap-[5px]"><button type="button" onClick={() => onEdit(product)} className="inline-flex h-[29px] items-center gap-[5px] rounded-[4px] border border-[#dbe2eb] bg-white px-[10px] font-bold text-[#40516a] hover:bg-[#f5f8fb]"><Pencil className="size-[10px]" />Edit</button><button type="button" onClick={() => onEdit(product)} className="inline-flex h-[29px] items-center rounded-[4px] border border-[#dbe2eb] bg-white px-[8px] font-bold text-[#0875ed] hover:bg-[#f5f8fb]">Nominal</button></div></td></tr>)}</tbody>
    </table></div>
  );
}

function ProductEditor({ product, onBack, onNotice }: { product: Product; onBack(): void; onNotice(message: string): void }) {
  const [tab, setTab] = useState<EditorTab>("Nominal & Harga");
  const [nominals, setNominals] = useState<Nominal[]>(() => product.raw.packages.map((item) => ({
    id: item.id,
    name: item.label,
    sku: item.providerSku || item.id,
    group: item.group || "Lainnya",
    cost: item.providerCode === "digiflazz" ? (item.providerMaxPrice ?? item.supplierPrice ?? item.price) : (item.supplierPrice ?? item.price),
    margin: item.marginType === "percent" ? (item.marginValue ?? 0) : (item.providerCode === "digiflazz" ? item.providerMaxPrice : item.supplierPrice) ? Math.max(0, Math.round((item.price - (item.providerCode === "digiflazz" ? (item.providerMaxPrice ?? item.price) : (item.supplierPrice ?? item.price))) / (item.providerCode === "digiflazz" ? (item.providerMaxPrice ?? item.price) : (item.supplierPrice ?? item.price)) * 100)) : 0,
    marginType: item.marginType || "percent",
    sell: item.price,
    active: item.isActive,
    imageUrl: item.imageUrl || "",
    imageKind: item.label.toLowerCase().includes("weekly") ? "weekly" : item.label.toLowerCase().includes("twilight") ? "twilight" : item.label.includes("+") ? "double" : "diamond",
    provider: item.providerCode === "digiflazz" ? "Digiflazz" : "Manual",
  })));
  const [sections, setSections] = useState<NominalSection[]>(() => {
    const names = product.raw.packageTabs.length ? product.raw.packageTabs : Array.from(new Set(product.raw.packages.map((item) => item.group).filter((item): item is string => Boolean(item))));
    return names.map((name, index) => ({ id: `section-${index}-${slugify(name)}`, name, description: "", position: "Di atas", active: true }));
  });
  const [importOpen, setImportOpen] = useState(false);
  const [manualOpen, setManualOpen] = useState(false);
  const [sectionOpen, setSectionOpen] = useState(false);
  const [nominalEditor, setNominalEditor] = useState<Nominal | null>(null);
  const [marginOpen, setMarginOpen] = useState(false);
  const [previewMode, setPreviewMode] = useState<"Mobile" | "Desktop">("Mobile");
  const [dragging, setDragging] = useState<{ kind: "nominal" | "section"; id: string } | null>(null);
  const [message, setMessage] = useState("");
  const [monitorRefreshing, setMonitorRefreshing] = useState(false);
  const [error, setError] = useState("");
  const [checkoutType, setCheckoutType] = useState<"id" | "id-server">("id-server");
  const [labelId, setLabelId] = useState("User ID");
  const [labelServer, setLabelServer] = useState("Server ID");
  const [nicknameGameCode, setNicknameGameCode] = useState("");
  const [inputLoading, setInputLoading] = useState(true);
  const [inputSaving, setInputSaving] = useState(false);
  const [mediaUploading, setMediaUploading] = useState<Record<"imageUrl" | "bannerUrl", boolean>>({
    imageUrl: false,
    bannerUrl: false,
  });
  const mediaUploadVersion = useRef<Record<"imageUrl" | "bannerUrl", number>>({ imageUrl: 0, bannerUrl: 0 });
  const mediaUploadActive = useRef<Record<"imageUrl" | "bannerUrl", boolean>>({
    imageUrl: false,
    bannerUrl: false,
  });
  const [name, setName] = useState(product.raw.name);
  const [slug, setSlug] = useState(product.raw.slug);
  const [publisher, setPublisher] = useState(product.raw.publisher);
  const [description, setDescription] = useState(product.raw.description || "");
  const [imageUrl, setImageUrl] = useState(product.raw.imageUrl || "");
  const [bannerUrl, setBannerUrl] = useState(product.raw.bannerUrl || "");
  const [category, setCategory] = useState(product.raw.category);
  const [isActive, setIsActive] = useState(product.raw.isActive);
  const [popular, setPopular] = useState(product.raw.popular);
  const [instant, setInstant] = useState(product.raw.instant);
  const [fulfillmentType, setFulfillmentType] = useState(product.raw.fulfillmentType);
  const [manualInstructions, setManualInstructions] = useState(product.raw.manualInstructions || "");
  const inputFields = checkoutType === "id-server"
    ? [{ id: "destination", label: labelId }, { id: "server", label: labelServer }]
    : [{ id: "destination", label: labelId }];
  const targetTemplate = inputFields.map((item) => `{{${item.id}}}`).join("");

  useEffect(() => {
    const controller = new AbortController();
    setInputLoading(true);
    fetch(`/api/panel/product-input?slug=${encodeURIComponent(product.slug)}`, { cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        const payload = await response.json().catch(() => ({})) as { error?: string; input?: { checkoutType: "id" | "id-server"; labelId: string; labelServer: string; nicknameGameCode: string } };
        if (!response.ok || !payload.input) throw new Error(payload.error || "Pengaturan input pelanggan gagal dimuat.");
        setCheckoutType(payload.input.checkoutType);
        setLabelId(payload.input.labelId);
        setLabelServer(payload.input.labelServer);
        setNicknameGameCode(payload.input.nicknameGameCode || "");
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
        body: JSON.stringify({ slug: product.slug, checkoutType, labelId, labelServer, nicknameGameCode }),
      });
      const payload = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(payload.error || "Pengaturan input pelanggan gagal disimpan.");
      setMessage("Pengaturan input dan kode game berhasil disimpan ke backend.");
      onNotice(`${product.name} diperbarui.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan input pelanggan gagal disimpan.");
    } finally { setInputSaving(false); }
  }

  function buildPayload(): ManagedProductPayload {
    const activeSections = sections.filter((item) => item.active).map((item) => item.name.trim()).filter(Boolean);
    return {
      ...product.raw,
      dbId: product.raw.dbId,
      name: name.trim(),
      slug: slugify(slug),
      publisher: publisher.trim(),
      description: description.trim(),
      category,
      imageUrl: imageUrl.trim(),
      bannerUrl: bannerUrl.trim(),
      initials: name.trim().split(/\s+/).map((item) => item[0]).join("").slice(0, 3).toUpperCase() || product.raw.initials,
      inputLabel: labelId.trim(),
      inputPlaceholder: `Masukkan ${labelId.trim()}`,
      inputFields: checkoutType === "id-server"
        ? [{ id: "destination", label: labelId.trim(), placeholder: `Masukkan ${labelId.trim()}`, required: true }, { id: "server", label: labelServer.trim(), placeholder: `Masukkan ${labelServer.trim()}`, required: true }]
        : [{ id: "destination", label: labelId.trim(), placeholder: `Masukkan ${labelId.trim()}`, required: true }],
      needsServer: checkoutType === "id-server",
      targetTemplate,
      popular,
      instant,
      fulfillmentType,
      manualInstructions: manualInstructions.trim(),
      packageTabsEnabled: activeSections.length > 0,
      packageTabs: activeSections,
      isActive,
      packages: nominals.map((item, index) => {
        const previous = product.raw.packages.find((stored) => stored.id === item.id || stored.providerSku === item.sku);
        return {
          id: item.id,
          label: item.name,
          price: Math.max(1, Math.round(item.sell)),
          note: previous?.note || "",
          group: item.group || undefined,
          imageUrl: item.imageUrl.trim(),
          providerCode: item.provider === "Digiflazz" ? "digiflazz" : previous?.providerCode === "voucher-stock" ? "voucher-stock" : undefined,
          providerSku: item.provider === "Digiflazz" ? item.sku : previous?.providerSku,
          supplierPrice: item.provider === "Digiflazz" ? previous?.supplierPrice ?? null : Math.max(0, Math.round(item.cost)),
          providerMaxPrice: item.provider === "Digiflazz" ? Math.max(1, Math.round(item.cost)) : previous?.providerMaxPrice ?? null,
          pricingMode: item.provider === "Digiflazz" ? "auto" : "manual",
          marginType: item.marginType,
          marginValue: Math.max(0, Math.round(item.margin)),
          isActive: item.active,
          sortOrder: index,
        };
      }),
    };
  }

  async function uploadProductImage(field: "imageUrl" | "bannerUrl", file?: File) {
    if (!file) return;
    if (file.size > 6 * 1024 * 1024) {
      setError("Ukuran gambar maksimal 6 MB.");
      return;
    }
    const requestVersion = ++mediaUploadVersion.current[field];
    mediaUploadActive.current[field] = true;
    setMediaUploading((current) => ({ ...current, [field]: true }));
    setError("");
    setMessage("");
    try {
      const form = new FormData();
      form.set("file", file);
      const uploaded = await readJson<{ url: string }>(await fetch("/api/panel/media", { method: "POST", body: form }));
      if (mediaUploadVersion.current[field] !== requestVersion) return;
      if (field === "imageUrl") setImageUrl(uploaded.url);
      else setBannerUrl(uploaded.url);
      setMessage(field === "imageUrl" ? "Gambar produk berhasil diunggah. Klik Simpan Perubahan untuk menerapkan." : "Banner produk berhasil diunggah. Klik Simpan Perubahan untuk menerapkan.");
    } catch (reason) {
      if (mediaUploadVersion.current[field] !== requestVersion) return;
      setError(reason instanceof Error ? reason.message : "Gambar gagal diunggah.");
    } finally {
      if (mediaUploadVersion.current[field] === requestVersion) {
        mediaUploadActive.current[field] = false;
        setMediaUploading((current) => ({ ...current, [field]: false }));
      }
    }
  }

  async function saveProductChanges(success = "Perubahan produk berhasil disimpan.") {
    if (mediaUploadActive.current.imageUrl || mediaUploadActive.current.bannerUrl) { setError("Tunggu semua unggahan gambar selesai sebelum menyimpan."); return; }
    if (!product.raw.dbId) { setError("ID produk tidak ditemukan."); return; }
    if (!name.trim() || !slugify(slug)) { setError("Nama dan slug produk wajib diisi."); return; }
    setInputSaving(true); setError(""); setMessage("");
    try {
      await readJson(await fetch("/api/panel/products", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify(buildPayload()) }));
      setMessage(success);
      onNotice(`${name.trim()} diperbarui.`);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Produk gagal disimpan."); }
    finally { setInputSaving(false); }
  }

  async function refreshSellerMonitor() {
    setMonitorRefreshing(true);
    setError("");
    setMessage("");
    try {
      await readJson(await fetch("/api/panel/digiflazz-pricing", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ productId: product.raw.dbId }) }));
      setMessage("Harga modal dan harga jual berhasil disinkronkan dari katalog.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Sinkron harga gagal."); }
    finally { setMonitorRefreshing(false); }
  }

  function moveNominal(id: string, direction: -1 | 1) { setNominals((current) => moveItem(current, id, direction)); }
  function moveSection(id: string, direction: -1 | 1) { setSections((current) => moveItem(current, id, direction)); }
  function dropNominal(targetId: string) { if (dragging?.kind === "nominal") setNominals((current) => moveBefore(current, dragging.id, targetId)); setDragging(null); }
  function dropSection(targetId: string) { if (dragging?.kind === "section") setSections((current) => moveBefore(current, dragging.id, targetId)); setDragging(null); }

  function addManualNominal(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget); const cost = Number(form.get("cost") || 0); const margin = Number(form.get("margin") || 0);
    setNominals((current) => [...current, { id: crypto.randomUUID(), name: String(form.get("name")), sku: String(form.get("sku") || "MANUAL"), group: String(form.get("group") || sections[0]?.name || "Lainnya"), cost, margin, marginType: "fixed", sell: Math.ceil(cost + cost * margin / 100), active: true, imageUrl: "", imageKind: "diamond", provider: "Manual" }]);
    setManualOpen(false); setMessage("Nominal manual berhasil ditambahkan.");
  }

  function copyNominal(id: string) {
    setNominals((current) => {
      const source = current.find((item) => item.id === id);
      if (!source) return current;
      const copyNumber = current.filter((item) => item.name.startsWith(`${source.name} (Salinan`)).length + 1;
      return [...current, {
        ...source,
        id: crypto.randomUUID(),
        name: `${source.name} (Salinan ${copyNumber})`,
        sku: `${source.sku}-COPY-${copyNumber}`,
        provider: "Manual",
      }];
    });
    setMessage("Salinan nominal ditambahkan sebagai nominal manual. Simpan perubahan untuk menerapkannya.");
  }

  function updateNominal(next: Nominal) {
    setNominals((current) => current.map((item) => item.id === next.id ? next : item));
    setNominalEditor(null);
    setMessage("Nominal diperbarui. Klik Simpan Perubahan untuk menerapkannya ke toko.");
  }

  function applyGlobalMargin(input: { type: "fixed" | "percent"; value: number; target: "digiflazz" | "all" }) {
    setNominals((current) => current.map((item) => {
      if (input.target === "digiflazz" && item.provider !== "Digiflazz") return item;
      const sell = input.type === "percent"
        ? Math.ceil(item.cost * (100 + input.value) / 100)
        : Math.ceil(item.cost + input.value);
      return { ...item, marginType: input.type, margin: input.value, sell: Math.max(1, sell) };
    }));
    setMarginOpen(false);
    setMessage("Margin massal diterapkan. Klik Simpan Perubahan untuk menerapkannya ke toko.");
  }

  async function syncNominal(id: string) {
    const nominal = nominals.find((item) => item.id === id);
    if (!nominal || nominal.provider !== "Digiflazz" || !product.raw.dbId) {
      setError("Sinkron hanya tersedia untuk nominal Digiflazz yang sudah tersimpan.");
      return;
    }
    setMonitorRefreshing(true);
    setError("");
    try {
      await readJson(await fetch("/api/panel/digiflazz-pricing", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ productId: product.raw.dbId, packageSku: nominal.sku }) }));
      setMessage(`${nominal.name} berhasil disinkronkan. Muat ulang produk setelah perubahan tersimpan.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Sinkron nominal gagal.");
    } finally {
      setMonitorRefreshing(false);
    }
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
        <div className="flex gap-[9px]"><button type="button" onClick={() => window.open(`/checkout?product=${encodeURIComponent(product.slug)}`, "_blank", "noopener,noreferrer")} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] border border-[#dce3eb] bg-white px-[15px] text-[9px] font-bold text-[#2f425b]"><ExternalLink className="size-[12px] text-[#0875ed]" />Lihat di Toko</button><button type="button" onClick={onBack} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] border border-[#dce3eb] bg-white px-[16px] text-[9px] font-bold text-[#2f425b]"><ArrowLeft className="size-[13px]" />Kembali</button></div>
      </header>

      <nav className="mt-[14px] flex border-b border-[#dce3eb]">{(["Informasi Produk", "Nominal & Harga", "Tabel Pemisah", "Tampilan Produk", "Input Customer", "Fulfillment"] as EditorTab[]).map((item) => <button type="button" key={item} onClick={() => setTab(item)} className={`h-[38px] border-b-2 px-[16px] text-[9px] font-semibold ${tab === item ? "border-[#0875ed] text-[#0875ed]" : "border-transparent text-[#4f6078]"}`}>{item}</button>)}</nav>

      {message && <button type="button" onClick={() => setMessage("")} className="mt-[10px] flex w-full items-center justify-between rounded-[5px] border border-[#bce3ce] bg-[#eef9f3] px-[11px] py-[7px] text-[8px] font-semibold text-[#158755]"><span>{message}</span><X className="size-[11px]" /></button>}
      {error && <button type="button" onClick={() => setError("")} className="mt-[10px] w-full rounded-[5px] border border-red-200 bg-red-50 px-[11px] py-[7px] text-left text-[8px] text-red-700">{error}</button>}

      {tab === "Nominal & Harga" || tab === "Tabel Pemisah" ? (
        <div className="mt-[12px] grid grid-cols-[minmax(0,1fr)_300px] gap-[12px]">
          <main className="min-w-0 space-y-[12px]">
            <section className={`overflow-hidden rounded-[7px] border border-[#dfe6ef] bg-white ${tab === "Tabel Pemisah" ? "opacity-60" : ""}`}>
              <div className="flex items-center justify-between px-[14px] py-[12px]"><div><h2 className="text-[13px] font-extrabold">Daftar Nominal</h2><p className="mt-[2px] text-[8px] text-[#6b7c92]">Kelola semua nominal, set gambar, harga dan tentukan posisi di tabel pemisah.</p></div></div>
              <div className="flex flex-wrap gap-[7px] border-t border-[#eef1f5] px-[14px] py-[9px]"><ActionButton onClick={() => setImportOpen(true)}><Plus className="size-[12px]" />Tambah dari Digiflazz</ActionButton><ActionButton onClick={() => setManualOpen(true)}><Plus className="size-[12px]" />Tambah Manual</ActionButton><ActionButton onClick={() => nominals[0] ? setNominalEditor(nominals[0]) : setError("Tambahkan nominal terlebih dahulu.")}><Upload className="size-[12px]" />Upload Gambar Nominal</ActionButton><ActionButton onClick={() => setMessage("Seret baris atau gunakan tombol naik/turun untuk mengatur urutan.")}><Settings2 className="size-[12px]" />Atur Urutan</ActionButton><ActionButton onClick={() => void refreshSellerMonitor()}><RefreshCw className={`size-[12px] ${monitorRefreshing ? "animate-spin" : ""}`} />{monitorRefreshing ? "Menyinkron..." : "Sync Harga"}</ActionButton><ActionButton onClick={() => setMarginOpen(true)}><SlidersHorizontal className="size-[12px]" />Atur Margin Massal</ActionButton><button type="button" disabled={inputSaving} onClick={() => void saveProductChanges("Nominal dan tabel pemisah berhasil disimpan ke database.")} className="ml-auto inline-flex h-[31px] items-center gap-[6px] rounded-[4px] bg-[#0875ed] px-[13px] text-[8px] font-bold text-white disabled:opacity-50"><Save className="size-[12px]" />{inputSaving ? "Menyimpan..." : "Simpan Perubahan"}</button></div>
              <NominalTable nominals={nominals} sections={sections} onChange={setNominals} onMove={moveNominal} onCopy={copyNominal} onEdit={setNominalEditor} onSync={syncNominal} onDragStart={(id) => setDragging({ kind: "nominal", id })} onDrop={dropNominal} />
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
        tab === "Input Customer" ? <EditorTabPanel tab={tab} product={product} targetTemplate={targetTemplate} checkoutType={checkoutType} labelId={labelId} labelServer={labelServer} nicknameGameCode={nicknameGameCode} onCheckoutType={setCheckoutType} onLabelId={setLabelId} onLabelServer={setLabelServer} onNicknameGameCode={setNicknameGameCode} inputLoading={inputLoading} saving={inputSaving} onSave={saveInputSettings} /> : <ProductSettingsPanel tab={tab} product={product} values={{ name, slug, publisher, description, imageUrl, bannerUrl, category, isActive, popular, instant, fulfillmentType, manualInstructions }} onChange={(key, value) => { if (key === "name") setName(String(value)); else if (key === "slug") setSlug(String(value)); else if (key === "publisher") setPublisher(String(value)); else if (key === "description") setDescription(String(value)); else if (key === "imageUrl") setImageUrl(String(value)); else if (key === "bannerUrl") setBannerUrl(String(value)); else if (key === "category") setCategory(String(value)); else if (key === "isActive") setIsActive(Boolean(value)); else if (key === "popular") setPopular(Boolean(value)); else if (key === "instant") setInstant(Boolean(value)); else if (key === "fulfillmentType") setFulfillmentType(value as "automatic" | "manual"); else if (key === "manualInstructions") setManualInstructions(String(value)); }} uploading={mediaUploading} onUpload={(field, file) => void uploadProductImage(field, file)} saving={inputSaving || mediaUploading.imageUrl || mediaUploading.bannerUrl} onSave={() => void saveProductChanges()} />
      )}

      {importOpen && <ImportNominalModal existing={nominals} onClose={() => setImportOpen(false)} onImport={(added) => { setNominals((current) => [...current, ...added]); setImportOpen(false); setMessage(`${added.length} nominal Digiflazz berhasil ditambahkan.`); }} />}
      {manualOpen && <SimpleModal title="Tambah Nominal Manual" description="Isi nominal sendiri tanpa mengambil data Digiflazz." onClose={() => setManualOpen(false)}><form onSubmit={addManualNominal} className="grid grid-cols-2 gap-[10px]"><Field label="Nama nominal" name="name" placeholder="Contoh: 50 Diamonds" required /><Field label="SKU internal" name="sku" placeholder="ML-MANUAL-50" /><Field label="Harga modal" name="cost" placeholder="10000" type="number" required /><Field label="Margin (%)" name="margin" placeholder="10" type="number" required /><label className="col-span-2 text-[8px] font-bold text-[#3d4f68]">Grup / Tabel<select name="group" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]">{sections.map((section) => <option key={section.id}>{section.name}</option>)}</select></label><ModalActions onCancel={() => setManualOpen(false)} submit="Tambah Nominal" /></form></SimpleModal>}
      {sectionOpen && <SimpleModal title="Tambah Tabel Pemisah" description="Buat section baru untuk mengelompokkan nominal." onClose={() => setSectionOpen(false)}><form onSubmit={addSection} className="grid gap-[10px]"><Field label="Nama tabel / section" name="name" placeholder="Contoh: Promo Spesial" required /><Field label="Deskripsi (opsional)" name="description" placeholder="Keterangan section" /><ModalActions onCancel={() => setSectionOpen(false)} submit="Tambah Tabel" /></form></SimpleModal>}
      {nominalEditor && <NominalEditorModal nominal={nominalEditor} sections={sections} onClose={() => setNominalEditor(null)} onSave={updateNominal} />}
      {marginOpen && <GlobalMarginModal onClose={() => setMarginOpen(false)} onApply={applyGlobalMargin} />}
    </div>
  );
}

function NominalTable({ nominals, sections, onChange, onMove, onCopy, onEdit, onSync, onDragStart, onDrop }: { nominals: Nominal[]; sections: NominalSection[]; onChange(value: Nominal[]): void; onMove(id: string, direction: -1 | 1): void; onCopy(id: string): void; onEdit(value: Nominal): void; onSync(id: string): void; onDragStart(id: string): void; onDrop(id: string): void }) {
  return <div className="overflow-x-auto"><table className="w-full min-w-[820px] table-fixed text-left"><thead className="bg-[#f2f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[24px]"></th><th className="w-[25px] py-[8px]">#</th><th className="w-[120px]">Nama Nominal</th><th className="w-[52px]">Gambar</th><th className="w-[72px]">SKU Digiflazz</th><th className="w-[98px]">Grup / Tabel</th><th className="w-[45px]">Urutan</th><th className="w-[65px]">Max Price</th><th className="w-[52px]">Margin</th><th className="w-[70px]">Harga Jual</th><th className="w-[53px]">Status</th><th className="w-[132px]">Aksi</th></tr></thead><tbody>{nominals.map((nominal, index) => <tr key={nominal.id} draggable onDragStart={() => onDragStart(nominal.id)} onDragOver={(event) => event.preventDefault()} onDrop={() => onDrop(nominal.id)} className="border-t border-[#e5eaf0] text-[6.8px] text-[#34465e] hover:bg-[#fafbfd]"><td><GripVertical className="mx-auto size-[12px] cursor-grab text-[#7b8ba0]" /></td><td className="py-[6px]">{index + 1}</td><td className="truncate pr-[5px] font-semibold">{nominal.name}</td><td><NominalArtwork kind={nominal.imageKind} imageUrl={nominal.imageUrl} /></td><td>{nominal.provider === "Digiflazz" ? nominal.sku : "Manual"}</td><td><select value={nominal.group} onChange={(event) => onChange(nominals.map((item) => item.id === nominal.id ? { ...item, group: event.target.value } : item))} className="h-[27px] w-[92px] rounded-[4px] border border-[#dce3eb] bg-white px-[5px] text-[6.5px]">{sections.map((section) => <option key={section.id}>{section.name}</option>)}</select></td><td><input value={index + 1} readOnly className="h-[27px] w-[34px] rounded-[4px] border border-[#dce3eb] text-center" /></td><td>{formatRupiah(nominal.cost)}</td><td><span className="inline-flex h-[27px] items-center rounded-[4px] border border-[#dce3eb] bg-white px-[6px]">{nominal.marginType === "percent" ? `${nominal.margin} %` : formatRupiah(nominal.margin)}</span></td><td className="font-semibold">{formatRupiah(nominal.sell)}</td><td><Switch enabled={nominal.active} onToggle={() => onChange(nominals.map((item) => item.id === nominal.id ? { ...item, active: !item.active } : item))} /></td><td><div className="flex gap-[3px]"><IconButton label="Edit nominal" onClick={() => onEdit(nominal)}><Pencil /></IconButton>{nominal.provider === "Digiflazz" && <IconButton label="Sync nominal" onClick={() => void onSync(nominal.id)}><RefreshCw /></IconButton>}<IconButton label="Naik" onClick={() => onMove(nominal.id, -1)}><ArrowUp /></IconButton><IconButton label="Turun" onClick={() => onMove(nominal.id, 1)}><ArrowDown /></IconButton><IconButton label="Salin" onClick={() => onCopy(nominal.id)}><Copy /></IconButton><IconButton label="Hapus" danger onClick={() => onChange(nominals.filter((item) => item.id !== nominal.id))}><Trash2 /></IconButton></div></td></tr>)}</tbody></table></div>;
}

function SectionTable({ sections, onChange, onMove, onDragStart, onDrop }: { sections: NominalSection[]; onChange(value: NominalSection[]): void; onMove(id: string, direction: -1 | 1): void; onDragStart(id: string): void; onDrop(id: string): void }) {
  return <div className="overflow-x-auto border-t border-[#e7ebf0]"><table className="w-full min-w-[720px] table-fixed text-left"><thead className="bg-[#f2f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[27px]"></th><th className="w-[25px] py-[8px]">#</th><th className="w-[130px]">Nama Tabel / Section</th><th>Deskripsi (Opsional)</th><th className="w-[105px]">Posisi di Halaman</th><th className="w-[50px]">Urutan</th><th className="w-[60px]">Status</th><th className="w-[90px]">Aksi</th></tr></thead><tbody>{sections.map((section, index) => <tr key={section.id} draggable onDragStart={() => onDragStart(section.id)} onDragOver={(event) => event.preventDefault()} onDrop={() => onDrop(section.id)} className="border-t border-[#e6eaf0] text-[7px] text-[#35475f]"><td><GripVertical className="mx-auto size-[12px] cursor-grab text-[#75869c]" /></td><td className="py-[7px]">{index + 1}</td><td className="font-semibold">{section.name}</td><td className="truncate pr-[8px]">{section.description}</td><td><select value={section.position} onChange={(event) => onChange(sections.map((item) => item.id === section.id ? { ...item, position: event.target.value as NominalSection["position"] } : item))} className="h-[27px] w-[90px] rounded-[4px] border border-[#dce3eb] bg-white px-[6px] text-[6.5px]"><option>Di atas</option><option>Di bawah</option></select></td><td><span className="grid size-[27px] place-items-center rounded-[4px] border border-[#dce3eb] bg-white">{index + 1}</span></td><td><Switch enabled={section.active} onToggle={() => onChange(sections.map((item) => item.id === section.id ? { ...item, active: !item.active } : item))} /></td><td><div className="flex gap-[3px]"><IconButton label="Naik" onClick={() => onMove(section.id, -1)}><ArrowUp /></IconButton><IconButton label="Turun" onClick={() => onMove(section.id, 1)}><ArrowDown /></IconButton><IconButton label="Hapus" danger onClick={() => onChange(sections.filter((item) => item.id !== section.id))}><Trash2 /></IconButton></div></td></tr>)}</tbody></table></div>;
}

function NominalEditorModal({ nominal, sections, onClose, onSave }: { nominal: Nominal; sections: NominalSection[]; onClose(): void; onSave(value: Nominal): void }) {
  const [draft, setDraft] = useState(nominal);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!draft.name.trim() || !draft.sku.trim()) { setError("Nama dan SKU tidak boleh kosong."); return; }
    setBusy(true); setError("");
    try {
      let imageUrl = draft.imageUrl;
      if (file) {
        const form = new FormData();
        form.set("file", file);
        const uploaded = await readJson<{ url: string }>(await fetch("/api/panel/media", { method: "POST", body: form }));
        imageUrl = uploaded.url;
      }
      const cost = Math.max(0, Math.round(draft.cost));
      const margin = Math.max(0, Math.round(draft.margin));
      const sell = draft.marginType === "percent" ? Math.ceil(cost * (100 + margin) / 100) : Math.ceil(cost + margin);
      onSave({ ...draft, name: draft.name.trim(), sku: draft.sku.trim(), cost, margin, sell: Math.max(1, sell), imageUrl });
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Nominal gagal diperbarui.");
    } finally { setBusy(false); }
  }

  return <SimpleModal title="Edit Nominal" description="Atur SKU, harga, margin, gambar, dan tabel pemisah." onClose={onClose} wide><form onSubmit={submit} className="grid grid-cols-2 gap-[10px]">
    {error && <p className="col-span-2 rounded-[5px] border border-red-200 bg-red-50 p-[9px] text-[8px] text-red-700">{error}</p>}
    <label className="text-[8px] font-bold text-[#3d4f68]">Nama nominal<input value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label>
    <label className="text-[8px] font-bold text-[#3d4f68]">SKU {draft.provider === "Digiflazz" ? "Digiflazz" : "internal"}<input value={draft.sku} disabled={draft.provider === "Digiflazz"} onChange={(event) => setDraft({ ...draft, sku: event.target.value })} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px] disabled:bg-[#f3f5f8]" /></label>
    <label className="text-[8px] font-bold text-[#3d4f68]">{draft.provider === "Digiflazz" ? "Max Price DigiFlazz" : "Harga modal"}<input type="number" min={draft.provider === "Digiflazz" ? 1 : 0} value={draft.cost} onChange={(event) => setDraft({ ...draft, cost: Number(event.target.value) })} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label>
    <label className="text-[8px] font-bold text-[#3d4f68]">Jenis margin<select value={draft.marginType} onChange={(event) => setDraft({ ...draft, marginType: event.target.value as Nominal["marginType"] })} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]"><option value="percent">Persentase (%)</option><option value="fixed">Nominal (Rp)</option></select></label>
    <label className="text-[8px] font-bold text-[#3d4f68]">Nilai margin<input type="number" min={0} value={draft.margin} onChange={(event) => setDraft({ ...draft, margin: Number(event.target.value) })} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label>
    <label className="text-[8px] font-bold text-[#3d4f68]">Tabel pemisah<select value={draft.group} onChange={(event) => setDraft({ ...draft, group: event.target.value })} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]">{sections.map((section) => <option key={section.id}>{section.name}</option>)}</select></label>
    <label className="col-span-2 text-[8px] font-bold text-[#3d4f68]">URL gambar nominal<input value={draft.imageUrl} onChange={(event) => setDraft({ ...draft, imageUrl: event.target.value })} placeholder="/api/media/..." className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label>
    <label className="col-span-2 text-[8px] font-bold text-[#3d4f68]">Unggah gambar baru<input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => setFile(event.target.files?.[0] || null)} className="mt-[4px] block w-full text-[8px]" /></label>
    <div className="col-span-2 flex justify-between border-t border-[#e4e9ef] pt-[12px]"><span className="text-[8px] text-[#61728b]">{draft.provider === "Digiflazz" ? "Harga jual dari Max Price: " : "Harga jual baru: "} <strong>{formatRupiah(draft.marginType === "percent" ? Math.ceil(draft.cost * (100 + draft.margin) / 100) : Math.ceil(draft.cost + draft.margin))}</strong></span><div className="flex gap-[8px]"><button type="button" onClick={onClose} className="h-[32px] rounded-[4px] border border-[#dce3eb] px-[13px] text-[8px] font-bold">Batal</button><button type="submit" disabled={busy} className="h-[32px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white disabled:opacity-50">{busy ? "Mengunggah..." : "Terapkan"}</button></div></div>
  </form></SimpleModal>;
}

function GlobalMarginModal({ onClose, onApply }: { onClose(): void; onApply(value: { type: "fixed" | "percent"; value: number; target: "digiflazz" | "all" }): void }) {
  const [type, setType] = useState<"fixed" | "percent">("percent");
  const [value, setValue] = useState("10");
  const [target, setTarget] = useState<"digiflazz" | "all">("digiflazz");
  return <SimpleModal title="Atur Margin Massal" description="Terapkan margin ke nominal Digiflazz atau seluruh nominal." onClose={onClose}><div className="grid gap-[11px]"><label className="text-[8px] font-bold text-[#3d4f68]">Target<select value={target} onChange={(event) => setTarget(event.target.value as "digiflazz" | "all")} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]"><option value="digiflazz">Semua nominal Digiflazz</option><option value="all">Semua nominal</option></select></label><label className="text-[8px] font-bold text-[#3d4f68]">Jenis margin<select value={type} onChange={(event) => setType(event.target.value as "fixed" | "percent")} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]"><option value="percent">Persentase (%)</option><option value="fixed">Nominal (Rp)</option></select></label><label className="text-[8px] font-bold text-[#3d4f68]">Nilai margin<input type="number" min={0} value={value} onChange={(event) => setValue(event.target.value)} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label><div className="flex justify-end gap-[8px] border-t border-[#e4e9ef] pt-[12px]"><button type="button" onClick={onClose} className="h-[32px] rounded-[4px] border border-[#dce3eb] px-[13px] text-[8px] font-bold">Batal</button><button type="button" onClick={() => onApply({ type, value: Math.max(0, Number(value) || 0), target })} className="h-[32px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white">Terapkan</button></div></div></SimpleModal>;
}

function StorePreview({ product, nominals, sections, mode, onMode }: { product: Product; nominals: Nominal[]; sections: NominalSection[]; mode: "Mobile" | "Desktop"; onMode(value: "Mobile" | "Desktop"): void }) {
  return <aside className="sticky top-[70px] self-start rounded-[7px] border border-[#dfe6ef] bg-white p-[13px]"><div className="flex items-start justify-between"><div><h2 className="text-[12px] font-extrabold">Preview Tampilan di Toko</h2><p className="mt-[3px] text-[7.5px] text-[#6c7d92]">Berikut adalah preview tampilan produk di sisi pelanggan.</p></div><div className="flex overflow-hidden rounded-[4px] border border-[#dce3eb]">{(["Mobile", "Desktop"] as const).map((item) => <button type="button" key={item} onClick={() => onMode(item)} className={`inline-flex h-[27px] items-center gap-[4px] px-[9px] text-[7px] font-semibold ${mode === item ? "bg-[#0875ed] text-white" : "bg-white text-[#4e6078]"}`}>{item === "Mobile" ? <Smartphone className="size-[10px]" /> : <Monitor className="size-[10px]" />}{item}</button>)}</div></div><div className={`mx-auto mt-[12px] overflow-hidden border-[6px] border-[#101820] bg-[#f4f7fa] shadow-[0_10px_25px_rgba(15,31,55,.2)] ${mode === "Mobile" ? "h-[500px] w-[250px] rounded-[32px]" : "h-[390px] w-full rounded-[12px]"}`}><div className="flex h-[25px] items-center justify-between bg-[#101820] px-[18px] text-[7px] font-bold text-white"><span>15.30</span><span>● ◔ ▰</span></div><div className="flex items-center gap-[7px] border-b bg-white p-[8px]"><ProductImage product={product} /><div><strong className="block text-[8px]">{product.name}</strong><span className="text-[6px] text-[#66778d]">Top up Diamonds, Weekly Pass, dan lainnya</span></div></div><div className="h-[405px] overflow-hidden p-[8px]">{sections.map((section) => { const entries = nominals.filter((item) => item.group === section.name && item.active).slice(0, 2); if (!entries.length) return null; return <div key={section.id} className="mb-[8px]"><h3 className="mb-[5px] text-[9px] font-extrabold">{section.name}{section.name.includes("Special") || section.name.includes("First") ? " ✨" : section.name === "Diamonds" ? " 💎" : ""}</h3><div className="grid grid-cols-2 gap-[6px]">{entries.map((nominal) => <div key={nominal.id} className="min-h-[76px] rounded-[5px] border border-[#e1e7ee] bg-white p-[6px] shadow-sm"><strong className="block truncate text-[6.5px]">{nominal.name}</strong><NominalArtwork kind={nominal.imageKind} imageUrl={nominal.imageUrl} large /><span className="block text-[8px] font-black text-[#e93643]">{formatRupiah(nominal.sell)}</span></div>)}</div></div>; })}</div><div className="absolute"></div></div></aside>;
}

type ProductSettingsValues = {
  name: string;
  slug: string;
  publisher: string;
  description: string;
  imageUrl: string;
  bannerUrl: string;
  category: string;
  isActive: boolean;
  popular: boolean;
  instant: boolean;
  fulfillmentType: "automatic" | "manual";
  manualInstructions: string;
};

function ProductSettingsPanel({ tab, product, values, onChange, uploading, onUpload, saving, onSave }: { tab: Exclude<EditorTab, "Nominal & Harga" | "Tabel Pemisah" | "Input Customer">; product: Product; values: ProductSettingsValues; onChange(key: keyof ProductSettingsValues, value: string | boolean): void; uploading: Record<"imageUrl" | "bannerUrl", boolean>; onUpload(field: "imageUrl" | "bannerUrl", file?: File): void; saving: boolean; onSave(): void }) {
  return <section className="mt-[12px] rounded-[7px] border border-[#dfe6ef] bg-white p-[16px]">
    <div className="flex items-center justify-between border-b border-[#e8ecf1] pb-[11px]"><div><h2 className="text-[13px] font-extrabold">{tab}</h2><p className="mt-[2px] text-[8px] text-[#6c7d92]">Pengaturan {tab.toLowerCase()} untuk {product.name}.</p></div><button type="button" disabled={saving} onClick={onSave} className="inline-flex h-[32px] items-center gap-[6px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white disabled:opacity-50"><Save className="size-[12px]" />{saving ? "Menyimpan..." : "Simpan Perubahan"}</button></div>
    {tab === "Informasi Produk" && <div className="mt-[14px] grid grid-cols-2 gap-[12px]"><ControlledField label="Nama produk" value={values.name} onChange={(value) => onChange("name", value)} /><ControlledField label="Slug" value={values.slug} onChange={(value) => onChange("slug", value)} /><ControlledField label="Publisher" value={values.publisher} onChange={(value) => onChange("publisher", value)} /><label className="text-[8px] font-bold text-[#3d4f68]">Kategori<select value={values.category} onChange={(event) => onChange("category", event.target.value)} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]">{PRODUCT_CATEGORIES.map((item) => <option key={item.slug} value={item.slug}>{item.label}</option>)}</select></label><ProductMediaField label="Gambar produk (opsional, rasio 1:1)" field="imageUrl" value={values.imageUrl} onChange={(value) => onChange("imageUrl", value)} uploading={uploading.imageUrl} onUpload={onUpload} /><ProductMediaField label="Banner halaman produk (opsional)" field="bannerUrl" value={values.bannerUrl} onChange={(value) => onChange("bannerUrl", value)} uploading={uploading.bannerUrl} onUpload={onUpload} /><label className="col-span-2 text-[8px] font-bold text-[#3d4f68]">Deskripsi singkat<textarea value={values.description} onChange={(event) => onChange("description", event.target.value)} className="mt-[4px] h-[74px] w-full resize-none rounded-[4px] border border-[#dce3eb] p-[9px] text-[8px]" /></label></div>}
    {tab === "Tampilan Produk" && <div className="mt-[14px] grid grid-cols-3 gap-[10px]"><SettingSwitch label="Aktif" value={values.isActive} onChange={(value) => onChange("isActive", value)} /><SettingSwitch label="Ditampilkan di katalog" value={values.isActive} onChange={(value) => onChange("isActive", value)} /><SettingSwitch label="Produk populer" value={values.popular} onChange={(value) => onChange("popular", value)} /></div>}
    {tab === "Fulfillment" && <div className="mt-[14px] grid grid-cols-2 gap-[12px]"><label className="text-[8px] font-bold text-[#3d4f68]">Jenis pemenuhan<select value={values.fulfillmentType} onChange={(event) => onChange("fulfillmentType", event.target.value)} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]"><option value="automatic">Otomatis</option><option value="manual">Manual</option></select></label><SettingSwitch label="Proses instan" value={values.instant} onChange={(value) => onChange("instant", value)} /><label className="col-span-2 text-[8px] font-bold text-[#3d4f68]">Instruksi pemenuhan manual<textarea value={values.manualInstructions} onChange={(event) => onChange("manualInstructions", event.target.value)} disabled={values.fulfillmentType !== "manual"} className="mt-[4px] h-[84px] w-full resize-none rounded-[4px] border border-[#dce3eb] p-[9px] text-[8px] disabled:bg-[#f3f5f8]" placeholder="Instruksi internal/admin untuk memproses pesanan" /></label></div>}
  </section>;
}

function ProductMediaField({ label, field, value, onChange, uploading, onUpload }: { label: string; field: "imageUrl" | "bannerUrl"; value: string; onChange(value: string): void; uploading: boolean; onUpload(field: "imageUrl" | "bannerUrl", file?: File): void }) {
  const inputId = `product-${field}-upload`;
  return <div className="text-[8px] font-bold text-[#3d4f68]"><label>{label}<input value={value} onChange={(event) => onChange(event.target.value)} placeholder="URL gambar (opsional)" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px] outline-none placeholder:text-[#929eae] focus:border-[#2580eb]" /></label><label htmlFor={inputId} className="mt-[6px] inline-flex h-[30px] cursor-pointer items-center gap-[6px] rounded-[4px] border border-[#cfd9e5] bg-[#f8fbff] px-[10px] text-[8px] font-bold text-[#0875ed]"><Upload className="size-[11px]" />{uploading ? "Mengunggah..." : "Pilih & Unggah Foto"}<input id={inputId} type="file" accept="image/png,image/jpeg,image/webp,image/gif" className="sr-only" disabled={uploading} onChange={(event) => { const file = event.target.files?.[0]; event.currentTarget.value = ""; onUpload(field, file); }} /></label><small className="mt-[4px] block font-normal text-[#718198]">JPG, PNG, WEBP, GIF · Maks. 6MB</small></div>;
}

function ControlledField({ label, value, onChange, placeholder }: { label: string; value: string; onChange(value: string): void; placeholder?: string }) {
  return <label className="text-[8px] font-bold text-[#3d4f68]">{label}<input value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px] outline-none placeholder:text-[#929eae] focus:border-[#2580eb]" /></label>;
}

function SettingSwitch({ label, value, onChange }: { label: string; value: boolean; onChange(value: boolean): void }) {
  return <label className="flex items-center justify-between rounded-[6px] border border-[#e1e7ee] px-[11px] py-[10px] text-[8px] font-semibold">{label}<Switch enabled={value} onToggle={() => onChange(!value)} /></label>;
}

function EditorTabPanel({ tab, product, targetTemplate, checkoutType, labelId, labelServer, nicknameGameCode, onCheckoutType, onLabelId, onLabelServer, onNicknameGameCode, inputLoading, saving, onSave }: { tab: EditorTab; product: Product; targetTemplate: string; checkoutType: "id" | "id-server"; labelId: string; labelServer: string; nicknameGameCode: string; onCheckoutType(value: "id" | "id-server"): void; onLabelId(value: string): void; onLabelServer(value: string): void; onNicknameGameCode(value: string): void; inputLoading: boolean; saving: boolean; onSave(): void }) {
  return <section className="mt-[12px] rounded-[7px] border border-[#dfe6ef] bg-white p-[16px]"><div className="flex items-center justify-between border-b border-[#e8ecf1] pb-[11px]"><div><h2 className="text-[13px] font-extrabold">{tab}</h2><p className="mt-[2px] text-[8px] text-[#6c7d92]">Pengaturan {tab.toLowerCase()} untuk {product.name}.</p></div><button type="button" disabled={saving || (tab === "Input Customer" && inputLoading)} onClick={onSave} className="inline-flex h-[32px] items-center gap-[6px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white disabled:opacity-50"><Save className="size-[12px]" />{saving ? "Menyimpan..." : "Simpan Perubahan"}</button></div>{tab === "Input Customer" && <div className="mt-[14px] grid max-w-[900px] grid-cols-[minmax(0,1fr)_300px] gap-[14px]"><div className="grid grid-cols-2 gap-[12px]"><label className="col-span-2 text-[8px] font-bold text-[#3d4f68]">Checkout Type<span className="mt-[3px] block font-normal text-[#718197]">Pilih data akun yang harus diisi pelanggan.</span><select disabled={inputLoading} value={checkoutType} onChange={(event) => onCheckoutType(event.target.value as "id" | "id-server")} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] bg-white px-[10px] text-[9px]"><option value="id">ID</option><option value="id-server">ID + Server</option></select></label><label className="text-[8px] font-bold text-[#3d4f68]">Label ID<span className="mt-[3px] block font-normal text-[#718197]">Nama field yang tampil di checkout customer.</span><input disabled={inputLoading} value={labelId} onChange={(event) => onLabelId(event.target.value)} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] px-[10px] text-[9px]" placeholder="Contoh: User ID" /></label><label className="text-[8px] font-bold text-[#3d4f68]">Kode Game Nickname<span className="mt-[3px] block font-normal text-[#718197]">Kosongkan bila produk tidak memakai cek nickname. Ambil kode dari menu Kokinpay.</span><input disabled={inputLoading} value={nicknameGameCode} onChange={(event) => onNicknameGameCode(event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ""))} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] px-[10px] text-[9px]" placeholder="mobile-legends" /></label>{checkoutType === "id-server" && <label className="text-[8px] font-bold text-[#3d4f68]">Label Server<span className="mt-[3px] block font-normal text-[#718197]">Nama field server/zone di checkout customer.</span><input disabled={inputLoading} value={labelServer} onChange={(event) => onLabelServer(event.target.value)} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] px-[10px] text-[9px]" placeholder="Contoh: Zone ID" /></label>}</div><aside className="rounded-[7px] border border-[#dce6f2] bg-[#f8fbff] p-[13px]"><p className="text-[9px] font-extrabold text-[#263b58]">Preview Input Checkout</p>{inputLoading ? <p className="mt-[10px] text-[8px] text-[#718197]">Memuat pengaturan dari backend...</p> : <><label className="mt-[10px] block text-[8px] font-bold text-[#4c6078]">{labelId || "ID"}<input disabled placeholder={`Masukkan ${labelId || "ID"}`} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]" /></label>{checkoutType === "id-server" && <label className="mt-[9px] block text-[8px] font-bold text-[#4c6078]">{labelServer || "Server"}<input disabled placeholder={`Masukkan ${labelServer || "Server"}`} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]" /></label>}</>}<p className="mt-[12px] text-[8px] font-bold text-[#2f4968]">Format customer_no</p><code className="mt-[5px] block rounded-[4px] bg-white px-[9px] py-[8px] text-[8px] text-[#0875ed]">{targetTemplate}</code><p className="mt-[6px] text-[7.5px] leading-4 text-[#718197]">Backend menggabungkan data ini saat mengirim pesanan otomatis, lalu mengunci aturan verifikasi nickname. Staff hanya mengatur jenis dan label input pelanggan.</p></aside></div>}</section>;
}

function ImportNominalModal({ existing, onClose, onImport }: { existing: Nominal[]; onClose(): void; onImport(items: Nominal[]): void }) {
  const [selected, setSelected] = useState<string[]>([]);
  const [step, setStep] = useState(1);
  const [margin, setMargin] = useState(10);
  const [catalog, setCatalog] = useState<DigiflazzCatalogItem[]>([]);
  const [category, setCategory] = useState("");
  const [brand, setBrand] = useState("");
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    const controller = new AbortController();
    void (async () => {
      try {
        const payload = await readJson<{ catalog: DigiflazzCatalogItem[] }>(
          await fetch("/api/panel/digiflazz-pricing?catalog=1", { cache: "no-store", signal: controller.signal }),
        );
        setCatalog(payload.catalog);
        const first = payload.catalog[0];
        if (first) {
          setCategory(first.category);
          setBrand(first.brand);
        }
      } catch (reason) {
        if (reason instanceof DOMException && reason.name === "AbortError") return;
        setError(reason instanceof Error ? reason.message : "Pricelist Digiflazz gagal dimuat.");
      } finally {
        setLoading(false);
      }
    })();
    return () => controller.abort();
  }, []);

  const categories = useMemo(
    () => Array.from(new Set(catalog.map((item) => item.category).filter(Boolean))).sort(),
    [catalog],
  );
  const brands = useMemo(
    () => Array.from(new Set(catalog.filter((item) => !category || item.category === category).map((item) => item.brand).filter(Boolean))).sort(),
    [catalog, category],
  );
  const available = useMemo<DigiflazzImportItem[]>(() => {
    const term = query.trim().toLowerCase();
    return catalog
      .filter((item) =>
        (!category || item.category === category) &&
        (!brand || item.brand === brand) &&
        item.buyerProductStatus &&
        item.sellerProductStatus &&
        !existing.some((nominal) => nominal.sku === item.buyerSkuCode) &&
        (!term || `${item.productName} ${item.buyerSkuCode}`.toLowerCase().includes(term)),
      )
      .map((item) => ({ ...item, sku: item.buyerSkuCode }));
  }, [brand, catalog, category, existing, query]);

  function chooseCategory(value: string) {
    setCategory(value);
    setBrand(catalog.find((item) => item.category === value)?.brand || "");
    setSelected([]);
  }

  function finish() {
    const defaultGroup = existing[0]?.group || "Lainnya";
    onImport(available
      .filter((item) => selected.includes(item.buyerSkuCode))
      .map((item) => ({
        id: crypto.randomUUID(),
        name: item.productName,
        sku: item.sku,
        group: defaultGroup,
        cost: item.price,
        margin,
        marginType: "percent" as const,
        sell: Math.ceil(item.price + item.price * margin / 100),
        active: true,
        imageUrl: "",
        imageKind: item.productName.toLowerCase().includes("pass") ? "weekly" as const : "diamond" as const,
        provider: "Digiflazz" as const,
      })));
  }

  return (
    <SimpleModal title="Import Nominal dari Digiflazz" description="Produk tetap dibuat manual. Hanya nominal terpilih yang diambil dari Digiflazz. Pricelist selalu dimuat langsung dari data aktif." onClose={onClose} wide>
      <div className="mb-[13px] grid grid-cols-4 gap-[8px]">
        {["Pilih Kategori", "Pilih Nominal", "Atur Margin", "Konfirmasi"].map((label, index) => (
          <div key={label} className={`flex items-center gap-[6px] text-[7px] font-semibold ${step === index + 1 ? "text-[#0875ed]" : "text-[#74849a]"}`}>
            <span className={`grid size-[21px] place-items-center rounded-full ${step === index + 1 ? "bg-[#0875ed] text-white" : "bg-[#eef2f6]"}`}>{index + 1}</span>{label}
          </div>
        ))}
      </div>
      {loading && <p className="rounded-[5px] border border-[#dce6f2] bg-[#f8fbff] p-[12px] text-[8px] text-[#52647c]">Mengambil pricelist Digiflazz terbaru...</p>}
      {error && <p className="rounded-[5px] border border-red-200 bg-red-50 p-[12px] text-[8px] text-red-700">{error}</p>}
      {!loading && !error && step === 1 && (
        <div className="grid grid-cols-2 gap-[10px]">
          <label className="text-[8px] font-bold">Kategori Digiflazz<CompactSelect value={category} onChange={chooseCategory} options={categories} /></label>
          <label className="text-[8px] font-bold">Pilih Game / Brand<CompactSelect value={brand} onChange={(value) => { setBrand(value); setSelected([]); }} options={brands} /></label>
        </div>
      )}
      {!loading && !error && step === 2 && (
        <div>
          <label className="relative block"><Search className="absolute left-[9px] top-1/2 size-[12px] -translate-y-1/2 text-[#7b899b]" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari nama atau SKU di Digiflazz..." className="h-[32px] w-full rounded-[4px] border border-[#dce3eb] pl-[28px] text-[8px]" /></label>
          <div className="mt-[8px] max-h-[310px] overflow-auto rounded-[5px] border border-[#e0e6ed]">
            {available.map((item) => <label key={item.buyerSkuCode} className="grid grid-cols-[22px_1fr_75px_80px_55px] items-center border-t border-[#e8ecf1] px-[8px] py-[6px] text-[7px] first:border-0"><input type="checkbox" checked={selected.includes(item.buyerSkuCode)} onChange={() => setSelected((current) => current.includes(item.buyerSkuCode) ? current.filter((sku) => sku !== item.buyerSkuCode) : [...current, item.buyerSkuCode])} /><span>{item.productName}</span><span>{item.buyerSkuCode}</span><span>{formatRupiah(item.price)}</span><span className="rounded bg-[#ddf8e8] px-[6px] py-[3px] text-center font-bold text-[#15955a]">Normal</span></label>)}
            {!available.length && <p className="p-[18px] text-center text-[8px] text-[#718198]">Tidak ada nominal aktif yang cocok atau seluruh SKU sudah diimpor.</p>}
          </div>
        </div>
      )}
      {!loading && !error && step === 3 && <label className="block text-[8px] font-bold">Margin global untuk nominal Digiflazz (%)<input type="number" min={0} value={margin} onChange={(event) => setMargin(Number(event.target.value))} className="mt-[5px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label>}
      {!loading && !error && step === 4 && <div className="rounded-[5px] border border-[#cfe4fa] bg-[#f0f7ff] p-[12px] text-[8px] text-[#415b75]"><strong>{selected.length} nominal dipilih</strong><p className="mt-[4px]">Brand: {brand} · Margin: {margin}% · Produk tidak dibuat otomatis.</p></div>}
      <div className="mt-[15px] flex justify-between">
        <button type="button" onClick={step === 1 ? onClose : () => setStep((value) => value - 1)} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[13px] text-[8px] font-bold">{step === 1 ? "Batal" : "Kembali"}</button>
        <button type="button" disabled={loading || Boolean(error) || (step === 2 && !selected.length)} onClick={step === 4 ? finish : () => setStep((value) => value + 1)} className="h-[32px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white disabled:opacity-50">{step === 4 ? "Import Nominal" : "Lanjut"}</button>
      </div>
    </SimpleModal>
  );
}

function ManualProductModal({ saving, onClose, onSubmit }: { saving: boolean; onClose(): void; onSubmit(event: FormEvent<HTMLFormElement>): void }) {
  return (
    <SimpleModal title="Tambah Produk Manual" description="Semua produk dibuat sendiri. Nominal dapat ditambahkan setelah produk tersimpan." onClose={onClose} wide>
      <form onSubmit={onSubmit}>
        <div className="mb-[12px] flex border-b border-[#e2e7ed]">
          {["Informasi Produk", "Nominal & Harga", "Input Customer", "Fulfillment", "Tampilan"].map((label, index) => <span key={label} className={index === 0 ? "border-b-2 border-[#0875ed] px-[10px] pb-[8px] text-[8px] font-bold text-[#0875ed]" : "px-[10px] pb-[8px] text-[8px] text-[#718197]"}>{label}</span>)}
        </div>
        <div className="grid grid-cols-[120px_1fr_1fr] gap-[12px]">
          <label htmlFor="manual-product-image" className="row-span-3 cursor-pointer text-[8px] font-bold text-[#3d4f68]">
            Gambar produk (opsional, rasio 1:1)
            <span className="mt-[5px] grid h-[110px] place-items-center rounded-[5px] border border-dashed border-[#cfd9e5] bg-[#fafbfd] text-center text-[#0875ed]">
              <span><ImageIcon className="mx-auto size-[24px]" /><small className="mt-[5px] block">Pilih gambar</small></span>
            </span>
            <input id="manual-product-image" name="image" type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" />
            <small className="mt-[5px] block font-normal text-[#7a899c]">PNG, JPG, WEBP · Maks. 2MB</small>
          </label>
          <Field label="Nama Produk *" name="name" placeholder="Contoh: Roblox Robux" required />
          <Field label="Slug *" name="slug" placeholder="contoh: roblox-robux" />
          <label className="text-[8px] font-bold">Kategori *<select name="category" defaultValue="Top Up Game" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]">{PRODUCT_CATEGORY_LABELS.map((label) => <option key={label} value={label}>{label}</option>)}</select></label>
          <label className="text-[8px] font-bold">Provider nominal *<select name="provider" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]"><option>Digiflazz</option><option>Manual</option></select></label>
          <label className="col-span-2 text-[8px] font-bold">Deskripsi singkat<textarea name="description" placeholder="Deskripsi singkat produk..." className="mt-[4px] h-[72px] w-full resize-none rounded-[4px] border border-[#dce3eb] p-[9px] text-[8px]" /></label>
          <label className="col-span-2 text-[8px] font-bold">Banner halaman produk (opsional)<input name="banner" placeholder="Boleh dikosongkan dan ditambahkan nanti" className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label>
        </div>
        <div className="mt-[12px] flex justify-end gap-[8px] border-t border-[#e5e9ef] pt-[12px]">
          <button type="button" onClick={onClose} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[14px] text-[8px] font-bold">Batal</button>
          <button type="submit" disabled={saving} className="h-[32px] rounded-[4px] bg-[#0875ed] px-[15px] text-[8px] font-bold text-white disabled:opacity-50">{saving ? "Menyimpan..." : "Lanjut ke Nominal"}</button>
        </div>
      </form>
    </SimpleModal>
  );
}

function SimpleModal({ title, description, children, onClose, wide }: { title: string; description: string; children: ReactNode; onClose(): void; wide?: boolean }) { return <div className="fixed inset-0 z-50 grid place-items-center bg-[#071426]/55 p-[24px]" role="dialog" aria-modal="true" aria-label={title}><section className={`max-h-[88vh] w-full overflow-auto rounded-[9px] bg-white shadow-2xl ${wide ? "max-w-[720px]" : "max-w-[480px]"}`}><header className="flex items-start justify-between border-b border-[#e3e8ef] px-[16px] py-[13px]"><div><h2 className="text-[14px] font-black text-[#101d35]">{title}</h2><p className="mt-[2px] text-[8px] text-[#6d7d92]">{description}</p></div><button type="button" onClick={onClose} className="grid size-[27px] place-items-center rounded-[4px] text-[#596b82] hover:bg-[#f2f5f8]"><X className="size-[14px]" /></button></header><div className="p-[16px]">{children}</div></section></div>; }

function ModalActions({ onCancel, submit }: { onCancel(): void; submit: string }) { return <div className="col-span-full mt-[5px] flex justify-end gap-[8px] border-t border-[#e5e9ef] pt-[12px]"><button type="button" onClick={onCancel} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[14px] text-[8px] font-bold">Batal</button><button type="submit" className="h-[32px] rounded-[4px] bg-[#0875ed] px-[15px] text-[8px] font-bold text-white">{submit}</button></div>; }
function Field({ label, ...props }: { label: string; name: string; placeholder: string; type?: string; required?: boolean }) { return <label className="text-[8px] font-bold text-[#3d4f68]">{label}<input {...props} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px] outline-none placeholder:text-[#929eae] focus:border-[#2580eb]" /></label>; }
function ActionButton({ children, onClick }: { children: ReactNode; onClick: () => void }) { return <button type="button" onClick={onClick} className="inline-flex h-[31px] items-center gap-[6px] rounded-[4px] border border-[#dce3eb] bg-white px-[11px] text-[7.5px] font-bold text-[#34506d] hover:bg-[#f6f9fc]">{children}</button>; }
function IconButton({ children, label, danger, onClick }: { children: ReactNode; label: string; danger?: boolean; onClick: () => void }) { return <button type="button" aria-label={label} onClick={onClick} className={`grid size-[25px] place-items-center rounded-[4px] border ${danger ? "border-red-100 bg-red-50 text-red-500" : "border-[#dce3eb] bg-white text-[#52657d]"}`}>{children}</button>; }
function CompactSelect({ value, onChange, options }: { value: string; onChange(value: string): void; options: readonly string[] }) { return <select value={value} onChange={(event) => onChange(event.target.value)} className="h-[34px] min-w-0 rounded-[5px] border border-[#dce3eb] bg-white px-[9px] text-[8px] font-medium text-[#40516a] outline-none">{options.map((option) => <option key={option}>{option}</option>)}</select>; }
function PageButton({ children, active }: { children: ReactNode; active?: boolean }) { return <span className={`grid size-[27px] place-items-center rounded-[4px] border text-[8px] font-bold ${active ? "border-[#0875ed] bg-[#0875ed] text-white" : "border-[#dde4ec] bg-white text-[#4c5e76]"}`}>{children}</span>; }
function Box() { return <span className="block size-[13px] rounded-[3px] border border-[#cdd7e2] bg-white" />; }
function Switch({ enabled, onToggle }: { enabled: boolean; onToggle(): void }) { return <button type="button" aria-pressed={enabled} onClick={onToggle} className={`relative h-[17px] w-[31px] rounded-full transition ${enabled ? "bg-[#0875ed]" : "bg-[#cad5e1]"}`}><span className={`absolute top-[2px] size-[13px] rounded-full bg-white shadow transition ${enabled ? "left-[16px]" : "left-[2px]"}`} /></button>; }
function ProductImage({ product, large }: { product: Product; large?: boolean }) { const size = large ? "size-[58px] rounded-[9px]" : "size-[36px] rounded-[6px]"; return product.image ? <img src={product.image} alt="" className={`${size} object-cover shadow-sm`} /> : <span className={`grid ${size} place-items-center bg-gradient-to-br from-[#2186ef] to-[#133a85] font-black text-white`}>{product.name.slice(0, 2).toUpperCase()}</span>; }
function CategoryBadge({ category }: { category: Product["category"] }) { const style = category === "Voucher & Gift Card" ? "bg-[#fff0df] text-[#c46b1b]" : category === "Entertainment" ? "bg-[#eee2ff] text-[#7142b3]" : category === "Pulsa" ? "bg-[#e8f7ee] text-[#168553]" : category === "PLN" ? "bg-[#fff8d9] text-[#a36d00]" : "bg-[#e2f1ff] text-[#1671c5]"; return <span className={`rounded-[4px] px-[7px] py-[4px] text-[6.5px] font-semibold ${style}`}>{category}</span>; }
function NominalArtwork({ kind, imageUrl, large }: { kind: Nominal["imageKind"]; imageUrl?: string; large?: boolean }) { const size = large ? "mx-auto my-[7px] size-[35px] text-[16px]" : "size-[27px] text-[11px]"; if (imageUrl) return <img src={imageUrl} alt="" className={`${size} rounded-[4px] object-cover`} />; const label = kind === "diamond" ? "💎" : kind === "weekly" ? "🎟️" : kind === "double" ? "2X" : "🌙"; return <span className={`grid place-items-center rounded-[4px] bg-gradient-to-br from-[#b7efff] to-[#6d4fe8] font-black text-white ${size}`}>{label}</span>; }

function moveItem<T extends { id: string }>(items: T[], id: string, direction: -1 | 1) { const index = items.findIndex((item) => item.id === id); const target = index + direction; if (index < 0 || target < 0 || target >= items.length) return items; const next = [...items]; [next[index], next[target]] = [next[target], next[index]]; return next; }
function moveBefore<T extends { id: string }>(items: T[], sourceId: string, targetId: string) { const source = items.find((item) => item.id === sourceId); if (!source || sourceId === targetId) return items; const remaining = items.filter((item) => item.id !== sourceId); const target = remaining.findIndex((item) => item.id === targetId); remaining.splice(target < 0 ? remaining.length : target, 0, source); return remaining; }
function slugify(value: string) { return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
function formatRupiah(value: number) { return `Rp ${new Intl.NumberFormat("id-ID").format(value)}`; }