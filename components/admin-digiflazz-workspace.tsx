"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useEffect, useMemo, useState, type FormEvent } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  RefreshCw,
  Search,
  Settings2,
  SlidersHorizontal,
  X,
} from "lucide-react";

type MarginType = "fixed" | "percent";
type Health = "healthy" | "warning" | "critical" | "unknown";

type MonitorItem = {
  packageId: number;
  productName: string;
  packageLabel: string;
  providerSku: string;
  category: string;
  brand: string;
  currentPrice: number | null;
  maxPrice: number | null;
  marginType: MarginType;
  marginValue: number;
  sellingPrice: number;
  blockedByMaxPrice: boolean;
  buyerProductStatus: boolean;
  sellerProductStatus: boolean;
  unlimitedStock: boolean;
  stock: number;
  startCutOff: string | null;
  endCutOff: string | null;
  health: Health;
  alertReason: string | null;
  lastCheckedAt: string | null;
};

type MonitorResponse = {
  items: MonitorItem[];
  summary: { total: number; healthy: number; warning: number; critical: number; unknown: number };
  cache?: { count: number; lastSyncedAt: string | null; lastStartedAt: string | null };
  api?: { ready: boolean; balance: number | null; environment: string | null; reason: string | null };
};

type PricingResponse = {
  settings?: { isAutoSync: boolean };
  cache?: { count: number; lastSyncedAt: string | null };
};

type ProviderOrder = {
  reference_id: string;
  product_name: string;
  package_label: string;
  fulfillment_status: string;
  provider_status: string | null;
  provider_message: string | null;
  provider_code: string | null;
  created_at: string;
};

async function readJson<T>(response: Response): Promise<T> {
  const payload = await response.json().catch(() => ({})) as T & { error?: string };
  if (!response.ok) throw new Error(payload.error || "Permintaan panel gagal diproses.");
  return payload;
}

function formatRupiah(value: number | null | undefined) {
  if (value === null || value === undefined || !Number.isFinite(value)) return "-";
  return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value);
}

function formatDate(value: string | null | undefined) {
  if (!value) return "Belum pernah";
  const parsed = new Date(value.endsWith("Z") || value.includes("+") ? value : `${value}Z`);
  if (Number.isNaN(parsed.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(parsed);
}

function calculateSale(cost: number, type: MarginType, value: number) {
  return type === "percent" ? Math.ceil(cost * (100 + value) / 100) : Math.ceil(cost + value);
}

export function AdminDigiflazzWorkspace() {
  const [data, setData] = useState<MonitorResponse>({
    items: [],
    summary: { total: 0, healthy: 0, warning: 0, critical: 0, unknown: 0 },
  });
  const [orders, setOrders] = useState<ProviderOrder[]>([]);
  const [autoSync, setAutoSync] = useState(true);
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("Semua Kategori");
  const [brand, setBrand] = useState("Semua Subkategori");
  const [health, setHealth] = useState("Semua Status");
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<MonitorItem | null>(null);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");

  async function load() {
    setLoading(true);
    setError("");
    try {
      const [monitor, pricing, orderPayload] = await Promise.all([
        readJson<MonitorResponse>(await fetch("/api/panel/digiflazz-monitor", { cache: "no-store" })),
        readJson<PricingResponse>(await fetch("/api/panel/digiflazz-pricing", { cache: "no-store" })),
        readJson<{ orders?: ProviderOrder[] }>(await fetch("/api/panel/orders", { cache: "no-store" })),
      ]);
      setData(monitor);
      setAutoSync(pricing.settings?.isAutoSync !== false);
      setOrders((orderPayload.orders || []).filter((item) => item.provider_code === "digiflazz").slice(0, 8));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Data Digiflazz gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { void load(); }, []);

  const categories = useMemo(
    () => Array.from(new Set(data.items.map((item) => item.category).filter(Boolean))).sort((a, b) => a.localeCompare(b)),
    [data.items],
  );
  const brands = useMemo(
    () => Array.from(new Set(data.items
      .filter((item) => category === "Semua Kategori" || item.category === category)
      .map((item) => item.brand)
      .filter(Boolean)))
      .sort((a, b) => a.localeCompare(b)),
    [category, data.items],
  );
  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return data.items.filter((item) =>
      (!term || `${item.productName} ${item.packageLabel} ${item.providerSku} ${item.category} ${item.brand}`.toLowerCase().includes(term)) &&
      (category === "Semua Kategori" || item.category === category) &&
      (brand === "Semua Subkategori" || item.brand === brand) &&
      (health === "Semua Status" || item.health === health),
    );
  }, [brand, category, data.items, health, query]);

  const pageSize = 30;
  const pages = Math.max(1, Math.ceil(visible.length / pageSize));
  const activePage = Math.min(page, pages);
  const rows = visible.slice((activePage - 1) * pageSize, activePage * pageSize);

  async function syncNow() {
    setSyncing(true);
    setError("");
    setNotice("");
    try {
      const result = await readJson<{ message?: string }>(await fetch("/api/panel/digiflazz-monitor", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: "{}",
      }));
      setNotice(result.message || "Pricelist dan harga modal berhasil disinkronkan.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Sync pricelist gagal.");
    } finally {
      setSyncing(false);
    }
  }

  async function toggleAutoSync() {
    const next = !autoSync;
    setError("");
    try {
      await readJson(await fetch("/api/panel/digiflazz-pricing", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ isAutoSync: next }),
      }));
      setAutoSync(next);
      setNotice(`Auto Sync ${next ? "diaktifkan" : "dinonaktifkan"}.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan Auto Sync gagal disimpan.");
    }
  }

  function chooseCategory(value: string) {
    setCategory(value);
    setBrand("Semua Subkategori");
    setPage(1);
  }

  async function savePricing(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editing) return;
    const form = new FormData(event.currentTarget);
    const maxPrice = Number(form.get("maxPrice"));
    const marginType = String(form.get("marginType")) as MarginType;
    const marginValue = Number(form.get("marginValue"));
    if (!Number.isInteger(maxPrice) || maxPrice < 1 || !Number.isInteger(marginValue) || marginValue < 0) {
      setError("Max Price dan margin harus berupa angka yang valid.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      const payload = await readJson<{ pricing: { sellingPrice: number; blockedByMaxPrice: boolean } }>(await fetch("/api/panel/digiflazz-pricing", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ packageId: editing.packageId, maxPrice, marginType, marginValue }),
      }));
      setNotice(payload.pricing.blockedByMaxPrice
        ? `${editing.productName} - ${editing.packageLabel}: harga provider sudah melewati Max Price dan otomatis diblokir.`
        : `${editing.productName} - ${editing.packageLabel}: Price Control LFAMILIA disimpan. Harga jual ${formatRupiah(payload.pricing.sellingPrice)}.`);
      setEditing(null);
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Price Control gagal disimpan.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="admin-digiflazz-reference min-w-0 text-[#14213a]">
      <header className="flex flex-wrap items-start justify-between gap-[12px]">
        <div>
          <h1 className="text-[24px] font-black tracking-[-0.04em] text-[#0c1933]">Digiflazz</h1>
          <p className="mt-[3px] text-[10px] text-[#62748c]">Pusat operasional provider dan Price Control LFAMILIA.</p>
        </div>
        <div className="flex items-center gap-[8px]">
          <button type="button" onClick={() => void toggleAutoSync()} className={`h-[34px] rounded-[5px] border px-[12px] text-[8px] font-bold ${autoSync ? "border-emerald-200 bg-emerald-50 text-emerald-700" : "border-[#dce3eb] bg-white text-[#55667c]"}`}>Auto Sync: {autoSync ? "ON" : "OFF"}</button>
          <button type="button" disabled={syncing} onClick={() => void syncNow()} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white disabled:opacity-50"><RefreshCw className={`size-[12px] ${syncing ? "animate-spin" : ""}`} />{syncing ? "Sinkron..." : "Sync Pricelist"}</button>
          <button type="button" disabled={loading} onClick={() => void load()} className="inline-flex h-[34px] items-center gap-[6px] rounded-[5px] border border-[#dce3eb] bg-white px-[12px] text-[8px] font-bold text-[#40516a]"><RefreshCw className={`size-[12px] ${loading ? "animate-spin" : ""}`} />Refresh</button>
        </div>
      </header>

      {notice && <button type="button" onClick={() => setNotice("")} className="mt-[9px] flex w-full items-center justify-between rounded-[5px] border border-[#bce3ce] bg-[#eef9f3] px-[11px] py-[7px] text-left text-[8px] font-semibold text-[#158755]"><span>{notice}</span><X className="size-[11px]" /></button>}
      {error && <button type="button" onClick={() => setError("")} className="mt-[9px] w-full rounded-[5px] border border-red-200 bg-red-50 px-[11px] py-[7px] text-left text-[8px] text-red-700">{error}</button>}

      <section className="mt-[12px] overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]">
        <div className="grid grid-cols-2 border-b border-[#e4e9ef] sm:grid-cols-3 lg:grid-cols-6">
          <Stat label="Status API" value={data.api?.ready ? "Online" : "Belum Siap"} note={data.api?.reason || data.api?.environment || "-"} />
          <Stat label="Saldo" value={data.api?.balance === null || data.api?.balance === undefined ? "-" : formatRupiah(data.api.balance)} note="Akun provider" />
          <Stat label="SKU Terhubung" value={String(data.summary.total)} note={`${data.summary.healthy} normal`} />
          <Stat label="Diblokir / Kritis" value={String(data.summary.critical)} note="Termasuk Max Price" danger={data.summary.critical > 0} />
          <Stat label="Peringatan" value={String(data.summary.warning)} note="Stok / cutoff / harga" danger={data.summary.warning > 0} />
          <Stat label="Sync Terakhir" value={formatDate(data.cache?.lastSyncedAt)} note={`${data.cache?.count ?? 0} SKU di cache`} />
        </div>

        <div className="flex flex-wrap items-end justify-between gap-[10px] border-b border-[#e4e9ef] px-[14px] py-[11px]">
          <div>
            <div className="flex items-center gap-[7px]"><SlidersHorizontal className="size-[14px] text-[#0875ed]" /><h2 className="text-[13px] font-extrabold">Price Control LFAMILIA</h2></div>
            <p className="mt-[2px] text-[8px] text-[#687a91]">Harga Digiflazz = modal aktual · Max Price = batas aman · Margin = keuntungan · Harga Jual = modal aktual + margin.</p>
          </div>
          <span className="rounded-[4px] bg-[#eef6ff] px-[8px] py-[5px] text-[7px] font-bold text-[#0875df]">Kategori → Subkategori / Brand → SKU</span>
        </div>

        <div className="grid grid-cols-1 gap-[7px] border-b border-[#e8edf3] px-[14px] py-[10px] md:grid-cols-[1.4fr_.75fr_.75fr_.65fr]">
          <label className="relative"><Search className="absolute left-[9px] top-1/2 size-[12px] -translate-y-1/2 text-[#74849a]" /><input value={query} onChange={(event) => { setQuery(event.target.value); setPage(1); }} placeholder="Cari produk, nominal, SKU, kategori..." className="h-[32px] w-full rounded-[4px] border border-[#dce3eb] pl-[28px] pr-[8px] text-[8px] outline-none focus:border-[#2680eb]" /></label>
          <select value={category} onChange={(event) => chooseCategory(event.target.value)} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[8px] text-[8px]"><option>Semua Kategori</option>{categories.map((item) => <option key={item}>{item}</option>)}</select>
          <select value={brand} onChange={(event) => { setBrand(event.target.value); setPage(1); }} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[8px] text-[8px]"><option>Semua Subkategori</option>{brands.map((item) => <option key={item}>{item}</option>)}</select>
          <select value={health} onChange={(event) => { setHealth(event.target.value); setPage(1); }} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[8px] text-[8px]"><option>Semua Status</option><option value="healthy">Normal</option><option value="warning">Peringatan</option><option value="critical">Kritis / Diblokir</option><option value="unknown">Belum Dicek</option></select>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[1250px] table-fixed text-left">
            <thead className="bg-[#f4f7fa] text-[7px] font-bold uppercase tracking-[.02em] text-[#58697f]"><tr><th className="w-[95px] px-[10px] py-[9px]">Kategori</th><th className="w-[125px]">Subkategori</th><th className="w-[130px]">Produk</th><th className="w-[150px]">Nominal</th><th className="w-[105px]">SKU</th><th className="w-[105px]">Harga Digiflazz</th><th className="w-[110px]">Max Price LFAMILIA</th><th className="w-[90px]">Margin</th><th className="w-[105px]">Harga Jual</th><th className="w-[100px]">Status</th><th className="w-[70px]">Aksi</th></tr></thead>
            <tbody>
              {rows.map((item) => <tr key={item.packageId} className="border-t border-[#e7ebf0] text-[7.5px] text-[#35475f] hover:bg-[#fafcfe]"><td className="px-[10px] py-[8px] font-semibold">{item.category}</td><td className="truncate pr-[8px] font-semibold text-[#213752]">{item.brand}</td><td className="truncate pr-[8px]">{item.productName}</td><td className="truncate pr-[8px]">{item.packageLabel}</td><td className="truncate font-mono text-[7px]">{item.providerSku}</td><td className="font-semibold">{formatRupiah(item.currentPrice)}</td><td className={item.blockedByMaxPrice ? "font-bold text-red-600" : "font-semibold"}>{formatRupiah(item.maxPrice)}</td><td>{item.marginType === "percent" ? `${item.marginValue}%` : formatRupiah(item.marginValue)}</td><td className="font-bold text-[#0c6fd4]">{formatRupiah(item.sellingPrice)}</td><td><Status item={item} /></td><td><button type="button" onClick={() => setEditing(item)} className="inline-flex h-[27px] items-center gap-[5px] rounded-[4px] border border-[#cfe0f2] bg-white px-[8px] font-bold text-[#0875df]"><Settings2 className="size-[10px]" />Atur</button></td></tr>)}
              {!loading && !rows.length && <tr><td colSpan={11} className="py-[28px] text-center text-[8px] text-[#728198]">Tidak ada SKU yang cocok dengan filter.</td></tr>}
              {loading && <tr><td colSpan={11} className="py-[28px] text-center text-[8px] text-[#728198]">Memuat Price Control Digiflazz...</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between border-t border-[#e4e9ef] px-[14px] py-[9px] text-[7.5px] text-[#5e6f84]"><span>{visible.length ? (activePage - 1) * pageSize + 1 : 0}–{Math.min(activePage * pageSize, visible.length)} dari {visible.length} SKU</span><div className="flex items-center gap-[5px]"><button type="button" disabled={activePage <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))} className="h-[27px] rounded-[4px] border border-[#dce3eb] px-[8px] disabled:opacity-40">Sebelumnya</button><span className="grid h-[27px] min-w-[27px] place-items-center rounded-[4px] bg-[#0875ed] px-[7px] font-bold text-white">{activePage}/{pages}</span><button type="button" disabled={activePage >= pages} onClick={() => setPage((value) => Math.min(pages, value + 1))} className="h-[27px] rounded-[4px] border border-[#dce3eb] px-[8px] disabled:opacity-40">Berikutnya</button></div></div>

        <div className="border-t border-[#e4e9ef] px-[14px] py-[11px]"><h3 className="text-[11px] font-extrabold">Status Sinkronisasi & Transaksi Provider Terbaru</h3><p className="mt-[2px] text-[7.5px] text-[#6d7d91]">Ringkasan operasional tetap berada dalam satu workspace Digiflazz.</p></div>
        <div className="overflow-x-auto border-t border-[#edf0f4]"><table className="w-full min-w-[760px] text-left text-[7.5px]"><thead className="bg-[#fafbfd] text-[7px] font-bold text-[#5f7084]"><tr><th className="px-[12px] py-[8px]">Invoice</th><th>Produk</th><th>Nominal</th><th>Status</th><th>Waktu</th><th>Catatan</th></tr></thead><tbody>{orders.map((item) => <tr key={item.reference_id} className="border-t border-[#edf0f4]"><td className="px-[12px] py-[8px] font-mono">{item.reference_id}</td><td>{item.product_name}</td><td>{item.package_label}</td><td>{item.fulfillment_status}</td><td>{formatDate(item.created_at)}</td><td className="max-w-[220px] truncate pr-[12px]">{item.provider_message || item.provider_status || "-"}</td></tr>)}{!orders.length && <tr><td colSpan={6} className="py-[18px] text-center text-[#7b8999]">Belum ada transaksi provider terbaru.</td></tr>}</tbody></table></div>
      </section>

      {editing && <PricingDialog item={editing} saving={saving} onClose={() => setEditing(null)} onSubmit={savePricing} />}
    </div>
  );
}

function Stat({ label, value, note, danger }: { label: string; value: string; note: string; danger?: boolean }) {
  return <div className="min-w-0 border-r border-t border-[#edf0f4] px-[12px] py-[10px] first:border-t-0 sm:border-t-0"><p className="text-[6.5px] font-bold uppercase tracking-[.04em] text-[#758398]">{label}</p><strong className={`mt-[3px] block truncate text-[11px] ${danger ? "text-red-600" : "text-[#142842]"}`}>{value}</strong><span className="mt-[1px] block truncate text-[6.5px] text-[#7a899b]">{note}</span></div>;
}

function Status({ item }: { item: MonitorItem }) {
  if (item.blockedByMaxPrice) return <span title={item.alertReason || undefined} className="inline-flex items-center gap-[4px] rounded-[4px] bg-red-50 px-[6px] py-[4px] font-bold text-red-600"><AlertTriangle className="size-[9px]" />Max Price</span>;
  if (item.health === "critical") return <span title={item.alertReason || undefined} className="inline-flex items-center gap-[4px] rounded-[4px] bg-red-50 px-[6px] py-[4px] font-bold text-red-600"><AlertTriangle className="size-[9px]" />Kritis</span>;
  if (item.health === "warning") return <span title={item.alertReason || undefined} className="inline-flex items-center gap-[4px] rounded-[4px] bg-amber-50 px-[6px] py-[4px] font-bold text-amber-700"><AlertTriangle className="size-[9px]" />Peringatan</span>;
  if (item.health === "healthy") return <span className="inline-flex items-center gap-[4px] rounded-[4px] bg-emerald-50 px-[6px] py-[4px] font-bold text-emerald-700"><CheckCircle2 className="size-[9px]" />Normal</span>;
  return <span className="rounded-[4px] bg-slate-100 px-[6px] py-[4px] font-bold text-slate-600">Belum Dicek</span>;
}

function PricingDialog({ item, saving, onClose, onSubmit }: { item: MonitorItem; saving: boolean; onClose(): void; onSubmit(event: FormEvent<HTMLFormElement>): void }) {
  const [maxPrice, setMaxPrice] = useState(item.maxPrice ?? item.currentPrice ?? 1);
  const [marginType, setMarginType] = useState<MarginType>(item.marginType);
  const [marginValue, setMarginValue] = useState(item.marginValue);
  const currentCost = item.currentPrice ?? 0;
  const sale = currentCost > 0 ? calculateSale(currentCost, marginType, marginValue) : 0;
  const blocked = currentCost > 0 && maxPrice > 0 && currentCost > maxPrice;
  return <div className="fixed inset-0 z-50 grid place-items-center bg-[#071426]/55 p-[20px]" role="dialog" aria-modal="true" aria-label="Atur Price Control LFAMILIA"><form onSubmit={onSubmit} className="w-full max-w-[520px] overflow-hidden rounded-[8px] bg-white shadow-2xl"><header className="flex items-start justify-between border-b border-[#e3e8ef] px-[15px] py-[12px]"><div><h2 className="text-[14px] font-black text-[#101d35]">Atur Price Control LFAMILIA</h2><p className="mt-[2px] text-[8px] text-[#6d7d92]">{item.brand} · {item.productName} · {item.packageLabel}</p></div><button type="button" onClick={onClose} className="grid size-[27px] place-items-center"><X className="size-[14px]" /></button></header><div className="p-[15px]"><div className="grid grid-cols-2 gap-[8px] rounded-[6px] border border-[#e0e7ef] bg-[#f8fafc] p-[10px]"><PriceInfo label="Harga Digiflazz sekarang" value={formatRupiah(item.currentPrice)} /><PriceInfo label="Harga Jual saat ini" value={formatRupiah(item.sellingPrice)} /></div><div className="mt-[12px] grid grid-cols-2 gap-[10px]"><label className="col-span-2 text-[8px] font-bold text-[#3e5068]">Max Price LFAMILIA<input name="maxPrice" type="number" min={1} required value={maxPrice} onChange={(event) => setMaxPrice(Number(event.target.value))} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /><small className="mt-[4px] block font-normal text-[#738296]">Jika harga Digiflazz melewati angka ini, SKU otomatis diblokir dari checkout/fulfillment.</small></label><label className="text-[8px] font-bold text-[#3e5068]">Tipe Margin<select name="marginType" value={marginType} onChange={(event) => setMarginType(event.target.value as MarginType)} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] bg-white px-[9px] text-[8px]"><option value="fixed">Rupiah</option><option value="percent">Persen</option></select></label><label className="text-[8px] font-bold text-[#3e5068]">Nilai Margin<input name="marginValue" type="number" min={0} required value={marginValue} onChange={(event) => setMarginValue(Number(event.target.value))} className="mt-[4px] h-[34px] w-full rounded-[4px] border border-[#dce3eb] px-[9px] text-[8px]" /></label></div><div className={`mt-[12px] rounded-[5px] border px-[10px] py-[8px] text-[8px] ${blocked ? "border-red-200 bg-red-50 text-red-700" : "border-[#cfe4fa] bg-[#f0f7ff] text-[#355b7f]"}`}><strong>Preview:</strong> Harga jual = {formatRupiah(sale)}. {blocked ? "Harga provider saat ini sudah melewati Max Price; SKU akan diblokir." : "Max Price hanya batas pengaman, bukan dasar harga jual."}</div></div><footer className="flex justify-end gap-[8px] border-t border-[#e5e9ef] px-[15px] py-[11px]"><button type="button" onClick={onClose} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[13px] text-[8px] font-bold">Batal</button><button type="submit" disabled={saving} className="h-[32px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white disabled:opacity-50">{saving ? "Menyimpan..." : "Simpan Price Control"}</button></footer></form></div>;
}

function PriceInfo({ label, value }: { label: string; value: string }) {
  return <div><p className="text-[7px] text-[#718196]">{label}</p><strong className="mt-[2px] block text-[10px] text-[#18324f]">{value}</strong></div>;
}
