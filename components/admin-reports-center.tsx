"use client";

import { useCallback, useEffect, useState } from "react";
import { LoaderCircle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { formatRupiah } from "@/lib/store-data";

type Range = "today" | "7d" | "30d" | "90d" | "all";
type Summary = {
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
  topProducts: Array<{ slug: string; name: string; totalOrders: number; fulfilledOrders: number; revenue: number | null }>;
  topCategories: Array<{ category: string; totalOrders: number; fulfilledOrders: number; revenue: number | null }>;
  topCustomers: Array<{ name: string; email: string; orders: number; total: number | null }>;
};

const ranges: Array<{ value: Range; label: string }> = [
  { value: "today", label: "Hari ini" }, { value: "7d", label: "7 hari" }, { value: "30d", label: "30 hari" }, { value: "90d", label: "90 hari" }, { value: "all", label: "Semua" },
];

export function AdminReportsCenter() {
  const [range, setRange] = useState<Range>("30d");
  const [data, setData] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/panel/summary?range=" + range, { cache: "no-store" });
      const payload = await response.json().catch(() => ({})) as Summary & { error?: string };
      if (!response.ok) throw new Error(payload.error || "Laporan gagal dimuat.");
      setData(payload);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Laporan gagal dimuat.");
    } finally { setLoading(false); }
  }, [range]);

  useEffect(() => { void load(); }, [load]);

  return <div className="space-y-3">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <div className="flex flex-wrap gap-1.5">{ranges.map((item) => <button key={item.value} type="button" onClick={() => setRange(item.value)} className={"rounded-md px-2.5 py-1.5 text-[9px] font-bold " + (range === item.value ? "bg-[#155eef] text-white" : "border border-white/10 bg-white/[0.03] text-white/55")}>{item.label}</button>)}</div>
      <Button type="button" variant="outline" onClick={() => void load()} className="border-white/10 bg-white/[0.03] text-white"><RefreshCw className="mr-1.5 size-3.5" />Refresh</Button>
    </div>
    {loading ? <div className="flex min-h-32 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat laporan…</div> : error ? <div className="rounded-lg border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div> : data && <>
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <Card label="Pesanan" value={String(data.metrics.totalOrders)} />
        <Card label="Omzet dibayar" value={data.canViewFinance ? formatRupiah(data.metrics.paidRevenue ?? 0) : "-"} />
        <Card label="Pesanan berhasil" value={String(data.metrics.fulfilledOrders)} />
        <Card label="Pelanggan" value={String(data.metrics.customers)} />
      </div>
      <div className="grid gap-3 lg:grid-cols-3">
        <Rank title="Produk terlaris" rows={data.topProducts.map((x) => ({ name: x.name, sub: x.totalOrders + " order", value: data.canViewFinance ? formatRupiah(x.revenue ?? 0) : "" }))} />
        <Rank title="Kategori terlaris" rows={data.topCategories.map((x) => ({ name: x.category, sub: x.totalOrders + " order", value: data.canViewFinance ? formatRupiah(x.revenue ?? 0) : "" }))} />
        <Rank title="Pelanggan teratas" rows={data.topCustomers.map((x) => ({ name: x.name, sub: x.orders + " order", value: data.canViewFinance ? formatRupiah(x.total ?? 0) : "" }))} />
      </div>
    </>}
  </div>;
}

function Card({ label, value }: { label: string; value: string }) {
  return <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] p-3"><p className="text-[9px] text-white/35">{label}</p><strong className="mt-1 block text-base">{value}</strong></div>;
}
function Rank({ title, rows }: { title: string; rows: Array<{ name: string; sub: string; value: string }> }) {
  return <div className="overflow-hidden rounded-lg border border-white/[0.08]"><div className="border-b border-white/[0.08] px-3 py-2 text-[10px] font-bold">{title}</div><div>{rows.slice(0, 8).map((row, index) => <div key={index} className="flex items-center justify-between gap-3 border-b border-white/[0.06] px-3 py-2 last:border-0"><div className="min-w-0"><p className="truncate text-[10px] font-semibold">{row.name}</p><p className="text-[8px] text-white/35">{row.sub}</p></div><span className="shrink-0 text-[9px] font-bold text-[#d8ff8d]">{row.value}</span></div>)}</div></div>;
}
