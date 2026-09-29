"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  RefreshCw,
  Search,
  X,
} from "lucide-react";

type Health = "healthy" | "warning" | "critical" | "unknown";

type MonitorItem = {
  packageId: number;
  productName: string;
  packageLabel: string;
  providerSku: string;
  category: string;
  brand: string;
  currentPrice: number | null;
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

export function AdminDigiflazzWorkspace() {
  const [data, setData] = useState<MonitorResponse>({
    items: [],
    summary: { total: 0, healthy: 0, warning: 0, critical: 0, unknown: 0 },
  });
  const [orders, setOrders] = useState<ProviderOrder[]>([]);
  const [autoSync, setAutoSync] = useState(true);
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("Semua Kategori");
  const [brand, setBrand] = useState("Semua Produk / Brand");
  const [health, setHealth] = useState("Semua Status");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");

  async function load() {
    setLoading(true);
    setError("");
    try {
      const [monitor, pricing, orderPayload] = await Promise.all([
        readJson<MonitorResponse>(await fetch("/api/admin/digiflazz-monitor", { cache: "no-store" })),
        readJson<PricingResponse>(await fetch("/api/admin/digiflazz-pricing", { cache: "no-store" })),
        readJson<{ orders?: ProviderOrder[] }>(await fetch("/api/admin/orders", { cache: "no-store" })),
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
    const nominalNumber = (label: string) => {
      const match = label.match(/\d[\d.,]*/);
      return match ? Number(match[0].replace(/\D/g, "")) : Number.MAX_SAFE_INTEGER;
    };
    return data.items.filter((item) =>
      (!term || `${item.productName} ${item.packageLabel} ${item.providerSku} ${item.category} ${item.brand}`.toLowerCase().includes(term)) &&
      (category === "Semua Kategori" || item.category === category) &&
      (brand === "Semua Subkategori" || item.brand === brand) &&
      (health === "Semua Status" || item.health === health),
    ).sort((left, right) =>
      left.productName.localeCompare(right.productName, "id", { numeric: true, sensitivity: "base" }) ||
      nominalNumber(left.packageLabel) - nominalNumber(right.packageLabel) ||
      left.packageLabel.localeCompare(right.packageLabel, "id", { numeric: true, sensitivity: "base" }),
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
      const result = await readJson<{ message?: string }>(await fetch("/api/admin/digiflazz-monitor", {
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
      await readJson(await fetch("/api/admin/digiflazz-pricing", {
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
    setBrand("Semua Produk / Brand");
    setPage(1);
  }

  return (
    <div className="admin-digiflazz-reference min-w-0 text-[#14213a]">
      <header className="flex flex-wrap items-start justify-between gap-[12px]">
        <div>
          <h1 className="text-[24px] font-black tracking-[-0.04em] text-[#0c1933]">Digiflazz</h1>
          <p className="mt-[3px] text-[10px] text-[#62748c]">Pusat operasional, pricelist, stok, cutoff, dan status DigiFlazz.</p>
        </div>
        <div className="flex w-full flex-wrap items-center gap-[8px] sm:w-auto">
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
          <Stat label="Kritis" value={String(data.summary.critical)} note="Seller / stok" danger={data.summary.critical > 0} />
          <Stat label="Peringatan" value={String(data.summary.warning)} note="Stok / cutoff / harga" danger={data.summary.warning > 0} />
          <Stat label="Sync Terakhir" value={formatDate(data.cache?.lastSyncedAt)} note={`${data.cache?.count ?? 0} SKU di cache`} />
        </div>

        <div className="flex flex-wrap items-end justify-between gap-[10px] border-b border-[#e4e9ef] px-[14px] py-[11px]">
          <div>
            <h2 className="text-[13px] font-extrabold">Pricelist DigiFlazz</h2>
            <p className="mt-[2px] text-[8px] text-[#687a91]">Harga DigiFlazz adalah modal aktual hasil sinkronisasi. Harga jual dan margin dikelola pada menu Produk, bukan di panel ini.</p>
          </div>
          <span className="rounded-[4px] bg-[#eef6ff] px-[8px] py-[5px] text-[7px] font-bold text-[#0875df]">Kategori → Produk → Nominal</span>
        </div>

        <div className="grid grid-cols-1 gap-[7px] border-b border-[#e8edf3] px-[14px] py-[10px] md:grid-cols-[1.4fr_.75fr_.75fr_.65fr]">
          <label className="relative"><Search className="absolute left-[9px] top-1/2 size-[12px] -translate-y-1/2 text-[#74849a]" /><input value={query} onChange={(event) => { setQuery(event.target.value); setPage(1); }} placeholder="Cari produk, nominal, SKU, kategori..." className="h-[32px] w-full rounded-[4px] border border-[#dce3eb] pl-[28px] pr-[8px] text-[8px] outline-none focus:border-[#2680eb]" /></label>
          <select value={category} onChange={(event) => chooseCategory(event.target.value)} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[8px] text-[8px]"><option>Semua Kategori</option>{categories.map((item) => <option key={item}>{item}</option>)}</select>
          <select value={brand} onChange={(event) => { setBrand(event.target.value); setPage(1); }} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[8px] text-[8px]"><option>Semua Subkategori</option>{brands.map((item) => <option key={item}>{item}</option>)}</select>
          <select value={health} onChange={(event) => { setHealth(event.target.value); setPage(1); }} className="h-[32px] rounded-[4px] border border-[#dce3eb] bg-white px-[8px] text-[8px]"><option>Semua Status</option><option value="healthy">Normal</option><option value="warning">Peringatan</option><option value="critical">Kritis</option><option value="unknown">Belum Dicek</option></select>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[890px] table-fixed text-left">
            <thead className="bg-[#f4f7fa] text-[7px] font-bold uppercase tracking-[.02em] text-[#58697f]"><tr><th className="w-[110px] px-[10px] py-[9px]">Kategori</th><th className="w-[180px]">Produk</th><th className="w-[180px]">Nominal</th><th className="w-[125px]">SKU</th><th className="w-[135px]">Harga DigiFlazz</th><th className="w-[120px]">Stok</th><th className="w-[120px]">Status</th></tr></thead>
            <tbody>
              {rows.map((item) => <tr key={item.packageId} className="border-t border-[#e7ebf0] text-[7.5px] text-[#35475f] hover:bg-[#fafcfe]"><td className="px-[10px] py-[8px] font-semibold">{item.category}</td><td className="truncate pr-[8px] font-semibold text-[#213752]">{item.productName}</td><td className="truncate pr-[8px] font-bold">{item.packageLabel}</td><td className="truncate font-mono text-[7px]">{item.providerSku}</td><td className="font-semibold">{formatRupiah(item.currentPrice)}</td><td>{item.unlimitedStock ? "Tidak terbatas" : item.stock.toLocaleString("id-ID")}</td><td><Status item={item} /></td></tr>)}
              {!loading && !rows.length && <tr><td colSpan={7} className="py-[28px] text-center text-[8px] text-[#728198]">Tidak ada SKU yang cocok dengan filter.</td></tr>}
              {loading && <tr><td colSpan={7} className="py-[28px] text-center text-[8px] text-[#728198]">Memuat pricelist DigiFlazz...</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between border-t border-[#e4e9ef] px-[14px] py-[9px] text-[7.5px] text-[#5e6f84]"><span>{visible.length ? (activePage - 1) * pageSize + 1 : 0}–{Math.min(activePage * pageSize, visible.length)} dari {visible.length} SKU</span><div className="flex items-center gap-[5px]"><button type="button" disabled={activePage <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))} className="h-[27px] rounded-[4px] border border-[#dce3eb] px-[8px] disabled:opacity-40">Sebelumnya</button><span className="grid h-[27px] min-w-[27px] place-items-center rounded-[4px] bg-[#0875ed] px-[7px] font-bold text-white">{activePage}/{pages}</span><button type="button" disabled={activePage >= pages} onClick={() => setPage((value) => Math.min(pages, value + 1))} className="h-[27px] rounded-[4px] border border-[#dce3eb] px-[8px] disabled:opacity-40">Berikutnya</button></div></div>

        <div className="border-t border-[#e4e9ef] px-[14px] py-[11px]"><h3 className="text-[11px] font-extrabold">Status Sinkronisasi & Transaksi Provider Terbaru</h3><p className="mt-[2px] text-[7.5px] text-[#6d7d91]">Ringkasan operasional tetap berada dalam satu workspace Digiflazz.</p></div>
        <div className="overflow-x-auto border-t border-[#edf0f4]"><table className="w-full min-w-[760px] text-left text-[7.5px]"><thead className="bg-[#fafbfd] text-[7px] font-bold text-[#5f7084]"><tr><th className="px-[12px] py-[8px]">Invoice</th><th>Produk</th><th>Nominal</th><th>Status</th><th>Waktu</th><th>Catatan</th></tr></thead><tbody>{orders.map((item) => <tr key={item.reference_id} className="border-t border-[#edf0f4]"><td className="px-[12px] py-[8px] font-mono">{item.reference_id}</td><td>{item.product_name}</td><td>{item.package_label}</td><td>{item.fulfillment_status}</td><td>{formatDate(item.created_at)}</td><td className="max-w-[220px] truncate pr-[12px]">{item.provider_message || item.provider_status || "-"}</td></tr>)}{!orders.length && <tr><td colSpan={6} className="py-[18px] text-center text-[#7b8999]">Belum ada transaksi provider terbaru.</td></tr>}</tbody></table></div>
      </section>
}
    </div>
  );
}

function Stat({ label, value, note, danger }: { label: string; value: string; note: string; danger?: boolean }) {
  return <div className="min-w-0 border-r border-t border-[#edf0f4] px-[12px] py-[10px] first:border-t-0 sm:border-t-0"><p className="text-[6.5px] font-bold uppercase tracking-[.04em] text-[#758398]">{label}</p><strong className={`mt-[3px] block truncate text-[11px] ${danger ? "text-red-600" : "text-[#142842]"}`}>{value}</strong><span className="mt-[1px] block truncate text-[6.5px] text-[#7a899b]">{note}</span></div>;
}

function Status({ item }: { item: MonitorItem }) {
  if (item.health === "critical") return <span title={item.alertReason || undefined} className="inline-flex items-center gap-[4px] rounded-[4px] bg-red-50 px-[6px] py-[4px] font-bold text-red-600"><AlertTriangle className="size-[9px]" />Kritis</span>;
  if (item.health === "warning") return <span title={item.alertReason || undefined} className="inline-flex items-center gap-[4px] rounded-[4px] bg-amber-50 px-[6px] py-[4px] font-bold text-amber-700"><AlertTriangle className="size-[9px]" />Peringatan</span>;
  if (item.health === "healthy") return <span className="inline-flex items-center gap-[4px] rounded-[4px] bg-emerald-50 px-[6px] py-[4px] font-bold text-emerald-700"><CheckCircle2 className="size-[9px]" />Normal</span>;
  return <span className="rounded-[4px] bg-slate-100 px-[6px] py-[4px] font-bold text-slate-600">Belum Dicek</span>;
}
