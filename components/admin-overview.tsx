"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  AlertCircle,
  ArrowUpRight,
  Box,
  CalendarDays,
  CheckCircle2,
  CircleDollarSign,
  Clock3,
  CloudCog,
  CreditCard,
  Database,
  FileClock,
  LoaderCircle,
  PackagePlus,
  RefreshCw,
  RotateCw,
  ShoppingCart,
  TrendingUp,
  WalletCards,
  XCircle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { formatRupiah } from "@/lib/store-data";

type DashboardRange = "today" | "7d" | "30d" | "90d" | "all";
type ChartMetric = "revenue" | "profit" | "orders";

type TrendPoint = {
  day: string;
  orders: number;
  revenue: number | null;
  profit: number | null;
};

type RankedProduct = {
  slug: string;
  name: string;
  totalOrders: number;
  fulfilledOrders: number;
  revenue: number | null;
  profit: number | null;
};

type RecentActivity = {
  id: string;
  adminName: string;
  adminRole: string;
  action: string;
  target: string;
  createdAt: string;
};

type RecentOrder = {
  id: string;
  referenceId: string;
  productName: string;
  packageLabel: string;
  buyerName: string;
  paymentMethod: string;
  paymentChannel: string;
  paymentStatus: string;
  fulfillmentStatus: string;
  total: number | null;
  createdAt: string;
};

type Summary = {
  range: DashboardRange;
  canViewFinance: boolean;
  metrics: {
    totalOrders: number;
    paidRevenue: number | null;
    profit: number | null;
    totalDiscount: number | null;
    approvedTopups: number | null;
    activeProducts: number;
    customers: number;
    fulfilledOrders: number;
    pendingPayments: number;
    pendingFulfillments: number;
    failedOrders: number;
  };
  todayMetrics: {
    paidRevenue: number | null;
    profit: number | null;
    totalOrders: number;
    pendingPayments: number;
    failedOrders: number;
    activeProducts: number;
  };
  chart: TrendPoint[];
  topProducts: RankedProduct[];
  integrations: {
    doku: { ready: boolean; environment: string | null; reason: string | null };
    digiflazz: {
      ready: boolean;
      environment: string | null;
      reason: string | null;
      balance: number | null;
      issues: number;
      lastSyncAt: string | null;
    };
    webhook: { ready: boolean; baseUrl: string | null };
  };
  attention: {
    sellerOff: number;
    outOfStock: number;
    priceChanged: number;
    digiflazzPending: number;
    paymentCallbackFailed: number;
    manualPending: number;
  };
  recentActivities: RecentActivity[];
  recentOrders: RecentOrder[];
};

const ranges: Array<{ value: DashboardRange; label: string }> = [
  { value: "today", label: "Hari Ini" },
  { value: "7d", label: "7 Hari Terakhir" },
  { value: "30d", label: "30 Hari Terakhir" },
  { value: "90d", label: "90 Hari Terakhir" },
  { value: "all", label: "Semua Waktu" },
];

export function AdminOverview({ onNavigate }: { onNavigate?: (value: string) => void }) {
  const [range, setRange] = useState<DashboardRange>("7d");
  const [chartMetric, setChartMetric] = useState<ChartMetric>("revenue");
  const [data, setData] = useState<Summary | null>(null);
  const [error, setError] = useState("");
  const [refreshing, setRefreshing] = useState(false);
  const requestId = useRef(0);

  const load = useCallback(async (showRefreshing = false) => {
    const currentRequestId = requestId.current + 1;
    requestId.current = currentRequestId;
    if (showRefreshing) setRefreshing(true);
    setError("");
    try {
      const response = await fetch("/api/panel/summary?range=" + range, { cache: "no-store" });
      const payload = await response.json().catch(() => ({}));
      if (currentRequestId !== requestId.current) return;
      if (!response.ok) throw new Error(payload.error || "Dashboard gagal dimuat.");
      setData(payload as Summary);
    } catch (reason) {
      if (currentRequestId !== requestId.current) return;
      setError(reason instanceof Error ? reason.message : "Dashboard gagal dimuat.");
    } finally {
      if (currentRequestId === requestId.current) setRefreshing(false);
    }
  }, [range]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  if (!data && !error) {
    return (
      <div className="flex min-h-[420px] items-center justify-center rounded-lg border border-[#e5e9f0] bg-white text-[11px] text-[#94a3b8]">
        <LoaderCircle className="mr-2 size-4 animate-spin" />
        Memuat dashboard…
      </div>
    );
  }

  if (!data) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-[11px] text-red-700">
        <p>{error}</p>
        <Button type="button" variant="outline" size="sm" onClick={() => void load(true)} className="mt-3 border-red-200 bg-white text-red-700">
          <RefreshCw className="size-3.5" />
          Coba lagi
        </Button>
      </div>
    );
  }

  const today = data.todayMetrics;
  const summaryCards = [
    {
      label: "Omzet Hari Ini",
      value: data.canViewFinance ? formatRupiah(today.paidRevenue ?? 0) : "—",
      Icon: WalletCards,
      iconClass: "bg-emerald-50 text-emerald-600",
      delta: "Data transaksi hari ini",
      deltaClass: "text-emerald-600",
    },
    {
      label: "Profit Hari Ini",
      value: data.canViewFinance ? formatRupiah(today.profit ?? 0) : "—",
      Icon: TrendingUp,
      iconClass: "bg-violet-50 text-violet-600",
      delta: "Estimasi dari harga supplier",
      deltaClass: "text-emerald-600",
    },
    {
      label: "Pesanan Hari Ini",
      value: formatNumber(today.totalOrders),
      Icon: ShoppingCart,
      iconClass: "bg-blue-50 text-blue-600",
      delta: "Semua pesanan masuk",
      deltaClass: "text-emerald-600",
    },
    {
      label: "Pesanan Pending",
      value: formatNumber(today.pendingPayments),
      Icon: Clock3,
      iconClass: "bg-amber-50 text-amber-600",
      delta: "Menunggu pembayaran",
      deltaClass: "text-amber-600",
    },
    {
      label: "Pesanan Gagal",
      value: formatNumber(today.failedOrders),
      Icon: XCircle,
      iconClass: "bg-red-50 text-red-600",
      delta: "Perlu ditinjau",
      deltaClass: "text-red-600",
    },
    {
      label: "Produk Aktif",
      value: formatNumber(today.activeProducts),
      Icon: Box,
      iconClass: "bg-indigo-50 text-indigo-600",
      delta: "Tersedia di katalog",
      deltaClass: "text-emerald-600",
    },
  ];

  const attentionItems = [
    { label: "SKU seller sedang OFF", description: "Beberapa produk tidak tersedia di Digiflazz", value: data.attention.sellerOff, tone: "red" as const },
    { label: "Produk kehabisan stok", description: "Segera lakukan pengecekan", value: data.attention.outOfStock, tone: "orange" as const },
    { label: "Harga Digiflazz berubah", description: "Terdeteksi perubahan harga", value: data.attention.priceChanged, tone: "amber" as const },
    { label: "Transaksi Digiflazz pending", description: "Menunggu konfirmasi dari Digiflazz", value: data.attention.digiflazzPending, tone: "blue" as const },
    { label: "Pembayaran callback gagal", description: "Periksa transaksi pembayaran gagal", value: data.attention.paymentCallbackFailed, tone: "red" as const },
    { label: "Pesanan manual belum diproses", description: "Segera lakukan pemrosesan", value: data.attention.manualPending, tone: "slate" as const },
  ];

  return (
    <div className="space-y-4">
      <section className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-[20px] font-black tracking-[-0.035em] text-[#172033]">Dashboard</h1>
          <p className="mt-1 text-[10px] text-[#7c8aa0]">Ringkasan aktivitas toko dan informasi penting hari ini.</p>
        </div>
        <div className="inline-flex h-8 items-center gap-2 self-start rounded-md border border-[#e1e6ee] bg-white px-3 text-[9px] font-semibold text-[#475569] shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
          <CalendarDays className="size-3.5 text-[#64748b]" />
          Hari ini, {formatLongDate(new Date())}
        </div>
      </section>

      <section className="flex flex-wrap gap-2">
        <QuickAction primary Icon={PackagePlus} label="Tambah Produk" onClick={() => onNavigate?.("products")} />
        <QuickAction Icon={Database} label="Import Digiflazz" onClick={() => onNavigate?.("products")} />
        <QuickAction Icon={RefreshCw} label="Sync Harga" onClick={() => onNavigate?.("digiflazz")} />
        <QuickAction Icon={FileClock} label="Lihat Pesanan" onClick={() => onNavigate?.("orders")} />
      </section>

      {error && (
        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[9px] text-amber-700">
          {error}
        </div>
      )}

      <section className="grid grid-cols-2 gap-2.5 md:grid-cols-3 xl:grid-cols-6">
        {summaryCards.map((item) => (
          <MetricCard key={item.label} {...item} />
        ))}
      </section>

      <section className="grid grid-cols-2 gap-2.5 md:grid-cols-3 xl:grid-cols-5">
        <StatusCard
          Icon={CircleDollarSign}
          iconClass="bg-blue-50 text-blue-600"
          label="Saldo Digiflazz"
          value={data.canViewFinance && data.integrations.digiflazz.balance !== null ? formatRupiah(data.integrations.digiflazz.balance) : "—"}
          meta={data.integrations.digiflazz.balance !== null ? "Diperbarui otomatis" : "Belum dapat dibaca"}
        />
        <StatusCard
          Icon={CloudCog}
          iconClass="bg-emerald-50 text-emerald-600"
          label="Digiflazz"
          value={data.integrations.digiflazz.ready ? "Online" : "Belum Siap"}
          online={data.integrations.digiflazz.ready}
          meta={data.integrations.digiflazz.environment ? `Mode: ${data.integrations.digiflazz.environment}` : "Konfigurasi belum lengkap"}
        />
        <StatusCard
          Icon={CreditCard}
          iconClass="bg-red-50 text-red-600"
          label="DOKU"
          value={data.integrations.doku.ready ? "Online" : "Belum Siap"}
          online={data.integrations.doku.ready}
          meta={data.integrations.doku.ready ? "Direct API siap" : "Credential belum lengkap"}
        />
        <StatusCard
          Icon={CheckCircle2}
          iconClass="bg-emerald-50 text-emerald-600"
          label="Webhook"
          value={data.integrations.webhook.ready ? "Normal" : "Belum Siap"}
          online={data.integrations.webhook.ready}
          meta={data.integrations.webhook.ready ? "Callback URL tersedia" : "PUBLIC_BASE_URL belum siap"}
        />
        <StatusCard
          Icon={Clock3}
          iconClass="bg-slate-100 text-slate-600"
          label="Sinkronisasi Terakhir"
          value={data.integrations.digiflazz.lastSyncAt ? formatShortDateTime(data.integrations.digiflazz.lastSyncAt) : "Belum pernah"}
          meta={data.integrations.digiflazz.issues ? `${data.integrations.digiflazz.issues} SKU perlu perhatian` : "Tidak ada masalah SKU"}
        />
      </section>

      <section className="grid gap-3 xl:grid-cols-[minmax(0,1.9fr)_minmax(280px,.85fr)]">
        <div className="rounded-lg border border-[#e4e9f1] bg-white shadow-[0_1px_3px_rgba(15,23,42,0.025)]">
          <div className="flex flex-col gap-3 border-b border-[#edf0f5] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <h2 className="text-[12px] font-bold text-[#172033]">Grafik Penjualan</h2>
            <div className="flex flex-wrap items-center gap-2">
              <div className="flex rounded-md bg-[#f1f5f9] p-0.5">
                <ChartTab active={chartMetric === "revenue"} onClick={() => setChartMetric("revenue")}>Omzet</ChartTab>
                <ChartTab active={chartMetric === "profit"} onClick={() => setChartMetric("profit")}>Profit</ChartTab>
                <ChartTab active={chartMetric === "orders"} onClick={() => setChartMetric("orders")}>Jumlah Pesanan</ChartTab>
              </div>
              <select value={range} onChange={(event) => setRange(event.target.value as DashboardRange)} className="h-7 rounded-md border border-[#e2e8f0] bg-white px-2 text-[8px] font-semibold text-[#64748b] outline-none">
                {ranges.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
              <button type="button" onClick={() => void load(true)} className="grid size-7 place-items-center rounded-md border border-[#e2e8f0] text-[#64748b] hover:bg-[#f8fafc]" aria-label="Muat ulang grafik">
                <RotateCw className={"size-3 " + (refreshing ? "animate-spin" : "")} />
              </button>
            </div>
          </div>
          <SalesChart points={data.chart} metric={chartMetric} />
        </div>

        <div className="rounded-lg border border-[#e4e9f1] bg-white shadow-[0_1px_3px_rgba(15,23,42,0.025)]">
          <div className="flex items-center justify-between border-b border-[#edf0f5] px-4 py-3">
            <h2 className="text-[12px] font-bold text-[#172033]">Perlu Perhatian</h2>
            <button type="button" onClick={() => onNavigate?.("digiflazz")} className="text-[8px] font-bold text-[#155eef]">Lihat Semua</button>
          </div>
          <div className="divide-y divide-[#edf0f5]">
            {attentionItems.map((item) => <AttentionRow key={item.label} {...item} />)}
          </div>
        </div>
      </section>

      <section className="grid gap-3 xl:grid-cols-[minmax(0,1.9fr)_minmax(320px,.85fr)]">
        <div className="rounded-lg border border-[#e4e9f1] bg-white shadow-[0_1px_3px_rgba(15,23,42,0.025)]">
          <div className="flex items-center justify-between border-b border-[#edf0f5] px-4 py-3">
            <h2 className="text-[12px] font-bold text-[#172033]">Pesanan Terbaru</h2>
            <button type="button" onClick={() => onNavigate?.("orders")} className="text-[8px] font-bold text-[#155eef]">Lihat Semua</button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-left">
              <thead className="border-b border-[#edf0f5] bg-[#fbfcfe] text-[8px] font-semibold text-[#7c8aa0]">
                <tr>
                  <th className="px-3 py-2">Invoice</th>
                  <th className="px-3 py-2">Customer</th>
                  <th className="px-3 py-2">Produk</th>
                  <th className="px-3 py-2">Nominal</th>
                  <th className="px-3 py-2">Total</th>
                  <th className="px-3 py-2">Pembayaran</th>
                  <th className="px-3 py-2">Status</th>
                  <th className="px-3 py-2">Waktu</th>
                  <th className="px-3 py-2">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#edf0f5] text-[9px] text-[#475569]">
                {data.recentOrders.length ? data.recentOrders.slice(0, 6).map((item) => (
                  <tr key={item.id} className="hover:bg-[#fbfcfe]">
                    <td className="px-3 py-2 font-mono text-[8px] font-semibold text-[#334155]">{shortInvoice(item.referenceId)}</td>
                    <td className="px-3 py-2">{maskName(item.buyerName)}</td>
                    <td className="px-3 py-2 font-medium text-[#334155]">{item.productName}</td>
                    <td className="px-3 py-2">{item.packageLabel}</td>
                    <td className="px-3 py-2 font-semibold">{data.canViewFinance ? formatRupiah(item.total ?? 0) : "—"}</td>
                    <td className="px-3 py-2">{paymentLabel(item.paymentMethod, item.paymentChannel)}</td>
                    <td className="px-3 py-2"><OrderStatus payment={item.paymentStatus} fulfillment={item.fulfillmentStatus} /></td>
                    <td className="px-3 py-2">{formatTime(item.createdAt)}</td>
                    <td className="px-3 py-2"><button type="button" onClick={() => onNavigate?.("orders")} className="rounded border border-[#e2e8f0] bg-white px-2 py-1 text-[8px] font-semibold text-[#475569] hover:bg-[#f8fafc]">Detail</button></td>
                  </tr>
                )) : (
                  <tr><td colSpan={9} className="px-3 py-8 text-center text-[9px] text-[#94a3b8]">Belum ada pesanan.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        <div className="rounded-lg border border-[#e4e9f1] bg-white shadow-[0_1px_3px_rgba(15,23,42,0.025)]">
          <div className="flex items-center justify-between border-b border-[#edf0f5] px-4 py-3">
            <h2 className="text-[12px] font-bold text-[#172033]">Produk Terlaris</h2>
            <span className="rounded-md border border-[#e2e8f0] bg-white px-2 py-1 text-[8px] font-semibold text-[#64748b]">{rangeLabel(range)}</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[430px] text-left">
              <thead className="border-b border-[#edf0f5] bg-[#fbfcfe] text-[8px] font-semibold text-[#7c8aa0]">
                <tr><th className="px-3 py-2">#</th><th className="px-3 py-2">Produk</th><th className="px-3 py-2">Transaksi</th><th className="px-3 py-2">Omzet</th><th className="px-3 py-2">Profit</th></tr>
              </thead>
              <tbody className="divide-y divide-[#edf0f5] text-[8px] text-[#475569]">
                {data.topProducts.length ? data.topProducts.map((item, index) => (
                  <tr key={item.slug || index}>
                    <td className="px-3 py-2 text-[#94a3b8]">{index + 1}</td>
                    <td className="px-3 py-2 font-semibold text-[#334155]">{item.name}</td>
                    <td className="px-3 py-2">{formatNumber(item.totalOrders)}</td>
                    <td className="px-3 py-2">{data.canViewFinance ? formatRupiah(item.revenue ?? 0) : "—"}</td>
                    <td className="px-3 py-2">{data.canViewFinance ? formatRupiah(item.profit ?? 0) : "—"}</td>
                  </tr>
                )) : (
                  <tr><td colSpan={5} className="px-3 py-8 text-center text-[#94a3b8]">Belum ada data.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section className="rounded-lg border border-[#e4e9f1] bg-white shadow-[0_1px_3px_rgba(15,23,42,0.025)] xl:max-w-[70%]">
        <div className="flex items-center justify-between border-b border-[#edf0f5] px-4 py-3">
          <h2 className="text-[12px] font-bold text-[#172033]">Aktivitas Terbaru</h2>
          <span className="text-[8px] font-bold text-[#155eef]">Lihat Semua</span>
        </div>
        <div className="divide-y divide-[#edf0f5]">
          {data.recentActivities.length ? data.recentActivities.slice(0, 6).map((item, index) => (
            <div key={item.id} className="flex items-center gap-3 px-4 py-2">
              <span className={"size-1.5 shrink-0 rounded-full " + activityDot(index)} />
              <p className="min-w-0 flex-1 truncate text-[9px] text-[#475569]">
                <span className="font-semibold text-[#334155]">{item.adminName}</span> {activityLabel(item.action, item.target)}
              </p>
              <span className="shrink-0 text-[8px] text-[#94a3b8]">{relativeTime(item.createdAt)}</span>
            </div>
          )) : (
            <div className="px-4 py-8 text-center text-[9px] text-[#94a3b8]">Belum ada aktivitas admin.</div>
          )}
        </div>
      </section>
    </div>
  );
}

function QuickAction({ Icon, label, primary = false, onClick }: { Icon: typeof PackagePlus; label: string; primary?: boolean; onClick(): void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        "inline-flex h-8 items-center gap-2 rounded-md border px-3 text-[9px] font-bold shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition " +
        (primary
          ? "border-[#155eef] bg-[#155eef] text-white hover:bg-[#0f4fd1]"
          : "border-[#e1e6ee] bg-white text-[#475569] hover:bg-[#f8fafc]")
      }
    >
      <Icon className="size-3.5" />
      {label}
    </button>
  );
}

function MetricCard({
  label,
  value,
  Icon,
  iconClass,
  delta,
  deltaClass,
}: {
  label: string;
  value: string;
  Icon: typeof WalletCards;
  iconClass: string;
  delta: string;
  deltaClass: string;
}) {
  return (
    <div className="min-w-0 rounded-lg border border-[#e4e9f1] bg-white p-3 shadow-[0_1px_3px_rgba(15,23,42,0.025)]">
      <div className="flex items-start gap-2.5">
        <span className={"grid size-8 shrink-0 place-items-center rounded-md " + iconClass}><Icon className="size-4" /></span>
        <div className="min-w-0">
          <p className="truncate text-[8px] font-medium text-[#7c8aa0]">{label}</p>
          <p className="mt-1 truncate text-[14px] font-black tracking-[-0.025em] text-[#172033]">{value}</p>
        </div>
      </div>
      <p className={"mt-3 flex items-center gap-1 truncate text-[7px] font-semibold " + deltaClass}>
        <ArrowUpRight className="size-2.5" />
        {delta}
      </p>
    </div>
  );
}

function StatusCard({
  Icon,
  iconClass,
  label,
  value,
  online,
  meta,
}: {
  Icon: typeof CircleDollarSign;
  iconClass: string;
  label: string;
  value: string;
  online?: boolean;
  meta: string;
}) {
  return (
    <div className="rounded-lg border border-[#e4e9f1] bg-white p-3 shadow-[0_1px_3px_rgba(15,23,42,0.025)]">
      <div className="flex items-center gap-2.5">
        <span className={"grid size-8 shrink-0 place-items-center rounded-md " + iconClass}><Icon className="size-4" /></span>
        <div className="min-w-0">
          <p className="truncate text-[8px] font-medium text-[#7c8aa0]">{label}</p>
          <p className="mt-0.5 flex items-center gap-1.5 truncate text-[12px] font-black text-[#172033]">
            {online !== undefined && <span className={"size-1.5 rounded-full " + (online ? "bg-emerald-500" : "bg-red-500")} />}
            {value}
          </p>
        </div>
      </div>
      <p className="mt-2 truncate text-center text-[7px] text-[#94a3b8]">{meta}</p>
    </div>
  );
}

function ChartTab({ active, onClick, children }: { active: boolean; onClick(): void; children: React.ReactNode }) {
  return (
    <button type="button" onClick={onClick} className={"rounded px-2.5 py-1 text-[8px] font-bold transition " + (active ? "bg-[#5b8bdc] text-white" : "text-[#64748b] hover:text-[#334155]")}>
      {children}
    </button>
  );
}

function SalesChart({ points, metric }: { points: TrendPoint[]; metric: ChartMetric }) {
  const values = points.map((point) => metric === "orders" ? point.orders : metric === "profit" ? point.profit ?? 0 : point.revenue ?? 0);
  const max = Math.max(1, ...values);
  const width = 720;
  const height = 220;
  const left = 42;
  const right = 12;
  const top = 18;
  const bottom = 32;
  const plotWidth = width - left - right;
  const plotHeight = height - top - bottom;
  const coords = values.map((value, index) => {
    const x = left + (points.length <= 1 ? plotWidth / 2 : (index / (points.length - 1)) * plotWidth);
    const y = top + plotHeight - (value / max) * plotHeight;
    return { x, y, value, day: points[index]?.day ?? "" };
  });
  const polyline = coords.map((point) => `${point.x},${point.y}`).join(" ");
  const area = coords.length
    ? `M ${coords[0].x} ${top + plotHeight} L ${coords.map((point) => `${point.x} ${point.y}`).join(" L ")} L ${coords[coords.length - 1].x} ${top + plotHeight} Z`
    : "";
  const labels = [1, 0.75, 0.5, 0.25, 0];

  return (
    <div className="p-3 sm:p-4">
      <div className="h-[240px] w-full overflow-hidden">
        <svg viewBox={`0 0 ${width} ${height}`} className="h-full w-full" role="img" aria-label="Grafik penjualan">
          <defs>
            <linearGradient id="lf-dashboard-area" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#4f8df7" stopOpacity="0.25" />
              <stop offset="100%" stopColor="#4f8df7" stopOpacity="0.02" />
            </linearGradient>
          </defs>
          {labels.map((ratio) => {
            const y = top + plotHeight - ratio * plotHeight;
            return (
              <g key={ratio}>
                <line x1={left} y1={y} x2={width - right} y2={y} stroke="#e8edf4" strokeWidth="1" />
                <text x={left - 7} y={y + 3} textAnchor="end" fontSize="8" fill="#94a3b8">{axisLabel(max * ratio, metric)}</text>
              </g>
            );
          })}
          {area && <path d={area} fill="url(#lf-dashboard-area)" />}
          {coords.length > 0 && <polyline points={polyline} fill="none" stroke="#3b82f6" strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />}
          {coords.map((point, index) => (
            <g key={point.day + index}>
              <circle cx={point.x} cy={point.y} r="3.5" fill="#3b82f6" stroke="white" strokeWidth="2" />
              {(points.length <= 10 || index % Math.ceil(points.length / 8) === 0 || index === points.length - 1) && (
                <text x={point.x} y={height - 9} textAnchor="middle" fontSize="8" fill="#7c8aa0">{formatChartDate(point.day)}</text>
              )}
            </g>
          ))}
        </svg>
      </div>
    </div>
  );
}

function AttentionRow({ label, description, value, tone }: { label: string; description: string; value: number; tone: "red" | "orange" | "amber" | "blue" | "slate" }) {
  const colors = {
    red: "bg-red-50 text-red-500",
    orange: "bg-orange-50 text-orange-500",
    amber: "bg-amber-50 text-amber-500",
    blue: "bg-blue-50 text-blue-500",
    slate: "bg-slate-100 text-slate-600",
  }[tone];
  return (
    <div className="flex items-center gap-3 px-4 py-2.5">
      <span className={"grid size-7 shrink-0 place-items-center rounded-md " + colors}><AlertCircle className="size-3.5" /></span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-[9px] font-bold text-[#334155]">{label}</p>
        <p className="mt-0.5 truncate text-[7px] text-[#94a3b8]">{description}</p>
      </div>
      <span className={"text-[9px] font-black " + (value ? "text-red-500" : "text-[#94a3b8]")}>{value}</span>
    </div>
  );
}

function OrderStatus({ payment, fulfillment }: { payment: string; fulfillment: string }) {
  const normalized = fulfillment === "success" ? "success" : payment === "failed" || fulfillment === "failed" ? "failed" : payment === "pending" ? "pending" : "processing";
  const config = {
    success: { label: "Berhasil", cls: "bg-emerald-50 text-emerald-600" },
    failed: { label: "Gagal", cls: "bg-red-50 text-red-600" },
    pending: { label: "Pending", cls: "bg-amber-50 text-amber-600" },
    processing: { label: "Diproses", cls: "bg-blue-50 text-blue-600" },
  }[normalized];
  return <span className={"rounded px-2 py-1 text-[7px] font-bold " + config.cls}>{config.label}</span>;
}

function paymentLabel(method: string, channel: string) {
  if (method === "wallet") return "Saldo";
  if (channel && channel !== "-") return channel.replaceAll("_", " ").toUpperCase();
  if (method === "qris") return "QRIS";
  return "DOKU";
}

function activityLabel(action: string, target: string) {
  const verb = action.toUpperCase();
  if (verb === "PUT" || verb === "PATCH") return `mengubah ${target}`;
  if (verb === "POST") return `menambahkan / menjalankan ${target}`;
  if (verb === "DELETE") return `menghapus ${target}`;
  return `${action.toLowerCase()} ${target}`;
}

function activityDot(index: number) {
  return ["bg-blue-500", "bg-emerald-500", "bg-emerald-500", "bg-orange-500", "bg-violet-500", "bg-slate-400"][index % 6];
}

function axisLabel(value: number, metric: ChartMetric) {
  if (metric === "orders") return formatCompact(value);
  return compactRupiah(value);
}

function compactRupiah(value: number) {
  if (value >= 1_000_000_000) return (value / 1_000_000_000).toFixed(value >= 10_000_000_000 ? 0 : 1) + "B";
  if (value >= 1_000_000) return (value / 1_000_000).toFixed(value >= 10_000_000 ? 0 : 1) + "M";
  if (value >= 1_000) return Math.round(value / 1_000) + "K";
  return String(Math.round(value));
}

function formatCompact(value: number) {
  return new Intl.NumberFormat("id-ID", { notation: "compact", maximumFractionDigits: 1 }).format(value);
}

function formatNumber(value: number) {
  return new Intl.NumberFormat("id-ID").format(Number(value || 0));
}

function formatLongDate(date: Date) {
  return new Intl.DateTimeFormat("id-ID", { day: "2-digit", month: "short", year: "numeric" }).format(date);
}

function formatShortDateTime(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }).format(date);
}

function formatChartDate(value: string) {
  const date = new Date(value + "T00:00:00");
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", { day: "2-digit", month: "short" }).format(date);
}

function formatTime(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "-";
  return new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit" }).format(date);
}

function shortInvoice(reference: string) {
  if (reference.length <= 16) return reference;
  return reference.slice(0, 14) + "…";
}

function maskName(name: string) {
  const clean = name.trim();
  if (clean.length <= 4) return clean;
  return clean.slice(0, 4) + "***";
}

function relativeTime(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "-";
  const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
  if (seconds < 60) return "baru saja";
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} menit lalu`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} jam lalu`;
  return `${Math.floor(hours / 24)} hari lalu`;
}

function rangeLabel(range: DashboardRange) {
  return ranges.find((item) => item.value === range)?.label || "Periode";
}
