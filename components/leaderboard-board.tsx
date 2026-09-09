"use client";

import { useEffect, useState } from "react";
import { Crown, LoaderCircle, Medal, Trophy } from "lucide-react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { formatRupiah } from "@/lib/store-data";

type Entry = { rank: number; name: string; orderCount: number; totalSpent: number };

export function LeaderboardBoard() {
  const [data, setData] = useState<Record<"month" | "all", Entry[]>>({ month: [], all: [] });
  const [loading, setLoading] = useState(true);
  useEffect(() => { void Promise.all((["month", "all"] as const).map(async (period) => { const response = await fetch(`/api/leaderboard?period=${period}`, { cache: "no-store" }); const result = await response.json().catch(() => ({})) as { entries?: Entry[] }; return [period, result.entries ?? []] as const; })).then((values) => setData(Object.fromEntries(values) as Record<"month" | "all", Entry[]>)).catch(() => setData({ month: [], all: [] })).finally(() => setLoading(false)); }, []);
  if (loading) return <div className="flex min-h-48 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat leaderboard…</div>;
  return <Tabs defaultValue="month"><TabsList className="h-11 rounded-xl border border-white/[0.08] bg-[#0d1019] p-1"><TabsTrigger value="month" className="rounded-lg px-4 text-xs">Bulan ini</TabsTrigger><TabsTrigger value="all" className="rounded-lg px-4 text-xs">Sepanjang waktu</TabsTrigger></TabsList><TabsContent value="month" className="mt-5"><Ranking entries={data.month} /></TabsContent><TabsContent value="all" className="mt-5"><Ranking entries={data.all} /></TabsContent></Tabs>;
}

function Ranking({ entries }: { entries: Entry[] }) { if (!entries.length) return <div className="rounded-[26px] border border-dashed border-white/10 py-16 text-center"><Trophy className="mx-auto size-9 text-white/18" /><p className="mt-4 text-sm font-bold text-white/50">Belum ada peringkat</p><p className="mt-2 text-xs text-white/30">Pelanggan yang mengaktifkan leaderboard akan tampil setelah transaksi berhasil.</p></div>; return <div className="space-y-3">{entries.map((entry) => <article key={`${entry.rank}-${entry.name}`} className={`flex items-center gap-4 rounded-2xl border p-4 sm:p-5 ${entry.rank <= 3 ? "border-[#b9ff35]/20 bg-[#b9ff35]/[0.045]" : "border-white/[0.08] bg-[#0d1019]"}`}><span className={`grid size-11 shrink-0 place-items-center rounded-2xl font-black ${entry.rank === 1 ? "bg-amber-300 text-amber-950" : entry.rank === 2 ? "bg-slate-300 text-slate-900" : entry.rank === 3 ? "bg-orange-700 text-orange-100" : "bg-white/[0.06] text-white/42"}`}>{entry.rank === 1 ? <Crown className="size-5" /> : entry.rank <= 3 ? <Medal className="size-5" /> : entry.rank}</span><div className="min-w-0 flex-1"><h2 className="truncate text-sm font-black sm:text-base">{entry.name}</h2><p className="mt-1 text-[10px] text-white/35">{entry.orderCount} transaksi berhasil</p></div><div className="text-right"><span className="block text-[9px] uppercase tracking-wider text-white/28">Total belanja</span><strong className="mt-1 block text-xs text-[#d8ff8d] sm:text-sm">{formatRupiah(entry.totalSpent)}</strong></div></article>)}</div>; }
