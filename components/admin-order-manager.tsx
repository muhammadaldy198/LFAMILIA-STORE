"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  BadgeCheck,
  CalendarDays,
  Check,
  CheckCircle2,
  CircleX,
  Clock3,
  Cog,
  Copy,
  Download,
  Eye,
  Landmark,
  Plus,
  QrCode,
  RefreshCw,
  Search,
  Send,
  ShoppingCart,
  TriangleAlert,
  UserRound,
  WalletCards,
  X,
} from "lucide-react";

type OrderStatus = "Berhasil" | "Diproses" | "Pending" | "Gagal" | "Komplain";

type Order = {
  dbId?: string;
  id: string;
  customer: string;
  phone: string;
  product: string;
  packageName: string;
  productCode: string;
  destination: string;
  destinationNote: string;
  payment: string;
  provider: string;
  total: number | null;
  status: OrderStatus;
  paymentStatus?: string;
  createdAt?: string;
  fulfillmentStatus?: string;
  providerStatus?: string | null;
  providerMessage?: string | null;
  providerSerialNumber?: string | null;
  deliveryMode?: "direct" | "voucher" | "manual";
};

type ApiOrder = {
  id: string;
  reference_id: string;
  product_name: string;
  package_sku: string;
  package_label: string;
  destination: string;
  server: string | null;
  nickname: string | null;
  buyer_name: string;
  buyer_phone: string;
  total: number | null;
  payment_channel: string;
  payment_status: string;
  provider_code: string | null;
  fulfillment_status: string;
  provider_status?: string | null;
  provider_message?: string | null;
  provider_serial_number?: string | null;
  delivery_mode: "direct" | "voucher" | "manual";
  created_at: string;
};

type ApiOrderEvent = {
  id: number;
  source: string;
  status: string;
  created_at: string;
};

type Activity = {
  title: string;
  invoice: string;
  detail: string;
  time: string;
  tone: "blue" | "green" | "yellow" | "red" | "pink";
  Icon: LucideIcon;
};

type OrderMetric = { label: string; value: string; change: string; note: string; trend: "up" | "down"; trendTone: "green" | "red"; tone: string; Icon: LucideIcon };

function mapOrderStatus(order: ApiOrder): OrderStatus {
  if (order.fulfillment_status === "success") return "Berhasil";
  if (order.payment_status === "failed" || order.fulfillment_status === "failed") return "Gagal";
  if (["needs_review", "retry_exhausted"].includes(order.fulfillment_status)) return "Komplain";
  if (["paid", "processing", "dispatching", "manual_pending"].includes(order.fulfillment_status) || order.payment_status === "paid") return "Diproses";
  return "Pending";
}

function mapApiOrder(order: ApiOrder): Order {
  return {
    dbId: order.id,
    id: order.reference_id,
    customer: order.buyer_name || "Pelanggan",
    phone: order.buyer_phone || "-",
    product: order.product_name,
    packageName: order.package_label,
    productCode: order.product_name.split(/\s+/).map((part) => part[0]).join("").slice(0, 5).toUpperCase(),
    destination: order.destination,
    destinationNote: [order.server ? `Server: ${order.server}` : "", order.nickname ? `Nickname: ${order.nickname}` : ""].filter(Boolean).join(" · "),
    payment: friendlyPayment(order.payment_channel),
    provider: order.provider_code === "digiflazz" ? "Digiflazz" : order.provider_code === "voucher-stock" ? "Stok Voucher" : order.provider_code ? "Provider" : "-",
    total: order.total,
    status: mapOrderStatus(order),
    paymentStatus: order.payment_status,
    createdAt: order.created_at,
    fulfillmentStatus: order.fulfillment_status,
    providerStatus: order.provider_status,
    providerMessage: order.provider_message,
    providerSerialNumber: order.provider_serial_number,
    deliveryMode: order.delivery_mode,
  };
}

function orderActivity(order: Order): Activity {
  if (order.status === "Berhasil") return { title: "Pesanan berhasil", invoice: order.id, detail: order.product, time: shortDate(order.createdAt), tone: "green", Icon: CheckCircle2 };
  if (order.status === "Gagal") return { title: "Pesanan gagal", invoice: order.id, detail: order.product, time: shortDate(order.createdAt), tone: "red", Icon: CircleX };
  if (order.status === "Komplain") return { title: "Pesanan perlu diperiksa", invoice: order.id, detail: order.product, time: shortDate(order.createdAt), tone: "pink", Icon: TriangleAlert };
  if (order.status === "Diproses") return { title: "Pesanan sedang diproses", invoice: order.id, detail: order.product, time: shortDate(order.createdAt), tone: "blue", Icon: Send };
  return { title: "Menunggu pembayaran", invoice: order.id, detail: order.payment, time: shortDate(order.createdAt), tone: "yellow", Icon: Clock3 };
}

function friendlyPayment(value: string) {
  const normalized = value.replaceAll("_", " ").trim();
  if (normalized === "wallet") return "Saldo LFAMILIA";
  if (normalized === "admin manual") return "Admin Manual";
  return normalized.toUpperCase() || "DOKU";
}

function shortDate(value?: string) {
  if (!value) return "-";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat("id-ID", { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(date);
}

function exportRows(rows: Order[], filename: string) {
  const headings = ["ID Pesanan", "Pelanggan", "Produk", "Tujuan", "Pembayaran", "Provider", "Total", "Status"];
  const lines = rows.map((order) => [order.id, order.customer, `${order.product} ${order.packageName}`, order.destination, order.payment, order.provider, order.total, order.status]);
  const csv = [headings, ...lines].map((line) => line.map(csvCell).join(",")).join("\n");
  const url = URL.createObjectURL(new Blob(["\uFEFF" + csv], { type: "text/csv;charset=utf-8" }));
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

export function AdminOrderManager() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [role, setRole] = useState<"super_admin" | "admin" | "staff">("staff");
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("Semua Status");
  const [provider, setProvider] = useState("Semua Provider");
  const [payment, setPayment] = useState("Semua Pembayaran");
  const [date, setDate] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);
  const [selected, setSelected] = useState<string[]>([]);
  const [bulkAction, setBulkAction] = useState("Aksi massal");
  const [detailOrder, setDetailOrder] = useState<Order | null>(null);
  const [detailEvents, setDetailEvents] = useState<ApiOrderEvent[]>([]);
  const [detailLoading, setDetailLoading] = useState(false);
  const [manualOpen, setManualOpen] = useState(false);
  const [notice, setNotice] = useState("");
  const [lastUpdated, setLastUpdated] = useState("Belum dimuat");
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function loadOrders(signal?: AbortSignal) {
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/panel/orders", { cache: "no-store", signal });
      const payload = await response.json().catch(() => ({})) as { orders?: ApiOrder[]; role?: "super_admin" | "admin" | "staff"; error?: string };
      if (!response.ok || !payload.orders) throw new Error(payload.error || "Pesanan gagal dimuat.");
      setRole(payload.role || "staff");
      setOrders(payload.orders.map(mapApiOrder));
      setLastUpdated(`${new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit", timeZone: "Asia/Jakarta" }).format(new Date())} WIB`);
    } catch (reason) {
      if (reason instanceof DOMException && reason.name === "AbortError") return;
      setError(reason instanceof Error ? reason.message : "Pesanan gagal dimuat.");
    } finally { setLoading(false); }
  }

  useEffect(() => {
    const controller = new AbortController();
    void loadOrders(controller.signal);
    return () => controller.abort();
  }, []);

  useEffect(() => {
    if (!autoRefresh) return;
    const timer = window.setInterval(() => { void loadOrders(); }, 30_000);
    return () => window.clearInterval(timer);
  }, [autoRefresh]);

  const visibleOrders = useMemo(() => {
    const term = query.trim().toLowerCase();
    return orders.filter((order) => {
      const searchable = `${order.id} ${order.customer} ${order.phone} ${order.product} ${order.destination}`.toLowerCase();
      const paymentGroup = order.payment.includes("QRIS") ? "QRIS" : order.payment.includes("VA") ? "Virtual Account" : "E-Wallet";
      return (!term || searchable.includes(term)) &&
        (status === "Semua Status" || order.status === status) &&
        (provider === "Semua Provider" || order.provider === provider) &&
        (payment === "Semua Pembayaran" || paymentGroup === payment) &&
        (!date || order.createdAt?.slice(0, 10) === date);
    });
  }, [date, orders, payment, provider, query, status]);
  const pageCount = Math.max(1, Math.ceil(visibleOrders.length / pageSize));
  const activePage = Math.min(page, pageCount);
  const pagedOrders = visibleOrders.slice((activePage - 1) * pageSize, activePage * pageSize);

  const liveMetrics = useMemo(() => [
    { label: "Total Pesanan", value: String(orders.length), change: "Aktual", note: "data tersimpan", trend: "up" as const, trendTone: "green" as const, tone: "blue", Icon: ShoppingCart },
    { label: "Pending", value: String(orders.filter((item) => item.status === "Pending").length), change: "Aktual", note: "menunggu bayar", trend: "up" as const, trendTone: "red" as const, tone: "yellow", Icon: Clock3 },
    { label: "Diproses", value: String(orders.filter((item) => item.status === "Diproses").length), change: "Aktual", note: "sedang diproses", trend: "up" as const, trendTone: "green" as const, tone: "blue", Icon: Cog },
    { label: "Berhasil", value: String(orders.filter((item) => item.status === "Berhasil").length), change: "Aktual", note: "selesai", trend: "up" as const, trendTone: "green" as const, tone: "green", Icon: BadgeCheck },
    { label: "Gagal", value: String(orders.filter((item) => item.status === "Gagal").length), change: "Aktual", note: "perlu diperiksa", trend: "up" as const, trendTone: "red" as const, tone: "red", Icon: CircleX },
    { label: "Komplain", value: String(orders.filter((item) => item.status === "Komplain").length), change: "Aktual", note: "butuh tindakan", trend: "up" as const, trendTone: "red" as const, tone: "red", Icon: TriangleAlert },
  ], [orders]);

  const liveActivities = useMemo(() => orders.slice(0, 10).map(orderActivity), [orders]);

  const allSelected = visibleOrders.length > 0 && visibleOrders.every((order) => selected.includes(order.id));

  function resetFilters() {
    setQuery("");
    setStatus("Semua Status");
    setProvider("Semua Provider");
    setPayment("Semua Pembayaran");
    setDate("");
    setPage(1);
  }

  async function refresh() {
    await loadOrders();
    setNotice("Pesanan terbaru berhasil dimuat.");
  }

  function exportCsv() {
    exportRows(visibleOrders, "pesanan-lfamilia.csv");
  }

  function toggleAll() {
    if (allSelected) setSelected((current) => current.filter((id) => !visibleOrders.some((order) => order.id === id)));
    else setSelected((current) => Array.from(new Set([...current, ...visibleOrders.map((order) => order.id)])));
  }

  function applyBulkAction() {
    if (!selected.length || bulkAction === "Aksi massal") {
      setNotice("Pilih pesanan dan aksi massal terlebih dahulu.");
      return;
    }
    const chosen = orders.filter((order) => selected.includes(order.id));
    exportRows(chosen, "pesanan-terpilih-lfamilia.csv");
    setNotice(`${chosen.length} pesanan terpilih berhasil diekspor.`);
    setSelected([]);
  }

  async function fetchOrderDetail(order: Order) {
    if (!order.dbId) return;
    setDetailLoading(true);
    try {
      const response = await fetch(`/api/panel/orders?id=${encodeURIComponent(order.dbId)}`, { cache: "no-store" });
      const payload = await response.json().catch(() => ({})) as { order?: ApiOrder; events?: ApiOrderEvent[]; error?: string };
      if (!response.ok || !payload.order) throw new Error(payload.error || "Detail pesanan gagal dimuat.");
      setDetailOrder(mapApiOrder(payload.order));
      setDetailEvents(payload.events || []);
    } finally {
      setDetailLoading(false);
    }
  }

  async function openOrderDetail(order: Order) {
    setDetailOrder(order);
    setDetailEvents([]);
    try {
      await fetchOrderDetail(order);
    } catch (reason) {
      setNotice(reason instanceof Error ? reason.message : "Detail pesanan gagal dimuat.");
    }
  }

  async function refreshPaymentStatus(order: Order) {
    const response = await fetch("/api/orders/status", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ referenceId: order.id }),
    });
    const payload = await response.json().catch(() => ({})) as { error?: string };
    if (!response.ok) throw new Error(payload.error || "Status pembayaran gagal diperiksa.");
    await loadOrders();
    await fetchOrderDetail(order);
    setNotice(`Status pembayaran ${order.id} sudah diperiksa ke gateway.`);
  }

  async function refreshFulfillmentStatus(order: Order) {
    if (!order.dbId) throw new Error("ID pesanan tidak tersedia.");
    const response = await fetch("/api/panel/orders", {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: order.dbId, action: "refresh_fulfillment" }),
    });
    const payload = await response.json().catch(() => ({})) as { error?: string };
    if (!response.ok) throw new Error(payload.error || "Status provider gagal diperiksa.");
    await loadOrders();
    await fetchOrderDetail(order);
    setNotice(`Status provider ${order.id} sudah diperiksa dengan reference yang sama.`);
  }

  async function addManualOrder(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    setSaving(true); setError("");
    try {
      const response = await fetch("/api/panel/orders", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(Object.fromEntries(data.entries())) });
      const payload = await response.json().catch(() => ({})) as { referenceId?: string; error?: string };
      if (!response.ok) throw new Error(payload.error || "Pesanan manual gagal disimpan.");
      setManualOpen(false);
      setNotice(`Pesanan manual ${payload.referenceId || "baru"} berhasil disimpan.`);
      await loadOrders();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Pesanan manual gagal disimpan."); }
    finally { setSaving(false); }
  }

  return (
    <div className="admin-orders-reference min-w-0 text-[#14213a]">
      <header className="flex flex-col items-start justify-between gap-[12px] lg:flex-row lg:gap-[16px]">
        <div>
          <h1 className="text-[23px] font-black leading-[1.15] tracking-[-0.04em] text-[#0b1834]">Pesanan</h1>
          <p className="mt-[4px] text-[10px] leading-[1.4] text-[#677892]">Kelola seluruh transaksi top up game, monitor status pembayaran dan proses pengiriman.</p>
        </div>
        <div className="flex w-full flex-wrap items-center gap-[8px] lg:w-auto lg:shrink-0 lg:justify-end">
          <div className="mr-[3px] flex w-full flex-wrap items-center gap-[8px] text-[8px] text-[#61728b] sm:w-auto">
            <span>Terakhir update {lastUpdated}</span>
            <button type="button" onClick={() => setAutoRefresh((value) => !value)} className={`inline-flex h-[26px] items-center gap-[5px] rounded-[5px] px-[9px] font-bold ${autoRefresh ? "bg-[#e8f9ef] text-[#139657]" : "bg-slate-100 text-slate-500"}`}>
              <Check className="size-[11px]" /> {autoRefresh ? "Auto Refresh Aktif" : "Auto Refresh Nonaktif"}
            </button>
          </div>
          <ToolbarButton onClick={refresh}><RefreshCw className="size-[13px]" />Refresh</ToolbarButton>
          {role !== "staff" && <ToolbarButton onClick={exportCsv}><Download className="size-[13px]" />Export</ToolbarButton>}
          {role !== "staff" && <button type="button" onClick={() => setManualOpen(true)} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] bg-[#0875ed] px-[14px] text-[9px] font-bold text-white shadow-[0_5px_14px_rgba(8,117,237,0.2)] hover:bg-[#0667d3]">
            <Plus className="size-[15px]" /> Pesanan Manual
          </button>}
        </div>
      </header>

      {notice && (
        <button type="button" onClick={() => setNotice("")} className="mt-[10px] flex w-full items-center justify-between rounded-[6px] border border-[#bfe8d1] bg-[#edf9f2] px-[12px] py-[8px] text-left text-[9px] font-medium text-[#168553]">
          <span>{notice}</span><X className="size-[12px]" />
        </button>
      )}
      {error && <button type="button" onClick={() => setError("")} className="mt-[10px] w-full rounded-[6px] border border-red-200 bg-red-50 px-[12px] py-[8px] text-left text-[9px] text-red-700">{error}</button>}

      <section className="mt-[12px] grid grid-cols-2 gap-[10px] sm:grid-cols-3 xl:grid-cols-6">
        {liveMetrics.map((metric) => <MetricCard key={metric.label} {...metric} />)}
      </section>

      <div className="mt-[12px] grid grid-cols-1 gap-[12px] xl:grid-cols-[minmax(0,1fr)_270px]">
        <section className="min-w-0 overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,0.04)]">
          <div className="grid grid-cols-1 gap-[8px] border-b border-[#e5eaf0] bg-[#fbfcfe] p-[10px] sm:grid-cols-2 xl:grid-cols-[1.55fr_.82fr_.86fr_1fr_1.05fr_.54fr]">
            <label className="relative">
              <span className="sr-only">Cari pesanan</span>
              <Search className="absolute left-[10px] top-1/2 size-[13px] -translate-y-1/2 text-[#71829a]" />
              <input value={query} onChange={(event) => { setQuery(event.target.value); setPage(1); }} placeholder="Cari ID / Customer / UID" className="h-[32px] w-full rounded-[5px] border border-[#dfe5ed] bg-white pl-[30px] pr-[9px] text-[9px] outline-none placeholder:text-[#7b899c] focus:border-[#2b82ef]" />
            </label>
            <FilterSelect value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={["Semua Status", "Pending", "Diproses", "Berhasil", "Gagal", "Komplain"]} />
            <FilterSelect value={provider} onChange={(value) => { setProvider(value); setPage(1); }} options={["Semua Provider", "Digiflazz", "Manual"]} />
            <FilterSelect value={payment} onChange={(value) => { setPayment(value); setPage(1); }} options={["Semua Pembayaran", "QRIS", "Virtual Account", "E-Wallet"]} />
            <label className="relative">
              <span className="sr-only">Pilih tanggal</span>
              <CalendarDays className="absolute left-[10px] top-1/2 size-[13px] -translate-y-1/2 text-[#58708d]" />
              <input type="date" value={date} onChange={(event) => { setDate(event.target.value); setPage(1); }} className="h-[32px] w-full rounded-[5px] border border-[#dfe5ed] bg-white pl-[30px] pr-[7px] text-[8px] text-[#33445d] outline-none" />
            </label>
            <button type="button" onClick={resetFilters} className="h-[32px] rounded-[5px] border border-[#dfe5ed] bg-white text-[9px] font-semibold text-[#43536b] hover:bg-[#f6f8fb]">Reset</button>
          </div>

          <div className="flex h-[43px] items-center justify-between px-[12px]">
            <h2 className="text-[12px] font-extrabold text-[#101c34]">Daftar Pesanan</h2>
            <span title={activePage === 1 ? `Menampilkan 1–${Math.min(pageSize, visibleOrders.length)} dari ${visibleOrders.length} pesanan` : undefined} className="text-[8px] text-[#657690]">{loading ? "Memuat pesanan..." : `Menampilkan ${visibleOrders.length ? (activePage - 1) * pageSize + 1 : 0}–${Math.min(activePage * pageSize, visibleOrders.length)} dari ${visibleOrders.length} pesanan`}</span>
          </div>

          <DesktopOrderTable orders={pagedOrders} selected={selected} onSelect={setSelected} onOpen={(order) => void openOrderDetail(order)} />

          <footer className="flex min-h-[46px] flex-col items-stretch justify-between gap-2 border-t border-[#e5eaf0] px-[11px] py-[7px] lg:flex-row lg:items-center">
            <div className="flex flex-wrap items-center gap-[8px]">
              <label className="flex items-center gap-[7px] text-[8px] text-[#4f6078]"><Checkbox checked={allSelected} onChange={toggleAll} />Pilih semua</label>
              <select value={bulkAction} onChange={(event) => setBulkAction(event.target.value)} className="h-[30px] w-[142px] rounded-[5px] border border-[#dce3eb] bg-white px-[9px] text-[8px] text-[#42536a] outline-none">
                <option>Aksi massal</option><option>Export terpilih</option>
              </select>
              <button type="button" onClick={applyBulkAction} className="h-[30px] rounded-[5px] bg-[#e8eef6] px-[13px] text-[8px] font-semibold text-[#61718a] hover:bg-[#dce6f1]">Terapkan</button>
            </div>
            <div className="flex flex-wrap items-center gap-[8px] text-[8px] text-[#52627a]">
              <span>Baris per halaman</span>
              <select value={pageSize} onChange={(event) => { setPageSize(Number(event.target.value)); setPage(1); }} className="h-[29px] rounded-[5px] border border-[#dce3eb] bg-white px-[8px] outline-none"><option value={25}>25</option><option value={50}>50</option><option value={100}>100</option></select>
              <Pagination page={activePage} pages={pageCount} onChange={setPage} />
            </div>
          </footer>
        </section>

        <ActivityPanel activities={liveActivities} />
      </div>

      {detailOrder && <OrderDetailModal order={detailOrder} events={detailEvents} loading={detailLoading} onClose={() => { setDetailOrder(null); setDetailEvents([]); }} onNotice={setNotice} onRefreshPayment={() => refreshPaymentStatus(detailOrder)} onRefreshFulfillment={() => refreshFulfillmentStatus(detailOrder)} onCompleted={() => void loadOrders()} />}
      {manualOpen && <ManualOrderModal saving={saving} onClose={() => setManualOpen(false)} onSubmit={addManualOrder} />}
    </div>
  );
}

function ToolbarButton({ children, onClick }: { children: ReactNode; onClick(): void }) {
  return <button type="button" onClick={onClick} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] border border-[#dbe3ed] bg-white px-[13px] text-[9px] font-bold text-[#34445d] shadow-[0_1px_2px_rgba(15,23,42,0.02)] hover:bg-[#f8fafc]">{children}</button>;
}

function MetricCard({ label, value, change, note, trend, trendTone, tone, Icon }: OrderMetric) {
  const iconTone = tone === "green" ? "bg-[#dcf8ea] text-[#08ad65]" : tone === "yellow" ? "bg-[#fff5d9] text-[#f4a700]" : tone === "red" ? "bg-[#ffe8e9] text-[#ed2639]" : "bg-[#e8f2ff] text-[#1675ee]";
  const trendColor = trendTone === "green" ? "text-[#0ba75b]" : "text-[#e9273d]";
  return (
    <article className="flex min-h-[98px] min-w-0 items-start gap-[10px] rounded-[8px] border border-[#dfe6ef] bg-white p-[12px] shadow-[0_1px_4px_rgba(20,33,58,0.04)]">
      <span className={`grid size-[42px] shrink-0 place-items-center rounded-[8px] ${iconTone}`}><Icon className="size-[21px]" strokeWidth={2.4} /></span>
      <div className="min-w-0 pt-[1px]">
        <p className="truncate text-[9px] font-semibold text-[#53657d]">{label}</p>
        <strong className="mt-[3px] block text-[18px] font-black leading-none tracking-[-0.03em] text-[#0d1933]">{value}</strong>
        <div className="mt-[12px] flex items-center gap-[6px] whitespace-nowrap text-[7px]">
          <span className={`font-extrabold ${trendColor}`}>{trend === "up" ? "↑" : "↓"} {change}</span>
          <span className="text-[#77869a]">{note}</span>
        </div>
      </div>
    </article>
  );
}

function FilterSelect({ value, onChange, options }: { value: string; onChange(value: string): void; options: string[] }) {
  return <select aria-label={options[0]} value={value} onChange={(event) => onChange(event.target.value)} className="h-[32px] min-w-0 rounded-[5px] border border-[#dfe5ed] bg-white px-[9px] text-[8px] font-medium text-[#40516a] outline-none focus:border-[#2b82ef]">{options.map((option) => <option key={option}>{option}</option>)}</select>;
}

function DesktopOrderTable({ orders, selected, onSelect, onOpen }: { orders: Order[]; selected: string[]; onSelect(ids: string[]): void; onOpen(order: Order): void }) {
  function toggle(id: string) {
    onSelect(selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id]);
  }
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[910px] table-fixed text-left">
        <thead className="border-y border-[#e3e8ef] bg-[#f4f7fa] text-[7px] font-bold text-[#52647e]">
          <tr>
            <th className="w-[32px] px-[10px] py-[8px]"></th><th className="w-[116px] py-[8px]">ID Pesanan</th><th className="w-[118px] py-[8px]">Pelanggan</th><th className="w-[135px] py-[8px]">Produk</th><th className="w-[114px] py-[8px]">Tujuan</th><th className="w-[97px] py-[8px]">Pembayaran</th><th className="w-[69px] py-[8px]">Provider</th><th className="w-[72px] py-[8px]">Total</th><th className="w-[69px] py-[8px]">Status</th><th className="w-[82px] py-[8px] text-center">Aksi</th>
          </tr>
        </thead>
        <tbody>
          {orders.length ? orders.map((order) => (
            <tr key={order.id} className="border-b border-[#e7ebf0] text-[7.5px] text-[#34465f] hover:bg-[#f9fbfd]">
              <td className="px-[10px] py-[6px]"><Checkbox checked={selected.includes(order.id)} onChange={() => toggle(order.id)} /></td>
              <td className="py-[6px] pr-[7px]"><button type="button" onClick={() => onOpen(order)} className="truncate font-bold text-[#0873dd] hover:underline">{order.id}</button></td>
              <td className="py-[6px] pr-[7px]"><div className="flex items-center gap-[6px]"><span className="grid size-[22px] shrink-0 place-items-center rounded-full bg-[#eef3f9] text-[#8090a5]"><UserRound className="size-[12px]" /></span><span className="min-w-0"><strong className="block truncate text-[7.5px] text-[#253750]">{order.customer}</strong><span className="block truncate text-[6.5px] text-[#6f8096]">{order.phone}</span></span></div></td>
              <td className="py-[6px] pr-[7px]"><div className="flex items-center gap-[7px]"><ProductThumb code={order.productCode} /><span className="min-w-0"><strong className="block truncate text-[#24364f]">{order.product}</strong><span className="block truncate text-[6.5px] text-[#6f8096]">{order.packageName}</span></span></div></td>
              <td className="py-[6px] pr-[7px]"><strong className="block truncate font-semibold">{order.destination}</strong>{order.destinationNote && <span className="block truncate text-[6.5px] text-[#72839a]">({order.destinationNote})</span>}</td>
              <td className="py-[6px] pr-[7px]"><span className="flex items-center gap-[5px]"><PaymentIcon payment={order.payment} /><span className="truncate font-semibold">{order.payment}</span></span></td>
              <td className="truncate py-[6px] pr-[7px]">{order.provider}</td>
              <td className="whitespace-nowrap py-[6px] pr-[7px] font-semibold">{formatRupiah(order.total)}</td>
              <td className="py-[6px] pr-[7px]"><StatusBadge status={order.status} /></td>
              <td className="py-[6px]"><div className="flex items-center justify-center"><button type="button" onClick={() => onOpen(order)} className="h-[26px] rounded-[4px] bg-[#e8f2ff] px-[12px] font-bold text-[#0873dd] hover:bg-[#d9eaff]">Detail</button></div></td>
            </tr>
          )) : (
            <tr><td colSpan={10} className="py-[42px] text-center text-[9px] text-[#7a899c]">Tidak ada pesanan yang cocok dengan filter.</td></tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function Checkbox({ checked, onChange }: { checked: boolean; onChange(): void }) {
  return <button type="button" aria-pressed={checked} onClick={onChange} className={`grid size-[14px] shrink-0 place-items-center rounded-[3px] border ${checked ? "border-[#1475e8] bg-[#1475e8] text-white" : "border-[#cfd8e3] bg-white text-transparent"}`}><Check className="size-[9px]" strokeWidth={3} /></button>;
}

function ProductThumb({ code }: { code: string }) {
  const tones: Record<string, string> = { ML: "from-[#235ab9] to-[#f0b44a]", FF: "from-[#f59e0b] to-[#402311]", PUBG: "from-[#2a2019] to-[#c47a1a]", VAL: "from-[#111827] to-[#e9485f]", GI: "from-[#8ca4df] to-[#efe3cf]", STEAM: "from-[#183550] to-[#1671a7]" };
  return <span className={`grid size-[28px] shrink-0 place-items-center overflow-hidden rounded-[5px] bg-gradient-to-br ${tones[code] || "from-blue-500 to-indigo-700"} text-[5px] font-black text-white shadow-sm`}>{code}</span>;
}

function PaymentIcon({ payment }: { payment: string }) {
  if (payment.includes("QRIS")) return <QrCode className="size-[12px] shrink-0 text-[#17243a]" />;
  if (payment.includes("VA")) return <Landmark className="size-[12px] shrink-0 text-[#0877db]" />;
  return <WalletCards className="size-[12px] shrink-0 text-[#159be0]" />;
}

function StatusBadge({ status }: { status: OrderStatus }) {
  const styles: Record<OrderStatus, string> = {
    Berhasil: "bg-[#dff8ea] text-[#11975b]",
    Diproses: "bg-[#fff0d4] text-[#e08a00]",
    Pending: "bg-[#fff3ce] text-[#d58a00]",
    Gagal: "bg-[#ffe5e7] text-[#dd3347]",
    Komplain: "bg-[#ffe1ef] text-[#d62972]",
  };
  return <span className={`inline-flex rounded-[4px] px-[7px] py-[4px] text-[6.5px] font-bold ${styles[status]}`}>{status}</span>;
}

function Pagination({ page, pages, onChange }: { page: number; pages: number; onChange(value: number): void }) {
  const start = pages <= 5 ? 1 : Math.min(Math.max(1, page - 2), pages - 4);
  const numbers = Array.from({ length: Math.min(5, pages) }, (_, index) => start + index);
  const base = "grid size-[26px] place-items-center rounded-[4px] border border-[#dce3eb] bg-white text-[8px] font-bold text-[#52627a] disabled:cursor-not-allowed disabled:opacity-40";
  return <nav aria-label="Pagination" className="flex items-center gap-[4px]"><button type="button" disabled={page <= 1} onClick={() => onChange(page - 1)} className={base} aria-label="Halaman sebelumnya">‹</button>{numbers.map((number) => <button key={number} type="button" onClick={() => onChange(number)} aria-current={number === page ? "page" : undefined} className={`grid size-[26px] place-items-center rounded-[4px] border text-[8px] font-bold ${number === page ? "border-[#0875ed] bg-[#0875ed] text-white" : "border-[#dce3eb] bg-white text-[#52627a]"}`}>{number}</button>)}<button type="button" disabled={page >= pages} onClick={() => onChange(page + 1)} className={base} aria-label="Halaman berikutnya">›</button></nav>;
}

function ActivityPanel({ activities }: { activities: Activity[] }) {
  return (
    <aside className="overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,0.04)]">
      <div className="flex h-[45px] items-center justify-between px-[12px]"><h2 className="text-[12px] font-extrabold text-[#101c34]">Aktivitas Terbaru</h2><span className="text-[8px] font-semibold text-[#0875e3]">{activities.length} aktivitas</span></div>
      <div className="relative px-[12px] pb-[8px]">
        <span className="absolute bottom-[22px] left-[18px] top-[15px] w-px bg-[#dbe4ee]" />
        {activities.map((activity, index) => <ActivityItem key={`${activity.invoice}-${index}`} activity={activity} />)}
        {!activities.length && <p className="py-[32px] text-center text-[8px] text-[#718198]">Belum ada aktivitas pesanan.</p>}
      </div>
    </aside>
  );
}

function ActivityItem({ activity }: { activity: Activity }) {
  const tones = { blue: "bg-[#e7f2ff] text-[#0874e4]", green: "bg-[#e1f8eb] text-[#11a660]", yellow: "bg-[#fff2d3] text-[#f1a400]", red: "bg-[#ffe4e6] text-[#e62b3d]", pink: "bg-[#ffe3f0] text-[#e82b7c]" };
  const dot = { blue: "bg-[#0874e4]", green: "bg-[#11a660]", yellow: "bg-[#f1a400]", red: "bg-[#e62b3d]", pink: "bg-[#e82b7c]" };
  const Icon = activity.Icon;
  return (
    <div className="relative flex min-h-[49px] gap-[9px] pl-[11px]">
      <span className={`absolute left-0 top-[14px] size-[5px] rounded-full ring-[3px] ring-white ${dot[activity.tone]}`} />
      <span className={`mt-[5px] grid size-[27px] shrink-0 place-items-center rounded-full ${tones[activity.tone]}`}><Icon className="size-[13px]" strokeWidth={2.5} /></span>
      <div className="min-w-0 flex-1 pt-[4px]"><strong className="block truncate text-[7.5px] text-[#25364e]">{activity.title}</strong><span className="block truncate text-[6.5px] text-[#63758d]">{activity.invoice}</span><span className="block truncate text-[6.5px] text-[#63758d]">{activity.detail}</span></div>
      <time className="shrink-0 pt-[6px] text-[6px] text-[#75869a]">{activity.time}</time>
    </div>
  );
}

function eventLabel(status: string) {
  const labels: Record<string, string> = {
    created: "Pesanan dibuat",
    pending: "Menunggu pembayaran",
    paid: "Pembayaran tervalidasi",
    processing: "Sedang diproses provider",
    dispatching: "Permintaan dikirim ke provider",
    success: "Pesanan berhasil",
    failed: "Transaksi gagal",
    expired: "Pembayaran kedaluwarsa",
    cancelled: "Transaksi dibatalkan",
    manual_pending: "Menunggu diproses admin",
    needs_review: "Perlu pemeriksaan admin",
    retry_exhausted: "Batas percobaan otomatis tercapai",
    refresh_requested: "Admin meminta pengecekan ulang",
  };
  return labels[status] || status.replaceAll("_", " ");
}

function eventSource(source: string) {
  if (source === "digiflazz") return "DigiFlazz";
  if (source === "midtrans") return "Midtrans";
  if (source === "doku") return "DOKU";
  if (source === "wallet") return "Saldo";
  if (source === "admin") return "Admin";
  if (source === "voucher_stock") return "Stok Voucher";
  return "Sistem";
}

function OrderDetailModal({
  order,
  events,
  loading,
  onClose,
  onNotice,
  onRefreshPayment,
  onRefreshFulfillment,
  onCompleted,
}: {
  order: Order;
  events: ApiOrderEvent[];
  loading: boolean;
  onClose(): void;
  onNotice(message: string): void;
  onRefreshPayment(): Promise<void>;
  onRefreshFulfillment(): Promise<void>;
  onCompleted(): void;
}) {
  const [serialNumber, setSerialNumber] = useState("");
  const [saving, setSaving] = useState(false);
  const [actionBusy, setActionBusy] = useState<"payment" | "provider" | null>(null);
  const [error, setError] = useState("");

  async function copyInvoice() {
    try {
      await navigator.clipboard.writeText(order.id);
      onNotice("Nomor invoice berhasil disalin.");
    } catch {
      onNotice(`Invoice: ${order.id}`);
    }
  }

  async function runRefresh(kind: "payment" | "provider") {
    setActionBusy(kind);
    setError("");
    try {
      if (kind === "payment") await onRefreshPayment();
      else await onRefreshFulfillment();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Status pesanan gagal diperiksa.");
    } finally {
      setActionBusy(null);
    }
  }

  async function completeManual() {
    if (!order.dbId) return;
    if (order.deliveryMode === "voucher" && !serialNumber.trim()) { setError("Kode voucher / serial wajib diisi."); return; }
    setSaving(true); setError("");
    try {
      const response = await fetch("/api/panel/orders", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: order.dbId, action: "complete_manual", serialNumber: serialNumber.trim() || undefined }) });
      const payload = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(payload.error || "Pesanan gagal diselesaikan.");
      onNotice(`${order.id} berhasil diselesaikan.`);
      onCompleted(); onClose();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Pesanan gagal diselesaikan."); }
    finally { setSaving(false); }
  }

  const terminalFulfillment = ["success", "failed", "cancelled"].includes(order.fulfillmentStatus || "");
  const canRefreshPayment = order.paymentStatus === "pending";
  const canRefreshProvider =
    order.paymentStatus === "paid" &&
    !terminalFulfillment &&
    order.provider === "Digiflazz" &&
    order.deliveryMode === "direct";
  const timeline = [
    ...(order.createdAt ? [{ id: -1, source: "system", status: "created", created_at: order.createdAt }] : []),
    ...events.filter((event) => event.status !== "created"),
  ];

  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-[#071426]/55 p-[12px] sm:p-[24px]" role="dialog" aria-modal="true" aria-label="Detail Pesanan">
      <div className="my-auto w-full max-w-[700px] overflow-hidden rounded-[10px] bg-white shadow-2xl">
        <div className="flex items-start justify-between border-b border-[#e4e9ef] px-[16px] py-[14px] sm:px-[20px] sm:py-[16px]"><div><h2 className="text-[16px] font-black text-[#101c34]">Detail Pesanan</h2><p className="mt-[3px] break-all text-[9px] text-[#718198]">{order.id}</p></div><button type="button" onClick={onClose} className="grid size-[30px] place-items-center rounded-[5px] text-[#63758b] hover:bg-[#f2f5f8]"><X className="size-[16px]" /></button></div>
        <div className="grid grid-cols-1 gap-[12px] p-[14px] text-[9px] sm:grid-cols-2 sm:gap-[16px] sm:p-[20px]">
          <DetailSection title="Informasi Pelanggan"><DetailLine label="Nama" value={order.customer} /><DetailLine label="Telepon" value={order.phone} /><DetailLine label="Tujuan" value={`${order.destination} ${order.destinationNote}`.trim()} /></DetailSection>
          <DetailSection title="Informasi Produk"><DetailLine label="Produk" value={order.product} /><DetailLine label="Nominal" value={order.packageName} /><DetailLine label="Provider" value={order.provider} /></DetailSection>
          <DetailSection title="Pembayaran"><DetailLine label="Metode" value={order.payment} /><DetailLine label="Total" value={formatRupiah(order.total)} /><DetailLine label="Status gateway" value={order.paymentStatus || "-"} /><div className="mt-[8px]"><StatusBadge status={order.status} /></div></DetailSection>
          <DetailSection title="Status Provider"><DetailLine label="Status" value={order.providerStatus || (order.paymentStatus === "paid" ? "Belum ada respons" : "Menunggu pembayaran")} />{order.providerMessage && <p className="mt-[8px] break-words rounded-[5px] bg-[#f6f8fb] px-[8px] py-[7px] text-[#52647b]">{order.providerMessage}</p>}{order.providerSerialNumber && <DetailLine label="Serial" value={order.providerSerialNumber} />}</DetailSection>
          <DetailSection title="Timeline" wide>
            {loading && !events.length ? <p className="flex items-center gap-[7px] text-[#65758c]"><RefreshCw className="size-[12px] animate-spin" />Memuat riwayat pesanan...</p> : (
              <div className="max-h-[220px] space-y-[9px] overflow-y-auto pr-[3px]">
                {timeline.map((event) => (
                  <div key={`${event.id}-${event.status}-${event.created_at}`} className="flex items-start gap-[8px]">
                    <span className="mt-[2px] grid size-[18px] shrink-0 place-items-center rounded-full bg-[#edf5ff] text-[#0875ed]"><CheckCircle2 className="size-[10px]" /></span>
                    <div className="min-w-0 flex-1"><strong className="block text-[8px] text-[#33465f]">{eventLabel(event.status)}</strong><span className="text-[7px] text-[#718198]">{eventSource(event.source)} · {shortDate(event.created_at)}</span></div>
                  </div>
                ))}
                {!timeline.length && <p className="text-[#718198]">Belum ada event tersimpan.</p>}
              </div>
            )}
          </DetailSection>
        </div>
        {error && <p className="mx-[14px] mb-[10px] rounded-[5px] bg-red-50 px-[10px] py-[7px] text-[8px] text-red-700 sm:mx-[20px]">{error}</p>}
        {order.fulfillmentStatus === "manual_pending" && order.deliveryMode === "voucher" && <label className="mx-[14px] mb-[12px] block text-[8px] font-bold text-[#42536b] sm:mx-[20px]">Kode voucher / serial<input value={serialNumber} onChange={(event) => setSerialNumber(event.target.value)} className="mt-[5px] h-[34px] w-full rounded-[5px] border border-[#dce3eb] px-[10px] text-[9px]" /></label>}
        <div className="flex flex-col gap-[9px] border-t border-[#e4e9ef] bg-[#fafbfd] px-[14px] py-[12px] sm:flex-row sm:items-center sm:justify-between sm:px-[20px]"><span className="text-[9px] font-bold text-[#2e4058]">Aksi Admin</span><div className="flex flex-wrap gap-[7px]">
          <button type="button" onClick={copyInvoice} className="inline-flex h-[32px] items-center gap-[6px] rounded-[5px] border border-[#dce3eb] bg-white px-[10px] text-[8px] font-bold text-[#40516a]"><Copy className="size-[12px]" />Copy Invoice</button>
          {canRefreshPayment && <button type="button" disabled={actionBusy !== null || saving} onClick={() => void runRefresh("payment")} className="inline-flex h-[32px] items-center gap-[6px] rounded-[5px] bg-[#0875ed] px-[11px] text-[8px] font-bold text-white disabled:opacity-50"><RefreshCw className={`size-[12px] ${actionBusy === "payment" ? "animate-spin" : ""}`} />{actionBusy === "payment" ? "Memeriksa..." : "Cek Status Pembayaran"}</button>}
          {canRefreshProvider && <button type="button" disabled={actionBusy !== null || saving} onClick={() => void runRefresh("provider")} className="inline-flex h-[32px] items-center gap-[6px] rounded-[5px] bg-amber-500 px-[11px] text-[8px] font-bold text-white disabled:opacity-50"><RefreshCw className={`size-[12px] ${actionBusy === "provider" ? "animate-spin" : ""}`} />{actionBusy === "provider" ? "Memeriksa..." : "Cek Ulang DigiFlazz"}</button>}
          {order.fulfillmentStatus === "manual_pending" && <button type="button" disabled={saving || actionBusy !== null} onClick={() => void completeManual()} className="inline-flex h-[32px] items-center gap-[6px] rounded-[5px] bg-emerald-600 px-[11px] text-[8px] font-bold text-white disabled:opacity-50"><CheckCircle2 className="size-[12px]" />{saving ? "Menyimpan..." : "Selesaikan Pesanan"}</button>}
          <button type="button" onClick={onClose} className="inline-flex h-[32px] items-center gap-[6px] rounded-[5px] border border-[#dce3eb] bg-white px-[10px] text-[8px] font-bold text-[#40516a]"><X className="size-[12px]" />Tutup</button>
        </div></div>
      </div>
    </div>
  );
}

function DetailSection({ title, children, wide = false }: { title: string; children: ReactNode; wide?: boolean }) {
  return <section className={`rounded-[7px] border border-[#e2e8ef] p-[12px] ${wide ? "sm:col-span-2" : ""}`}><h3 className="mb-[9px] text-[10px] font-extrabold text-[#17253d]">{title}</h3>{children}</section>;
}

function DetailLine({ label, value }: { label: string; value: string }) {
  return <div className="mt-[6px] flex justify-between gap-[12px]"><span className="text-[#718198]">{label}</span><strong className="text-right text-[#304158]">{value}</strong></div>;
}

function ManualOrderModal({ saving, onClose, onSubmit }: { saving: boolean; onClose(): void; onSubmit(event: FormEvent<HTMLFormElement>): void }) {
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-[#071426]/55 p-[24px]" role="dialog" aria-modal="true" aria-label="Pesanan Manual">
      <form onSubmit={onSubmit} className="w-full max-w-[560px] overflow-hidden rounded-[10px] bg-white shadow-2xl">
        <div className="flex items-start justify-between border-b border-[#e4e9ef] px-[20px] py-[16px]"><div><h2 className="text-[16px] font-black text-[#101c34]">Pesanan Manual</h2><p className="mt-[3px] text-[9px] text-[#718198]">Tambahkan transaksi baru langsung dari panel admin.</p></div><button type="button" onClick={onClose} className="grid size-[30px] place-items-center rounded-[5px] text-[#63758b] hover:bg-[#f2f5f8]"><X className="size-[16px]" /></button></div>
        <div className="grid grid-cols-2 gap-[12px] p-[20px]">
          <FormField label="Nama pelanggan" name="customer" placeholder="Contoh: Aldy" required />
          <FormField label="Nomor telepon" name="phone" placeholder="08xxxxxxxxxx" required />
          <FormField label="Produk" name="product" placeholder="Mobile Legends" required />
          <FormField label="Paket / nominal" name="packageName" placeholder="86 Diamond" required />
          <FormField label="Tujuan / User ID" name="destination" placeholder="123456789" required />
          <FormField label="Total pembayaran" name="total" placeholder="20000" type="number" required />
          <label className="col-span-2 text-[8px] font-bold text-[#42536b]">Pencatatan pembayaran<select name="payment" className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] bg-white px-[10px] text-[9px] font-medium outline-none focus:border-[#2380ec]"><option value="admin_manual">Dicatat lunas oleh Admin</option></select></label>
        </div>
        <div className="flex justify-end gap-[8px] border-t border-[#e4e9ef] bg-[#fafbfd] px-[20px] py-[12px]"><button type="button" onClick={onClose} className="h-[34px] rounded-[5px] border border-[#dce3eb] bg-white px-[14px] text-[9px] font-bold text-[#40516a]">Batal</button><button type="submit" disabled={saving} className="h-[34px] rounded-[5px] bg-[#0875ed] px-[16px] text-[9px] font-bold text-white disabled:opacity-50">{saving ? "Menyimpan..." : "Simpan Pesanan"}</button></div>
      </form>
    </div>
  );
}

function FormField({ label, ...props }: { label: string; name: string; placeholder: string; type?: string; required?: boolean }) {
  return <label className="text-[8px] font-bold text-[#42536b]">{label}<input {...props} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] px-[10px] text-[9px] font-medium outline-none placeholder:text-[#9aa6b5] focus:border-[#2380ec]" /></label>;
}

function formatRupiah(value: number | null) {
  return value == null ? "-" : `Rp ${new Intl.NumberFormat("id-ID").format(value)}`;
}

function csvCell(value: string | number | null) {
  return `"${String(value).replaceAll('"', '""')}"`;
}
