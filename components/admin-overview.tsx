"use client";

import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import {
  BarChart3,
  Box,
  CircleDollarSign,
  Clock3,
  LoaderCircle,
  PackageCheck,
  ReceiptText,
  RefreshCw,
  TicketPercent,
  TrendingUp,
  Users,
  WalletCards,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { formatRupiah } from "@/lib/store-data";

type DashboardRange = "today" | "7d" | "30d" | "90d" | "all";

type StatusCount = {
  status: string;
  count: number;
};

type TrendPoint = {
  day: string;
  orders: number;
  revenue: number | null;
};

type RankedProduct = {
  slug: string;
  name: string;
  totalOrders: number;
  fulfilledOrders: number;
  revenue: number | null;
};

type RankedCategory = {
  category: string;
  totalOrders: number;
  fulfilledOrders: number;
  revenue: number | null;
};

type RankedCustomer = {
  name: string;
  email: string;
  orders: number;
  total: number | null;
};

type RecentOrder = {
  id: string;
  referenceId: string;
  productName: string;
  buyerName: string;
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
    totalDiscount: number | null;
    approvedTopups: number | null;
    activeProducts: number;
    customers: number;
    fulfilledOrders: number;
    pendingPayments: number;
    pendingFulfillments: number;
  };
  paymentStatuses: StatusCount[];
  fulfillmentStatuses: StatusCount[];
  chart: TrendPoint[];
  topProducts: RankedProduct[];
  topCategories: RankedCategory[];
  topCustomers: RankedCustomer[];
  recentOrders: RecentOrder[];
};

const ranges: Array<{ value: DashboardRange; label: string }> = [
  { value: "today", label: "Hari ini" },
  { value: "7d", label: "7 hari" },
  { value: "30d", label: "30 hari" },
  { value: "90d", label: "90 hari" },
  { value: "all", label: "Semua" },
];

export function AdminOverview() {
  const [range, setRange] = useState<DashboardRange>("7d");
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
      const response = await fetch("/api/admin/summary?range=" + range, { cache: "no-store" });
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

  const maxOrders = Math.max(1, ...(data?.chart.map((item) => item.orders) ?? [1]));

  if (!data && !error) {
    return (
      <div className="flex min-h-64 items-center justify-center rounded-lg border border-white/[0.08] bg-[#0d1019] text-xs text-white/40">
        <LoaderCircle className="mr-2 size-4 animate-spin" />
        Memuat dashboard analitik…
      </div>
    );
  }

  if (!data) {
    return (
      <div className="rounded-lg border border-red-400/20 bg-red-400/[0.06] p-4 text-xs text-red-100">
        <p>{error}</p>
        <Button type="button" variant="outline" size="sm" onClick={() => void load(true)} className="mt-3 border-red-200/20 text-red-100 hover:bg-red-300/10 hover:text-white">
          <RefreshCw className="size-3.5" />
          Coba lagi
        </Button>
      </div>
    );
  }

  const cards = [
    ...(data.canViewFinance
      ? [
          { label: "Omzet dibayar", value: formatRupiah(data.metrics.paidRevenue ?? 0), Icon: CircleDollarSign, tone: "text-[#d8ff8d]" },
          { label: "Top up disetujui", value: formatRupiah(data.metrics.approvedTopups ?? 0), Icon: WalletCards, tone: "text-sky-200" },
          { label: "Diskon terpakai", value: formatRupiah(data.metrics.totalDiscount ?? 0), Icon: TicketPercent, tone: "text-amber-200" },
        ]
      : []),
    { label: "Pesanan", value: formatNumber(data.metrics.totalOrders), Icon: ReceiptText, tone: "text-white" },
    { label: "Berhasil diproses", value: formatNumber(data.metrics.fulfilledOrders), Icon: PackageCheck, tone: "text-emerald-200" },
    { label: "Menunggu pembayaran", value: formatNumber(data.metrics.pendingPayments), Icon: Clock3, tone: "text-orange-200" },
    { label: "Produk aktif", value: formatNumber(data.metrics.activeProducts), Icon: Box, tone: "text-violet-200" },
    { label: "Pelanggan", value: formatNumber(data.metrics.customers), Icon: Users, tone: "text-blue-200" },
  ];

  return (
    <div className="space-y-4">
      <section className="flex flex-col gap-3 rounded-lg border border-white/[0.08] bg-[#0d1019] p-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2">
            <span className="grid size-7 place-items-center rounded-md bg-[#b9ff35]/10 text-[#d8ff8d]">
              <BarChart3 className="size-3.5" />
            </span>
            <p className="text-sm font-bold">Dashboard analitik</p>
          </div>
          <p className="mt-1 text-[10px] text-white/38">Ringkasan transaksi, katalog, pelanggan, dan aktivitas toko dalam periode yang dipilih.</p>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          <div className="flex flex-wrap gap-1 rounded-md border border-white/[0.08] bg-white/[0.02] p-1">
            {ranges.map((item) => (
              <button
                key={item.value}
                type="button"
                onClick={() => setRange(item.value)}
                className={
                  "h-7 rounded px-2 text-[9px] font-bold transition " +
                  (range === item.value ? "bg-[#b9ff35] text-[#091006]" : "text-white/45 hover:bg-white/[0.07] hover:text-white")
                }
              >
                {item.label}
              </button>
            ))}
          </div>
          <Button type="button" size="icon-sm" variant="outline" onClick={() => void load(true)} disabled={refreshing} className="border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white" aria-label="Muat ulang dashboard">
            <RefreshCw className={"size-3.5 " + (refreshing ? "animate-spin" : "")} />
          </Button>
        </div>
      </section>

      {error && <div className="rounded-md border border-amber-300/20 bg-amber-300/[0.06] px-3 py-2 text-[10px] text-amber-100">{error}</div>}

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {cards.map(({ label, value, Icon, tone }) => (
          <div key={label} className="rounded-lg border border-white/[0.08] bg-[#0d1019] p-3">
            <div className="flex items-start justify-between gap-3">
              <span className={"grid size-7 place-items-center rounded-md bg-white/[0.045] " + tone}>
                <Icon className="size-3.5" />
              </span>
              <span className="text-[9px] text-white/28">{rangeLabel(range)}</span>
            </div>
            <p className="mt-3 text-[10px] text-white/38">{label}</p>
            <p className="mt-0.5 truncate text-lg font-black tracking-[-0.03em]">{value}</p>
          </div>
        ))}
      </section>

      <section className="grid gap-4 xl:grid-cols-[minmax(0,1.7fr)_minmax(260px,.7fr)]">
        <div className="rounded-lg border border-white/[0.08] bg-[#0d1019] p-4">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-xs font-bold">Tren transaksi</p>
              <p className="mt-0.5 text-[10px] text-white/35">Jumlah pesanan per hari. Nilai omzet tersedia untuk Pemilik.</p>
            </div>
            <TrendingUp className="size-4 text-[#d8ff8d]" />
          </div>
          <div className="mt-5 flex h-40 items-end gap-1.5">
            {data.chart.map((point) => {
              const height = Math.max(6, Math.round((point.orders / maxOrders) * 100));
              return (
                <div key={point.day} className="group flex min-w-0 flex-1 flex-col justify-end">
                  <div className="relative flex h-32 items-end">
                    <div
                      className="w-full rounded-t-sm bg-[#b9ff35]/75 transition-colors group-hover:bg-[#b9ff35]"
                      style={{ height: height + "%" }}
                      title={point.orders + " pesanan"}
                    />
                  </div>
                  <p className="mt-1 truncate text-center text-[8px] text-white/30">{formatShortDate(point.day)}</p>
                </div>
              );
            })}
          </div>
          <div className="mt-2 flex items-center justify-between border-t border-white/[0.06] pt-2 text-[9px] text-white/35">
            <span>{formatNumber(data.metrics.totalOrders)} pesanan pada periode ini</span>
            {data.canViewFinance && <span>{formatRupiah(data.metrics.paidRevenue ?? 0)} dibayar</span>}
          </div>
        </div>

        <div className="rounded-lg border border-white/[0.08] bg-[#0d1019] p-4">
          <p className="text-xs font-bold">Antrean operasional</p>
          <p className="mt-0.5 text-[10px] text-white/35">Bagian yang perlu dipantau lebih dulu.</p>
          <div className="mt-4 space-y-2.5">
            <OperationalRow label="Menunggu pembayaran" value={data.metrics.pendingPayments} tone="bg-orange-300" />
            <OperationalRow label="Antrean fulfillment" value={data.metrics.pendingFulfillments} tone="bg-blue-300" />
            <OperationalRow label="Pesanan berhasil" value={data.metrics.fulfilledOrders} tone="bg-emerald-300" />
          </div>
          <div className="mt-4 rounded-md border border-white/[0.08] bg-white/[0.02] px-3 py-2">
            <p className="text-[9px] text-white/34">Status gateway dan provider tetap dipantau dari menu Pesanan serta Pembayaran.</p>
          </div>
        </div>
      </section>

      <section className="grid gap-4 xl:grid-cols-3">
        <RankedList title="Produk terlaris" empty="Belum ada data produk pada periode ini." items={data.topProducts} render={(item) => (
          <div className="flex min-w-0 items-center justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate text-[11px] font-semibold text-white/86">{item.name}</p>
              <p className="mt-0.5 text-[9px] text-white/32">{formatNumber(item.fulfilledOrders)} berhasil dari {formatNumber(item.totalOrders)} pesanan</p>
            </div>
            <div className="shrink-0 text-right">
              {data.canViewFinance && <p className="text-[10px] font-bold text-[#d8ff8d]">{formatRupiah(item.revenue ?? 0)}</p>}
              <p className="text-[9px] text-white/32">{formatNumber(item.totalOrders)} order</p>
            </div>
          </div>
        )} />

        <RankedList title="Kategori terlaris" empty="Belum ada data kategori pada periode ini." items={data.topCategories} render={(item) => (
          <div className="flex min-w-0 items-center justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate text-[11px] font-semibold capitalize text-white/86">{item.category || "Tanpa kategori"}</p>
              <p className="mt-0.5 text-[9px] text-white/32">{formatNumber(item.fulfilledOrders)} transaksi berhasil</p>
            </div>
            <div className="shrink-0 text-right">
              {data.canViewFinance && <p className="text-[10px] font-bold text-[#d8ff8d]">{formatRupiah(item.revenue ?? 0)}</p>}
              <p className="text-[9px] text-white/32">{formatNumber(item.totalOrders)} order</p>
            </div>
          </div>
        )} />

        <div className="rounded-lg border border-white/[0.08] bg-[#0d1019] p-4">
          <p className="text-xs font-bold">Status transaksi</p>
          <p className="mt-0.5 text-[10px] text-white/35">Pembayaran dan pemrosesan pesanan.</p>
          <div className="mt-4 space-y-1.5">
            {data.paymentStatuses.slice(0, 4).map((item) => <StatusRow key={"payment-" + item.status} label={statusLabel(item.status)} value={item.count} />)}
            {data.fulfillmentStatuses.slice(0, 4).map((item) => <StatusRow key={"fulfillment-" + item.status} label={statusLabel(item.status)} value={item.count} />)}
            {data.paymentStatuses.length + data.fulfillmentStatuses.length === 0 && <EmptyState text="Belum ada status transaksi." />}
          </div>
        </div>
      </section>

      {data.canViewFinance && data.topCustomers.length > 0 && (
        <section className="rounded-lg border border-white/[0.08] bg-[#0d1019] p-4">
          <div className="flex items-center gap-2">
            <Users className="size-3.5 text-[#d8ff8d]" />
            <div>
              <p className="text-xs font-bold">Pelanggan paling aktif</p>
              <p className="text-[10px] text-white/35">Hanya terlihat oleh Pemilik.</p>
            </div>
          </div>
          <div className="mt-3 grid divide-y divide-white/[0.07] rounded-md border border-white/[0.08] bg-white/[0.015] sm:grid-cols-2 sm:divide-x sm:divide-y-0">
            {data.topCustomers.slice(0, 4).map((item) => (
              <div key={item.email} className="flex min-w-0 items-center justify-between gap-3 p-3">
                <div className="min-w-0">
                  <p className="truncate text-[11px] font-semibold">{item.name}</p>
                  <p className="truncate text-[9px] text-white/32">{item.email}</p>
                </div>
                <div className="shrink-0 text-right">
                  <p className="text-[10px] font-bold text-[#d8ff8d]">{formatRupiah(item.total ?? 0)}</p>
                  <p className="text-[9px] text-white/32">{formatNumber(item.orders)} order</p>
                </div>
              </div>
            ))}
          </div>
        </section>
      )}

      <section className="overflow-hidden rounded-lg border border-white/[0.08] bg-[#0d1019]">
        <div className="flex items-center justify-between gap-3 border-b border-white/[0.08] px-4 py-3">
          <div>
            <p className="text-xs font-bold">Pesanan terbaru</p>
            <p className="mt-0.5 text-[10px] text-white/35">Aktivitas terbaru yang masuk ke toko.</p>
          </div>
          <span className="rounded-md bg-white/[0.04] px-2 py-1 text-[9px] text-white/45">{data.recentOrders.length} terbaru</span>
        </div>
        {data.recentOrders.length ? (
          <div className="overflow-x-auto">
            <table className="min-w-[720px] w-full text-left">
              <thead className="border-b border-white/[0.07] bg-white/[0.015] text-[9px] uppercase tracking-[0.08em] text-white/32">
                <tr>
                  <th className="px-4 py-2.5 font-bold">Pesanan</th>
                  <th className="px-4 py-2.5 font-bold">Pelanggan</th>
                  <th className="px-4 py-2.5 font-bold">Status</th>
                  {data.canViewFinance && <th className="px-4 py-2.5 text-right font-bold">Total</th>}
                  <th className="px-4 py-2.5 text-right font-bold">Waktu</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-white/[0.07]">
                {data.recentOrders.map((item) => (
                  <tr key={item.id} className="text-[11px] text-white/72">
                    <td className="px-4 py-3">
                      <p className="max-w-56 truncate font-semibold text-white">{item.productName}</p>
                      <p className="mt-0.5 font-mono text-[9px] text-white/32">{item.referenceId}</p>
                    </td>
                    <td className="px-4 py-3">
                      <p className="max-w-40 truncate">{item.buyerName}</p>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        <StatusPill value={item.paymentStatus} />
                        <StatusPill value={item.fulfillmentStatus} />
                      </div>
                    </td>
                    {data.canViewFinance && <td className="px-4 py-3 text-right font-bold text-[#d8ff8d]">{formatRupiah(item.total ?? 0)}</td>}
                    <td className="px-4 py-3 text-right text-[10px] text-white/35">{formatDateTime(item.createdAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="p-8">
            <EmptyState text="Belum ada pesanan pada periode ini." />
          </div>
        )}
      </section>
    </div>
  );
}

function OperationalRow({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone: string;
}) {
  return (
    <div className="flex items-center justify-between gap-3">
      <div className="flex min-w-0 items-center gap-2">
        <span className={"size-1.5 shrink-0 rounded-full " + tone} />
        <span className="truncate text-[10px] text-white/52">{label}</span>
      </div>
      <strong className="text-xs">{formatNumber(value)}</strong>
    </div>
  );
}

function RankedList<T>({
  title,
  empty,
  items,
  render,
}: {
  title: string;
  empty: string;
  items: T[];
  render: (item: T) => ReactNode;
}) {
  return (
    <div className="rounded-lg border border-white/[0.08] bg-[#0d1019] p-4">
      <p className="text-xs font-bold">{title}</p>
      <div className="mt-3 divide-y divide-white/[0.07] rounded-md border border-white/[0.08] bg-white/[0.015]">
        {items.length ? items.slice(0, 5).map((item, index) => (
          <div key={index} className="p-3">{render(item)}</div>
        )) : <div className="p-4"><EmptyState text={empty} /></div>}
      </div>
    </div>
  );
}

function StatusRow({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-md border border-white/[0.07] bg-white/[0.015] px-2.5 py-2">
      <span className="text-[10px] text-white/53">{label}</span>
      <strong className="text-[11px]">{formatNumber(value)}</strong>
    </div>
  );
}

function StatusPill({ value }: { value: string }) {
  return (
    <span className={"rounded-md px-1.5 py-0.5 text-[8px] font-bold " + statusTone(value)}>
      {statusLabel(value)}
    </span>
  );
}

function EmptyState({ text }: { text: string }) {
  return <p className="py-2 text-center text-[10px] text-white/30">{text}</p>;
}

function statusTone(value: string) {
  const normalized = value.toLowerCase();
  if (normalized === "paid" || normalized === "success" || normalized === "completed" || normalized === "approved") return "bg-emerald-300/10 text-emerald-200";
  if (normalized === "pending" || normalized === "waiting_payment" || normalized === "processing") return "bg-amber-300/10 text-amber-200";
  if (normalized === "failed" || normalized === "rejected" || normalized === "cancelled" || normalized === "expired") return "bg-red-300/10 text-red-200";
  return "bg-white/[0.06] text-white/50";
}

function statusLabel(value: string) {
  const labels: Record<string, string> = {
    waiting_payment: "menunggu bayar",
    processing: "diproses",
    manual_review: "menunggu admin",
    paid: "dibayar",
    success: "berhasil",
    failed: "gagal",
    pending: "pending",
    approved: "disetujui",
    rejected: "ditolak",
    cancelled: "dibatalkan",
    expired: "kedaluwarsa",
    completed: "selesai",
  };
  return labels[value] || value.replaceAll("_", " ");
}

function formatNumber(value: number) {
  return new Intl.NumberFormat("id-ID").format(Number(value || 0));
}

function formatShortDate(value: string) {
  const parts = value.split("-");
  return parts.length === 3 ? parts[2] + "/" + parts[1] : value;
}

function formatDateTime(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}

function rangeLabel(range: DashboardRange) {
  const item = ranges.find((value) => value.value === range);
  return item?.label || "Periode";
}
