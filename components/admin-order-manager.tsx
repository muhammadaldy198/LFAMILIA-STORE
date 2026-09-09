"use client";

import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import {
  CalendarDays,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  ClipboardCopy,
  Download,
  ExternalLink,
  Filter,
  LoaderCircle,
  MoreVertical,
  Package,
  RefreshCw,
  RotateCcw,
  Search,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { formatRupiah } from "@/lib/store-data";

type Order = {
  id: string;
  reference_id: string;
  product_slug?: string;
  product_name: string;
  package_sku?: string;
  package_label: string;
  destination: string;
  server: string | null;
  nickname?: string | null;
  buyer_name: string;
  buyer_email?: string;
  buyer_phone: string;
  customer_inputs_json?: string;
  total: number | null;
  payment_method: string;
  payment_channel?: string;
  payment_status: string;
  fulfillment_type: "automatic" | "manual";
  fulfillment_status: string;
  provider_code: string | null;
  provider_sku?: string | null;
  provider_ref_id?: string | null;
  provider_status?: string | null;
  provider_message: string | null;
  provider_serial_number: string | null;
  delivery_mode: "direct" | "voucher" | "manual";
  doku_reference_no?: string | null;
  doku_payment_no?: string | null;
  created_at: string;
  updated_at?: string;
};

type OrderEvent = {
  id: number;
  source: string;
  event_id: string;
  status: string;
  payload_json: string;
  created_at: string;
};

type StatusTab = "all" | "pending" | "processing" | "success" | "failed" | "refund" | "manual";
type DetailTab = "detail" | "timeline" | "log";

const PAGE_SIZE = 8;
const statusTabs: Array<{ value: StatusTab; label: string }> = [
  { value: "all", label: "Semua" },
  { value: "pending", label: "Menunggu Pembayaran" },
  { value: "processing", label: "Diproses" },
  { value: "success", label: "Berhasil" },
  { value: "failed", label: "Gagal" },
  { value: "refund", label: "Refund" },
  { value: "manual", label: "Manual" },
];

export function AdminOrderManager() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [role, setRole] = useState<"owner" | "staff">("staff");
  const [query, setQuery] = useState("");
  const [activeStatus, setActiveStatus] = useState<StatusTab>("all");
  const [statusFilter, setStatusFilter] = useState("all");
  const [productFilter, setProductFilter] = useState("all");
  const [paymentFilter, setPaymentFilter] = useState("all");
  const [providerFilter, setProviderFilter] = useState("all");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
  const [events, setEvents] = useState<OrderEvent[]>([]);
  const [detailTab, setDetailTab] = useState<DetailTab>("detail");
  const [detailLoading, setDetailLoading] = useState(false);
  const [workingId, setWorkingId] = useState<string | null>(null);
  const [serialInput, setDeliveryInput] = useState("");

  const refreshOrders = useCallback(async (quiet = false) => {
    if (!quiet) setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/orders", { cache: "no-store" });
      const payload = (await response.json().catch(() => ({}))) as {
        orders?: Order[];
        role?: "owner" | "staff";
        error?: string;
      };
      if (!response.ok) throw new Error(payload.error || "Pesanan gagal dimuat.");
      setOrders(payload.orders ?? []);
      setRole(payload.role ?? "staff");
      setSelectedOrder((current) => current ? payload.orders?.find((order) => order.id === current.id) ?? current : current);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pesanan gagal dimuat.");
    } finally {
      if (!quiet) setLoading(false);
    }
  }, []);

  const loadDetail = useCallback(async (id: string) => {
    setDetailLoading(true);
    try {
      const response = await fetch(`/api/panel/orders?id=${encodeURIComponent(id)}`, { cache: "no-store" });
      const payload = (await response.json().catch(() => ({}))) as {
        order?: Order;
        events?: OrderEvent[];
        error?: string;
      };
      if (!response.ok || !payload.order) throw new Error(payload.error || "Detail pesanan gagal dimuat.");
      setSelectedOrder(payload.order);
      setEvents(payload.events ?? []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Detail pesanan gagal dimuat.");
    } finally {
      setDetailLoading(false);
    }
  }, []);

  useEffect(() => {
    const initialTimer = window.setTimeout(() => void refreshOrders(), 0);
    const refreshTimer = window.setInterval(() => void refreshOrders(true), 60_000);
    return () => {
      window.clearTimeout(initialTimer);
      window.clearInterval(refreshTimer);
    };
  }, [refreshOrders]);

  useEffect(() => {
    function closeWithEscape(event: KeyboardEvent) {
      if (event.key !== "Escape") return;
      if (filtersOpen) setFiltersOpen(false);
      else if (selectedOrder) setSelectedOrder(null);
    }
    document.addEventListener("keydown", closeWithEscape);
    return () => document.removeEventListener("keydown", closeWithEscape);
  }, [filtersOpen, selectedOrder]);

  const statusCounts = useMemo(() => Object.fromEntries(statusTabs.map((tab) => [tab.value, orders.filter((order) => matchesStatus(order, tab.value)).length])), [orders]);
  const products = useMemo(() => uniqueOptions(orders.map((order) => order.product_name)), [orders]);
  const payments = useMemo(() => uniqueOptions(orders.map((order) => order.payment_channel || order.payment_method)), [orders]);
  const providers = useMemo(() => uniqueOptions(orders.map((order) => order.provider_code || "manual")), [orders]);

  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return orders.filter((order) => {
      const createdDate = normalizedDate(order.created_at);
      const searchable = `${order.reference_id} ${order.product_name} ${order.package_label} ${order.buyer_name} ${order.buyer_phone} ${order.destination} ${parseOrderInputs(order).map((item) => item.value).join(" ")}`.toLowerCase();
      return (
        matchesStatus(order, activeStatus) &&
        (statusFilter === "all" || order.payment_status === statusFilter || order.fulfillment_status === statusFilter) &&
        (productFilter === "all" || order.product_name === productFilter) &&
        (paymentFilter === "all" || (order.payment_channel || order.payment_method) === paymentFilter) &&
        (providerFilter === "all" || (order.provider_code || "manual") === providerFilter) &&
        (!dateFrom || createdDate >= dateFrom) &&
        (!dateTo || createdDate <= dateTo) &&
        (!term || searchable.includes(term))
      );
    });
  }, [orders, query, activeStatus, statusFilter, productFilter, paymentFilter, providerFilter, dateFrom, dateTo]);

  const totalPages = Math.max(1, Math.ceil(visible.length / PAGE_SIZE));
  const currentPage = Math.min(page, totalPages);
  const pageOrders = visible.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

  function updateFilter(update: () => void) {
    update();
    setPage(1);
  }

  function resetFilters() {
    setQuery("");
    setStatusFilter("all");
    setProductFilter("all");
    setPaymentFilter("all");
    setProviderFilter("all");
    setDateFrom("");
    setDateTo("");
    setActiveStatus("all");
    setPage(1);
  }

  function openDetail(order: Order) {
    setSelectedOrder(order);
    setEvents([]);
    setDetailTab("detail");
    setDeliveryInput("");
    void loadDetail(order.id);
  }

  async function completeManual(order: Order) {
    const serialNumber = deliveryInput.trim();
    if (order.delivery_mode === "voucher" && !serialNumber) {
      setError("Kode voucher / serial wajib diisi sebelum pesanan diselesaikan.");
      return;
    }
    setWorkingId(order.id);
    setError("");
    try {
      const response = await fetch("/api/panel/orders", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ id: order.id, action: "complete_manual", ...(serialNumber ? { serialNumber } : {}) }),
      });
      const payload = (await response.json().catch(() => ({}))) as { error?: string };
      if (!response.ok) throw new Error(payload.error || "Pesanan gagal diperbarui.");
      setDeliveryInput("");
      setNotice("Pesanan manual berhasil diselesaikan.");
      await Promise.all([refreshOrders(true), loadDetail(order.id)]);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pesanan gagal diperbarui.");
    } finally {
      setWorkingId(null);
    }
  }

  async function retryVoucher(order: Order) {
    setWorkingId(order.id);
    setError("");
    try {
      const response = await fetch("/api/panel/vouchers", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "retry", orderId: order.id }),
      });
      const payload = (await response.json().catch(() => ({}))) as { ok?: boolean; message?: string; error?: string };
      if (!response.ok || !payload.ok) throw new Error(payload.error || payload.message || "Pengiriman kode belum berhasil.");
      setNotice("Pengiriman kode voucher dijalankan ulang.");
      await Promise.all([refreshOrders(true), loadDetail(order.id)]);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengiriman kode gagal.");
    } finally {
      setWorkingId(null);
    }
  }

  async function copyInvoice(order: Order) {
    try {
      await navigator.clipboard.writeText(order.reference_id);
      setNotice("Nomor invoice berhasil disalin.");
    } catch {
      setError("Nomor invoice tidak dapat disalin oleh browser.");
    }
  }

  function exportCsv() {
    const header = ["Invoice", "Waktu", "Customer", "Produk", "Nominal", "Tujuan", "Total", "Pembayaran", "Provider", "Status"];
    const rows = visible.map((order) => [
      order.reference_id,
      order.created_at,
      order.buyer_name,
      order.product_name,
      order.package_label,
      destinationLabel(order),
      order.total ?? "",
      order.payment_channel || order.payment_method,
      order.provider_code || "manual",
      primaryStatus(order),
    ]);
    const csv = [header, ...rows].map((row) => row.map(csvCell).join(",")).join("\n");
    const url = URL.createObjectURL(new Blob(["\uFEFF" + csv], { type: "text/csv;charset=utf-8" }));
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = `pesanan-lfamilia-${new Date().toISOString().slice(0, 10)}.csv`;
    anchor.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="min-w-0">
      <div className="mb-4 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-black tracking-[-0.03em] text-[#172033]">Pesanan</h1>
          <p className="mt-1 text-[10px] text-[#667085]">Kelola semua pesanan dan pantau status transaksi pelanggan.</p>
        </div>
        <Button type="button" variant="outline" onClick={exportCsv} className="h-8 shrink-0 rounded-md border-[#dfe5ed] bg-white px-3 text-[9px] font-bold text-[#344054] shadow-none">
          <Download className="size-3.5" />
          Export
        </Button>
      </div>

      <div className="hidden grid-cols-2 gap-2 lg:grid xl:grid-cols-5">
        <DateRange dateFrom={dateFrom} dateTo={dateTo} setDateFrom={(value) => updateFilter(() => setDateFrom(value))} setDateTo={(value) => updateFilter(() => setDateTo(value))} />
        <FilterSelect label="Semua Status" value={statusFilter} onChange={(value) => updateFilter(() => setStatusFilter(value))} options={statusOptions()} />
        <FilterSelect label="Semua Produk" value={productFilter} onChange={(value) => updateFilter(() => setProductFilter(value))} options={products} />
        <FilterSelect label="Semua Pembayaran" value={paymentFilter} onChange={(value) => updateFilter(() => setPaymentFilter(value))} options={payments} />
        <FilterSelect label="Semua Provider" value={providerFilter} onChange={(value) => updateFilter(() => setProviderFilter(value))} options={providers} />
      </div>

      <div className="mt-2 flex gap-2">
        <label className="relative min-w-0 flex-1">
          <span className="sr-only">Cari pesanan</span>
          <Search className="absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-[#98a2b3]" />
          <Input value={query} onChange={(event) => updateFilter(() => setQuery(event.target.value))} placeholder="Cari invoice, username, atau nomor tujuan..." className="h-9 rounded-md border-[#dfe5ed] bg-white pl-9 text-[9px] text-[#344054] shadow-none placeholder:text-[#98a2b3]" />
        </label>
        <Button type="button" onClick={() => setFiltersOpen(true)} variant="outline" className="h-9 rounded-md border-[#dfe5ed] bg-white px-3 text-[9px] text-[#475467] lg:hidden">
          <Filter className="size-3.5" />
          Filter
        </Button>
        <Button type="button" onClick={() => void refreshOrders()} className="hidden h-9 rounded-md bg-[#155eef] px-4 text-[9px] font-bold text-white hover:bg-[#0f4dca] lg:inline-flex">
          <RefreshCw className="size-3.5" />
          Muat Ulang
        </Button>
        <Button type="button" onClick={resetFilters} variant="outline" className="hidden h-9 rounded-md border-[#dfe5ed] bg-white px-3 text-[9px] text-[#475467] lg:inline-flex">
          <RotateCcw className="size-3.5" />
          Reset
        </Button>
      </div>

      <div className="mt-4 flex gap-1 overflow-x-auto border-b border-[#e5e9f0] scrollbar-none">
        {statusTabs.map((tab) => (
          <button key={tab.value} type="button" onClick={() => updateFilter(() => setActiveStatus(tab.value))} className={`flex h-9 shrink-0 items-center gap-1.5 border-b-2 px-3 text-[9px] font-semibold transition ${activeStatus === tab.value ? "border-[#155eef] text-[#155eef]" : "border-transparent text-[#667085] hover:text-[#344054]"}`}>
            {tab.label}
            <span className={`rounded px-1.5 py-0.5 text-[7px] ${activeStatus === tab.value ? "bg-[#edf4ff] text-[#155eef]" : statusCountTone(tab.value)}`}>{statusCounts[tab.value] || 0}</span>
          </button>
        ))}
      </div>

      {error && <div className="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[9px] text-red-700">{error}</div>}
      {notice && <button type="button" onClick={() => setNotice("")} className="mt-3 w-full rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-left text-[9px] text-emerald-700">{notice}</button>}

      {loading ? (
        <div className="flex min-h-64 items-center justify-center rounded-b-lg border border-t-0 border-[#e5e9f0] bg-white text-[10px] text-[#98a2b3]"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat pesanan…</div>
      ) : !visible.length ? (
        <div className="flex min-h-64 flex-col items-center justify-center rounded-b-lg border border-t-0 border-[#e5e9f0] bg-white text-center text-[10px] text-[#98a2b3]"><Package className="mb-2 size-7 text-[#cbd5e1]" />Belum ada pesanan yang cocok.</div>
      ) : (
        <>
          <DesktopOrderTable orders={pageOrders} onOpen={openDetail} />
          <MobileOrderList orders={pageOrders} onOpen={openDetail} />
          <Pagination page={currentPage} totalPages={totalPages} total={visible.length} onPage={setPage} />
        </>
      )}

      {filtersOpen && (
        <MobileFilters
          dateFrom={dateFrom} dateTo={dateTo} status={statusFilter} product={productFilter} payment={paymentFilter} provider={providerFilter}
          products={products} payments={payments} providers={providers}
          setDateFrom={setDateFrom} setDateTo={setDateTo} setStatus={setStatusFilter} setProduct={setProductFilter} setPayment={setPaymentFilter} setProvider={setProviderFilter}
          onClose={() => setFiltersOpen(false)} onReset={resetFilters}
        />
      )}

      {selectedOrder && (
        <OrderDetailDrawer
          order={selectedOrder} events={events} role={role} tab={detailTab} setTab={setDetailTab}
          loading={detailLoading} working={workingId === selectedOrder.id} deliveryInput={deliveryInput} setDeliveryInput={setDeliveryInput}
          onClose={() => setSelectedOrder(null)} onRefresh={() => void Promise.all([refreshOrders(true), loadDetail(selectedOrder.id)])}
          onCopy={() => void copyInvoice(selectedOrder)} onComplete={() => void completeManual(selectedOrder)} onRetryVoucher={() => void retryVoucher(selectedOrder)}
        />
      )}
    </div>
  );
}

function DesktopOrderTable({ orders, onOpen }: { orders: Order[]; onOpen: (order: Order) => void }) {
  return (
    <div className="hidden overflow-x-auto rounded-b-lg border border-t-0 border-[#e5e9f0] bg-white lg:block">
      <table className="w-full min-w-[940px] text-left">
        <thead className="bg-[#f8fafc] text-[8px] font-bold text-[#667085]"><tr><th className="px-3 py-2.5">Invoice</th><th className="px-3 py-2.5">Customer</th><th className="px-3 py-2.5">Produk</th><th className="px-3 py-2.5">Tujuan / User ID</th><th className="px-3 py-2.5">Total</th><th className="px-3 py-2.5">Pembayaran</th><th className="px-3 py-2.5">Provider</th><th className="px-3 py-2.5">Status</th><th className="px-3 py-2.5">Waktu</th><th className="px-3 py-2.5 text-center">Aksi</th></tr></thead>
        <tbody>{orders.map((order) => (
          <tr key={order.id} className="border-t border-[#eef1f5] text-[8px] text-[#344054] hover:bg-[#fbfcfe]">
            <td className="whitespace-nowrap px-3 py-2.5 font-bold">{order.reference_id}</td>
            <td className="max-w-28 truncate px-3 py-2.5">{maskName(order.buyer_name)}</td>
            <td className="px-3 py-2.5"><strong className="block max-w-32 truncate text-[#1d2939]">{order.product_name}</strong><span className="mt-0.5 block max-w-32 truncate text-[7px] text-[#98a2b3]">{order.package_label}</span></td>
            <td className="max-w-32 truncate px-3 py-2.5">{destinationLabel(order)}</td>
            <td className="whitespace-nowrap px-3 py-2.5 font-semibold">{order.total === null ? "Khusus Pemilik" : formatRupiah(order.total)}</td>
            <td className="max-w-24 truncate px-3 py-2.5">{displayPayment(order)}</td>
            <td className="max-w-20 truncate px-3 py-2.5 capitalize">{order.provider_code || "Manual"}</td>
            <td className="px-3 py-2.5"><StatusBadge order={order} /></td>
            <td className="whitespace-nowrap px-3 py-2.5"><span className="block">{formatDate(order.created_at)}</span><span className="text-[7px] text-[#98a2b3]">{formatTime(order.created_at)}</span></td>
            <td className="px-3 py-2"><div className="flex items-center justify-center gap-1"><Button type="button" variant="outline" onClick={() => onOpen(order)} className="h-7 min-w-20 rounded-md border-[#dfe5ed] bg-white px-3 text-[8px] font-bold text-[#344054] shadow-none">Detail</Button><button type="button" onClick={() => onOpen(order)} aria-label={`Aksi ${order.reference_id}`} className="grid size-7 place-items-center rounded-md text-[#667085] hover:bg-[#f2f4f7]"><MoreVertical className="size-3.5" /></button></div></td>
          </tr>
        ))}</tbody>
      </table>
    </div>
  );
}

function MobileOrderList({ orders, onOpen }: { orders: Order[]; onOpen: (order: Order) => void }) {
  return <div className="divide-y divide-[#edf0f5] border-x border-b border-[#e5e9f0] bg-white lg:hidden">{orders.map((order) => (
    <button key={order.id} type="button" onClick={() => onOpen(order)} className="flex w-full gap-2.5 px-3 py-3 text-left hover:bg-[#f8fafc]">
      <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-[#edf4ff] text-[#155eef]"><Package className="size-4" /></span>
      <span className="min-w-0 flex-1"><span className="flex items-start justify-between gap-2"><strong className="truncate text-[9px] text-[#1d2939]">{order.reference_id}</strong><StatusBadge order={order} /></span><strong className="mt-1 block truncate text-[10px] text-[#344054]">{order.product_name}</strong><span className="block truncate text-[8px] text-[#98a2b3]">{order.package_label}</span><span className="mt-1 block text-[9px] font-bold text-[#1d2939]">{order.total === null ? "Khusus Pemilik" : formatRupiah(order.total)}</span></span>
    </button>
  ))}</div>;
}

function Pagination({ page, totalPages, total, onPage }: { page: number; totalPages: number; total: number; onPage: (page: number) => void }) {
  const pages = paginationPages(page, totalPages);
  return <div className="flex flex-col gap-2 rounded-b-lg border-x border-b border-[#e5e9f0] bg-white px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between"><div className="flex items-center gap-1"><button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className="grid size-7 place-items-center rounded-md border border-[#e2e8f0] text-[#667085] disabled:opacity-40"><ChevronLeft className="size-3.5" /></button>{pages.map((value, index) => value === "…" ? <span key={`dots-${index}`} className="px-1 text-[8px] text-[#98a2b3]">…</span> : <button key={value} type="button" onClick={() => onPage(value)} className={`size-7 rounded-md text-[8px] font-bold ${page === value ? "bg-[#155eef] text-white" : "text-[#475467] hover:bg-[#f2f4f7]"}`}>{value}</button>)}<button type="button" disabled={page >= totalPages} onClick={() => onPage(page + 1)} className="grid size-7 place-items-center rounded-md border border-[#e2e8f0] text-[#667085] disabled:opacity-40"><ChevronRight className="size-3.5" /></button></div><span className="text-[8px] text-[#667085]">Menampilkan {Math.min((page - 1) * PAGE_SIZE + 1, total)}–{Math.min(page * PAGE_SIZE, total)} dari {total} pesanan</span></div>;
}

function OrderDetailDrawer(props: { order: Order; events: OrderEvent[]; role: "owner" | "staff"; tab: DetailTab; setTab: (tab: DetailTab) => void; loading: boolean; working: boolean; deliveryInput: string; setDeliveryInput: (value: string) => void; onClose: () => void; onRefresh: () => void; onCopy: () => void; onComplete: () => void; onRetryVoucher: () => void }) {
  const { order, events, role, tab, setTab, loading, working, deliveryInput, setDeliveryInput, onClose, onRefresh, onCopy, onComplete, onRetryVoucher } = props;
  const manualReady = order.fulfillment_type === "manual" && order.payment_status === "paid" && order.fulfillment_status === "manual_pending";
  const voucherRetry = role === "owner" && order.provider_code === "voucher-stock" && order.payment_status === "paid" && order.fulfillment_status !== "success";
  const wa = order.buyer_phone.replace(/\D/g, "").replace(/^0/, "62");
  return <><button type="button" onClick={onClose} aria-label="Tutup detail pesanan" className="fixed inset-0 top-[54px] z-40 bg-slate-950/20" /><aside className="fixed inset-y-0 right-0 z-50 flex w-full max-w-[390px] flex-col border-l border-[#e2e8f0] bg-white shadow-2xl sm:top-[54px]">
    <div className="flex items-center justify-between border-b border-[#edf0f5] px-4 py-3"><div><p className="text-[11px] font-black text-[#172033]">Detail Pesanan</p><p className="mt-0.5 text-[8px] text-[#98a2b3]">Informasi transaksi lengkap</p></div><button type="button" onClick={onClose} className="grid size-8 place-items-center rounded-md text-[#667085] hover:bg-[#f2f4f7]" aria-label="Tutup"><X className="size-4" /></button></div>
    <div className="border-b border-[#edf0f5] px-4 py-3"><div className="flex items-center gap-2"><strong className="text-[13px] text-[#172033]">{order.reference_id}</strong><StatusBadge order={order} /></div><p className="mt-1 text-[8px] text-[#98a2b3]">{formatFullDate(order.created_at)}</p></div>
    <div className="grid grid-cols-3 border-b border-[#edf0f5] px-4 pt-2">{(["detail", "timeline", "log"] as DetailTab[]).map((value) => <button key={value} type="button" onClick={() => setTab(value)} className={`h-8 border-b-2 text-[8px] font-bold capitalize ${tab === value ? "border-[#155eef] text-[#155eef]" : "border-transparent text-[#667085]"}`}>{value}</button>)}</div>
    <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3">{loading ? <div className="flex justify-center py-12 text-[#98a2b3]"><LoaderCircle className="size-5 animate-spin" /></div> : tab === "detail" ? <div className="space-y-4">
      <DetailSection title="Informasi Order"><DetailRow label="Customer" value={order.buyer_email ? `${order.buyer_name} (${order.buyer_email})` : order.buyer_name} /><DetailRow label="Produk" value={order.product_name} /><DetailRow label="Nominal" value={order.package_label} />{parseOrderInputs(order).map((item) => <DetailRow key={item.label} label={item.label} value={item.value || "-"} />)}<DetailRow label="Total" value={order.total === null ? "Khusus Pemilik" : formatRupiah(order.total)} strong /></DetailSection>
      <DetailSection title="Pembayaran"><DetailRow label="Metode" value={displayPayment(order)} /><DetailRow label="Status" value={statusLabel(order.payment_status)} /><DetailRow label="Reference" value={order.doku_reference_no || order.doku_payment_no || "-"} /><DetailRow label="Waktu" value={formatFullDate(order.updated_at || order.created_at)} /></DetailSection>
      <DetailSection title="Fulfillment"><DetailRow label="Provider" value={order.provider_code || "Manual"} /><DetailRow label="SKU" value={order.provider_sku || order.package_sku || "-"} /><DetailRow label="Ref. Provider" value={order.provider_ref_id || "-"} /><DetailRow label="Status Provider" value={statusLabel(order.provider_status || order.fulfillment_status)} /><DetailRow label="Serial / SN" value={order.provider_serial_number || "-"} />{order.provider_message && <div className="mt-2 rounded-md bg-[#f8fafc] p-2 text-[8px] leading-4 text-[#667085]">{order.provider_message}</div>}</DetailSection>
    </div> : tab === "timeline" ? <Timeline order={order} events={events} /> : <EventLog events={events} />}</div>
    <div className="border-t border-[#edf0f5] p-4"><p className="mb-2 text-[9px] font-black text-[#172033]">Aksi Admin</p><div className="grid grid-cols-2 gap-2"><Button type="button" variant="outline" onClick={onRefresh} className="h-8 rounded-md border-[#dfe5ed] bg-white text-[8px] text-[#344054]"><RefreshCw className="size-3" />Segarkan Data</Button><Button type="button" variant="outline" onClick={onCopy} className="h-8 rounded-md border-[#dfe5ed] bg-white text-[8px] text-[#344054]"><ClipboardCopy className="size-3" />Copy Invoice</Button><a href={`https://wa.me/${wa}`} target="_blank" rel="noreferrer" className="inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-[#dfe5ed] bg-white text-[8px] font-semibold text-[#344054]"><ExternalLink className="size-3" />WhatsApp</a>{voucherRetry && <Button type="button" disabled={working} onClick={onRetryVoucher} className="h-8 rounded-md bg-amber-400 text-[8px] font-bold text-amber-950 hover:bg-amber-300"><RotateCcw className="size-3" />Kirim Kode</Button>}</div>{manualReady && <div className="mt-3 space-y-2">{order.delivery_mode === "voucher" && <Input value={deliveryInput} onChange={(event) => setDeliveryInput(event.target.value)} placeholder="Kode voucher / serial" className="h-9 rounded-md border-[#dfe5ed] text-[9px]" />}<Button type="button" disabled={working || (order.delivery_mode === "voucher" && !deliveryInput.trim())} onClick={onComplete} className="h-9 w-full rounded-md bg-[#155eef] text-[9px] font-bold text-white hover:bg-[#0f4dca]"><CheckCircle2 className="size-3.5" />{working ? "Memproses…" : "Selesaikan Pesanan Manual"}</Button></div>}</div>
  </aside></>;
}

function Timeline({ order, events }: { order: Order; events: OrderEvent[] }) {
  const items = events.length ? events : [{ id: 0, source: "store", event_id: "created", status: "created", payload_json: "{}", created_at: order.created_at }];
  return <div className="space-y-0">{items.map((event, index) => <div key={`${event.id}-${event.event_id}`} className="flex gap-3"><div className="flex flex-col items-center"><span className="grid size-5 place-items-center rounded-full bg-emerald-500 text-white"><CheckCircle2 className="size-3" /></span>{index < items.length - 1 && <span className="min-h-8 w-px flex-1 bg-[#dbe4ee]" />}</div><div className="min-w-0 flex-1 pb-4"><div className="flex items-start justify-between gap-2"><strong className="text-[8px] text-[#344054]">{timelineLabel(event)}</strong><span className="shrink-0 text-[7px] text-[#98a2b3]">{formatFullDate(event.created_at)}</span></div><p className="mt-0.5 truncate text-[7px] capitalize text-[#667085]">{event.source} • {statusLabel(event.status)}</p></div></div>)}</div>;
}

function EventLog({ events }: { events: OrderEvent[] }) {
  if (!events.length) return <div className="py-12 text-center text-[9px] text-[#98a2b3]">Belum ada log transaksi.</div>;
  return <div className="space-y-2">{events.map((event) => <details key={`${event.id}-${event.event_id}`} className="rounded-md border border-[#e5e9f0] bg-[#f8fafc] p-2"><summary className="cursor-pointer text-[8px] font-bold text-[#344054]">{event.source} • {event.status} <span className="font-normal text-[#98a2b3]">{formatFullDate(event.created_at)}</span></summary><pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all rounded bg-white p-2 text-[7px] leading-4 text-[#667085]">{prettyPayload(event.payload_json)}</pre></details>)}</div>;
}

function MobileFilters(props: { dateFrom: string; dateTo: string; status: string; product: string; payment: string; provider: string; products: string[]; payments: string[]; providers: string[]; setDateFrom: (v: string) => void; setDateTo: (v: string) => void; setStatus: (v: string) => void; setProduct: (v: string) => void; setPayment: (v: string) => void; setProvider: (v: string) => void; onClose: () => void; onReset: () => void }) {
  return <div className="fixed inset-0 z-[70] bg-slate-950/30 lg:hidden"><div className="absolute inset-y-0 right-0 w-[88vw] max-w-sm bg-white p-4 shadow-2xl"><div className="flex items-center justify-between"><strong className="text-sm text-[#172033]">Filter Pesanan</strong><button type="button" onClick={props.onClose} className="grid size-8 place-items-center rounded-md text-[#667085]"><X className="size-4" /></button></div><div className="mt-4 space-y-3"><DateRange dateFrom={props.dateFrom} dateTo={props.dateTo} setDateFrom={props.setDateFrom} setDateTo={props.setDateTo} /><FilterSelect label="Semua Status" value={props.status} onChange={props.setStatus} options={statusOptions()} /><FilterSelect label="Semua Produk" value={props.product} onChange={props.setProduct} options={props.products} /><FilterSelect label="Semua Pembayaran" value={props.payment} onChange={props.setPayment} options={props.payments} /><FilterSelect label="Semua Provider" value={props.provider} onChange={props.setProvider} options={props.providers} /></div><div className="absolute inset-x-4 bottom-4 grid grid-cols-2 gap-2"><Button type="button" variant="outline" onClick={props.onReset} className="h-9 rounded-md border-[#dfe5ed] bg-white text-[9px]">Reset</Button><Button type="button" onClick={props.onClose} className="h-9 rounded-md bg-[#155eef] text-[9px] text-white">Terapkan</Button></div></div></div>;
}

function DateRange({ dateFrom, dateTo, setDateFrom, setDateTo }: { dateFrom: string; dateTo: string; setDateFrom: (value: string) => void; setDateTo: (value: string) => void }) {
  return <div className="col-span-2 grid grid-cols-2 gap-2 xl:col-span-1"><label className="relative"><span className="mb-1 block text-[8px] font-semibold text-[#667085] xl:sr-only">Dari tanggal</span><CalendarDays className="pointer-events-none absolute bottom-2.5 left-3 size-3.5 text-[#667085]" /><input aria-label="Dari tanggal" type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} className="h-9 w-full rounded-md border border-[#dfe5ed] bg-white pl-9 pr-2 text-[8px] text-[#344054] outline-none focus:border-[#155eef]" /></label><label><span className="mb-1 block text-[8px] font-semibold text-[#667085] xl:sr-only">Sampai tanggal</span><input aria-label="Sampai tanggal" type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} className="h-9 w-full rounded-md border border-[#dfe5ed] bg-white px-2 text-[8px] text-[#344054] outline-none focus:border-[#155eef]" /></label></div>;
}

function FilterSelect({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: string[] }) {
  return <label><span className="mb-1 block text-[8px] font-semibold text-[#667085] lg:sr-only">{label}</span><select aria-label={label} value={value} onChange={(event) => onChange(event.target.value)} className="h-9 w-full rounded-md border border-[#dfe5ed] bg-white px-3 text-[8px] text-[#344054] outline-none focus:border-[#155eef]"><option value="all">{label}</option>{options.map((option) => <option key={option} value={option}>{label === "Semua Status" ? statusLabel(option) : option}</option>)}</select></label>;
}

function DetailSection({ title, children }: { title: string; children: ReactNode }) { return <section className="border-b border-[#edf0f5] pb-4 last:border-0"><h3 className="mb-2 text-[9px] font-black text-[#172033]">{title}</h3><div className="space-y-1.5">{children}</div></section>; }
function DetailRow({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) { return <div className="grid grid-cols-[92px_minmax(0,1fr)] gap-2 text-[8px]"><span className="text-[#667085]">{label}</span><span className={`break-words text-[#344054] ${strong ? "font-black" : "font-medium"}`}>{value}</span></div>; }

function StatusBadge({ order }: { order: Order }) { const status = primaryStatus(order); const tone = statusTone(status); return <span className={`inline-flex whitespace-nowrap rounded px-1.5 py-1 text-[7px] font-bold ${tone}`}>{statusLabel(status)}</span>; }
function primaryStatus(order: Order) { if (order.payment_status !== "paid") return order.payment_status; return order.fulfillment_status; }
function matchesStatus(order: Order, status: StatusTab) { if (status === "all") return true; if (status === "pending") return ["pending", "waiting_payment"].includes(order.payment_status) || order.fulfillment_status === "waiting_payment"; if (status === "processing") return ["processing", "dispatching", "pending"].includes(order.fulfillment_status); if (status === "success") return order.payment_status === "paid" && order.fulfillment_status === "success"; if (status === "failed") return ["failed", "expired", "cancelled", "needs_review"].includes(order.payment_status) || ["failed", "cancelled", "needs_review"].includes(order.fulfillment_status); if (status === "refund") return order.payment_status.includes("refund") || order.fulfillment_status.includes("refund"); return order.fulfillment_type === "manual" || order.fulfillment_status === "manual_pending"; }
function statusLabel(value: string) { const labels: Record<string, string> = { pending: "Pending", paid: "Berhasil", expired: "Kedaluwarsa", failed: "Gagal", cancelled: "Dibatalkan", waiting_payment: "Menunggu Pembayaran", manual_pending: "Manual", processing: "Diproses", dispatching: "Dikirim", success: "Berhasil", needs_review: "Perlu Ditinjau", refunded: "Refund", refund: "Refund", manual_done: "Sukses" }; return labels[value] || value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function statusTone(value: string) { if (["paid", "success", "manual_done"].includes(value)) return "bg-emerald-100 text-emerald-700"; if (["pending", "waiting_payment", "manual_pending"].includes(value)) return "bg-amber-100 text-amber-700"; if (["processing", "dispatching"].includes(value)) return "bg-blue-100 text-blue-700"; if (value.includes("refund")) return "bg-violet-100 text-violet-700"; return "bg-red-100 text-red-700"; }
function statusCountTone(status: StatusTab) { if (status === "success") return "bg-emerald-50 text-emerald-600"; if (status === "failed") return "bg-red-50 text-red-600"; if (status === "pending") return "bg-amber-50 text-amber-600"; return "bg-[#f2f4f7] text-[#667085]"; }
function statusOptions() { return ["pending", "paid", "waiting_payment", "processing", "dispatching", "manual_pending", "success", "failed", "expired", "needs_review", "refunded"]; }
function uniqueOptions(values: string[]) { return Array.from(new Set(values.filter(Boolean))).sort((a, b) => a.localeCompare(b, "id")); }
function parseOrderInputs(order: Order) { try { const parsed = JSON.parse(order.customer_inputs_json || "[]") as Array<{ label?: string; value?: string }>; if (Array.isArray(parsed) && parsed.length) return parsed.filter((item) => item && typeof item.label === "string").map((item) => ({ label: item.label || "Data", value: item.value || "" })); } catch { /* Legacy fallback below. */ } return [{ label: "Tujuan / User ID", value: order.destination }, ...(order.server ? [{ label: "Server / Zone", value: order.server }] : [])]; }
function destinationLabel(order: Order) { return parseOrderInputs(order).map((item) => item.value).filter(Boolean).join(" / ") || "-"; }
function displayPayment(order: Order) { const value = order.payment_channel || order.payment_method || "-"; return value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function maskName(value: string) { const trimmed = value.trim(); return trimmed.length <= 3 ? trimmed : `${trimmed.slice(0, Math.min(5, trimmed.length - 2))}***`; }
function normalizedDate(value: string) { return value.slice(0, 10); }
function parseTimestamp(value: string) { const normalized = value.includes("T") ? value : value.replace(" ", "T") + "Z"; return new Date(normalized); }
function formatDate(value: string) { const date = parseTimestamp(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString("id-ID", { day: "2-digit", month: "short" }); }
function formatTime(value: string) { const date = parseTimestamp(value); return Number.isNaN(date.getTime()) ? "" : date.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" }); }
function formatFullDate(value: string) { const date = parseTimestamp(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString("id-ID", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }); }
function timelineLabel(event: OrderEvent) { if (event.status === "paid") return "Pembayaran diterima"; if (["success", "manual_done"].includes(event.status)) return "Transaksi berhasil"; if (event.status === "dispatching") return "Dikirim ke provider"; if (event.status === "created") return "Pesanan dibuat"; return statusLabel(event.status); }
function prettyPayload(value: string) { try { return JSON.stringify(JSON.parse(value), null, 2); } catch { return value || "{}"; } }
function csvCell(value: string | number) { return `"${String(value).replaceAll('"', '""')}"`; }
function paginationPages(page: number, total: number): Array<number | "…"> { if (total <= 7) return Array.from({ length: total }, (_, index) => index + 1); const values: Array<number | "…"> = [1]; if (page > 4) values.push("…"); for (let value = Math.max(2, page - 1); value <= Math.min(total - 1, page + 1); value += 1) values.push(value); if (page < total - 3) values.push("…"); values.push(total); return values; }
