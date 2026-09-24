"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { Copy, Percent, TicketPercent } from "lucide-react";
import { Button } from "@/components/ui/button";
import { formatRupiah } from "@/lib/store-data";
import type { DiscountVoucher } from "@/lib/server/promotions";

export function PromotionShowcase({ full = false }: { full?: boolean }) {
  const [vouchers, setVouchers] = useState<DiscountVoucher[]>([]);
  const [loadError, setLoadError] = useState("");
  const [reloadKey, setReloadKey] = useState(0);
  useEffect(() => {
    let active = true;
    void fetch("/api/promotions")
      .then(async (response) => {
        const data = await response.json().catch(() => ({})) as { vouchers?: DiscountVoucher[]; error?: string };
        if (!response.ok) throw new Error(data.error || "Promo gagal dimuat.");
        return data;
      })
      .then((data) => { if (active) { setVouchers(data.vouchers ?? []); setLoadError(""); } })
      .catch((reason) => { if (active) setLoadError(reason instanceof Error ? reason.message : "Promo gagal dimuat."); });
    return () => { active = false; };
  }, [reloadKey]);
  const shown = useMemo(() => full ? vouchers : vouchers.slice(0, 4), [vouchers, full]);
  return <section className={full ? "" : "mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8"}><div className="flex items-end justify-between gap-4"><div><p className="eyebrow">Potongan khusus</p><h2 className={full ? "section-title" : "text-2xl font-black tracking-tight sm:text-3xl"}>Voucher Diskon</h2><p className="mt-2 text-xs text-white/36">Salin kode yang aktif lalu masukkan saat checkout.</p></div>{!full && <Link href="/promo" className="text-xs font-bold text-[#cfff72]">Lihat semua</Link>}</div><div className="mt-6">{loadError ? <div className="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/[0.025] px-4 py-3 text-[10px] text-white/40"><span>{loadError}</span><button type="button" onClick={() => setReloadKey((value) => value + 1)} className="font-bold text-[#d8ff8d]">Muat ulang</button></div> : shown.length ? <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{shown.map((item) => <article key={item.id} className="rounded-2xl border border-white/[0.09] bg-[#0d1019] p-4"><div className="flex items-center justify-between"><span className="grid size-9 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#b9ff35]"><Percent className="size-4" /></span><span className="text-[9px] text-white/30">hingga {new Date(item.endsAt).toLocaleDateString("id-ID", { day: "numeric", month: "short" })}</span></div><h3 className="mt-4 text-sm font-bold">{item.name}</h3><p className="mt-2 min-h-10 text-[10px] leading-5 text-white/38">{item.description || `Minimum transaksi ${formatRupiah(item.minPurchase)}`}</p><button type="button" onClick={() => void navigator.clipboard.writeText(item.code)} className="mt-4 flex w-full items-center justify-between rounded-xl border border-dashed border-[#b9ff35]/35 bg-[#b9ff35]/[0.06] px-3 py-2.5 font-mono text-xs font-bold text-[#d8ff8d]"><span>{item.code}</span><Copy className="size-3.5" /></button></article>)}</div> : <EmptyPromo />}</div></section>;
}

function EmptyPromo() { return <div className="rounded-2xl border border-dashed border-white/10 bg-white/[0.015] px-5 py-10 text-center"><TicketPercent className="mx-auto size-6 text-white/20" /><p className="mt-3 text-xs text-white/36">Belum ada voucher diskon yang sedang aktif.</p><Button asChild variant="outline" size="sm" className="mt-4 rounded-lg border-white/10 bg-white/[0.03] text-white"><Link href="/catalog">Buka katalog</Link></Button></div>; }
