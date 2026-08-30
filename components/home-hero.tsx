"use client";

import Link from "next/link";
import { Clock3, Gamepad2, Headphones, ReceiptText, ShieldCheck, Sparkles } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useStorefront } from "@/hooks/use-storefront";

export function HomeHero() {
  const { settings } = useStorefront();
  if (!settings.bannerEnabled) return null;
  return (
    <section className="relative overflow-hidden border-b border-white/[0.07]">
      <div className="hero-glow" aria-hidden="true" />
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
        <div className="relative overflow-hidden rounded-[28px] border border-white/[0.09] bg-[#10131b] p-6 sm:p-9 lg:grid lg:min-h-[430px] lg:grid-cols-[1.1fr_.9fr] lg:items-center lg:gap-10 lg:p-12">
          {settings.bannerImageUrl && <div className="absolute inset-0 bg-cover bg-center opacity-35" style={{ backgroundImage: `url(${JSON.stringify(settings.bannerImageUrl).slice(1, -1)})` }} />}
          <div className="absolute inset-0 bg-[linear-gradient(90deg,#10131b_20%,rgba(16,19,27,.8)_55%,rgba(16,19,27,.25))]" />
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_80%_20%,rgba(185,255,53,.13),transparent_34%)]" />
          <div className="relative z-10">
            <p className="inline-flex items-center gap-2 rounded-full border border-[#b9ff35]/20 bg-[#b9ff35]/[0.08] px-3 py-1.5 text-[10px] font-extrabold uppercase tracking-[0.18em] text-[#d9ff92]"><Sparkles className="size-3.5" /> {settings.bannerEyebrow}</p>
            <h1 className="mt-5 max-w-3xl text-balance text-[clamp(2.55rem,7vw,5.5rem)] font-black leading-[0.92] tracking-[-0.06em]">{settings.bannerTitle}<span className="block text-[#b9ff35]">{settings.bannerHighlight}</span></h1>
            <p className="mt-5 max-w-xl text-sm leading-7 text-white/52 sm:text-base">{settings.bannerDescription}</p>
            <div className="mt-7 flex flex-col gap-3 sm:flex-row">
              <Button asChild className="h-12 rounded-xl bg-[#b9ff35] px-6 font-black text-[#091006] hover:bg-[#d0ff75]"><Link href={settings.bannerCtaHref}><Gamepad2 className="mr-2 size-4" />{settings.bannerCtaLabel}</Link></Button>
              <Button asChild variant="outline" className="h-12 rounded-xl border-white/10 bg-black/20 px-6 text-white hover:bg-white/[0.08] hover:text-white"><Link href="/track"><ReceiptText className="mr-2 size-4 text-[#b9ff35]" />Cek transaksi</Link></Button>
            </div>
            <div className="mt-7 flex flex-wrap gap-x-5 gap-y-2 text-[10px] font-medium text-white/40 sm:text-[11px]"><span className="inline-flex items-center gap-1.5"><Clock3 className="size-3.5 text-[#b9ff35]" /> Pemesanan 24/7</span><span className="inline-flex items-center gap-1.5"><ShieldCheck className="size-3.5 text-[#b9ff35]" /> Pembayaran terlindungi</span><span className="inline-flex items-center gap-1.5"><Headphones className="size-3.5 text-[#b9ff35]" /> Bantuan pelanggan</span></div>
          </div>
          <div className="relative z-10 mt-10 hidden justify-end lg:flex"><div className="w-72 rounded-[28px] border border-white/10 bg-black/35 p-5 backdrop-blur-md"><p className="text-[9px] uppercase tracking-[0.2em] text-white/35">Layanan LFAMILIA</p><div className="mt-5 space-y-3">{[["Top Up Game", "Proses otomatis"], ["Voucher Digital", "Pengiriman kode"], ["Voucher Diskon", "Kode promo aktif"], ["Cek Transaksi", "Status real-time"]].map(([title, detail]) => <div key={title} className="rounded-2xl border border-white/[0.08] bg-white/[0.04] p-4"><strong className="text-xs">{title}</strong><span className="mt-1 block text-[9px] text-white/35">{detail}</span></div>)}</div></div></div>
        </div>
      </div>
    </section>
  );
}
