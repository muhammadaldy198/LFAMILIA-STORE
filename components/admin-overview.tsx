"use client";

import { useEffect, useMemo, useState } from "react";
import { Box, CircleDollarSign, LoaderCircle, PackageCheck, ReceiptText, Users } from "lucide-react";
import { formatRupiah } from "@/lib/store-data";

type Summary = {
  role: "owner" | "staff";
  canViewFinance: boolean;
  metrics: { todayOrders: number; todayRevenue: number | null; activeProducts: number; customers: number; successfulOrders: number };
  statuses: Array<{ status: string; count: number }>;
  chart: Array<{ day: string; orders: number; revenue: number | null }>;
};

const labels: Record<string, string> = {
  success: "Berhasil", processing: "Diproses", pending: "Menunggu", waiting_payment: "Menunggu bayar",
  manual_pending: "Antrean manual", needs_review: "Perlu diperiksa", failed: "Gagal", expired: "Kedaluwarsa",
};

export function AdminOverview() {
  const [data, setData] = useState<Summary | null>(null);
  const [error, setError] = useState("");
  useEffect(() => { void fetch("/api/panel/summary", { cache: "no-store" }).then(async (response) => { const json = await response.json(); if (!response.ok) throw new Error(json.error); setData(json); }).catch((reason) => setError(reason instanceof Error ? reason.message : "Ringkasan gagal dimuat.")); }, []);
  const maxOrders = useMemo(() => Math.max(1, ...(data?.chart.map((item) => item.orders) ?? [1])), [data]);
  if (!data && !error) return <div className="panel flex min-h-56 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat data toko…</div>;
  if (error) return <div className="panel p-6 text-sm text-red-200">{error}</div>;
  if (!data) return null;
  const cards = [
    ...(data.canViewFinance ? [["Omzet hari ini", formatRupiah(data.metrics.todayRevenue ?? 0), CircleDollarSign]] as const : []),
    ["Pesanan hari ini", String(data.metrics.todayOrders), ReceiptText],
    ["Pesanan berhasil", String(data.metrics.successfulOrders), PackageCheck],
    ["Produk aktif", String(data.metrics.activeProducts), Box],
    ["Pelanggan unik", String(data.metrics.customers), Users],
  ] as const;
  return <div className="space-y-5"><div className={`grid grid-cols-2 gap-3 ${cards.length >= 5 ? "xl:grid-cols-5" : "xl:grid-cols-4"}`}>{cards.map(([label, value, Icon]) => <div key={label} className="panel p-4"><span className="grid size-8 place-items-center rounded-lg bg-[#b9ff35]/10 text-[#b9ff35]"><Icon className="size-4" /></span><span className="mt-5 block text-[10px] text-white/32">{label}</span><strong className="mt-1 block text-xl font-black">{value}</strong></div>)}</div><div className="grid gap-5 xl:grid-cols-[1.35fr_.65fr]"><div className="panel p-5"><div><h2 className="font-bold">Aktivitas 7 hari</h2><p className="mt-1 text-[10px] text-white/30">Berdasarkan pesanan yang tercatat di D1.</p></div><div className="mt-8 flex h-44 items-end gap-2">{data.chart.map((item) => <div key={item.day} className="group flex h-full flex-1 flex-col justify-end"><div className="mb-2 hidden text-center text-[8px] text-white/35 sm:block">{item.orders}</div><div className="w-full rounded-t-md bg-gradient-to-t from-[#b9ff35]/25 to-[#b9ff35]" style={{ height: `${Math.max(4, item.orders / maxOrders * 100)}%` }} /><span className="mt-2 block truncate text-center text-[8px] text-white/25">{new Date(`${item.day}T00:00:00Z`).toLocaleDateString("id-ID", { weekday: "short" })}</span></div>)}</div></div><div className="panel p-5"><h2 className="font-bold">Status pesanan</h2><div className="mt-5 space-y-4">{data.statuses.length ? data.statuses.map((item) => <div key={item.status}><div className="flex justify-between text-[10px]"><span className="text-white/40">{labels[item.status] ?? item.status}</span><strong>{item.count}</strong></div><div className="mt-2 h-1.5 rounded-full bg-white/[0.06]"><div className="h-full rounded-full bg-[#b9ff35]" style={{ width: `${Math.min(100, item.count / Math.max(1, data.metrics.todayOrders + data.metrics.successfulOrders) * 100)}%` }} /></div></div>) : <p className="text-xs text-white/30">Belum ada pesanan.</p>}</div></div></div></div>;
}
