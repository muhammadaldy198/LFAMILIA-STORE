import Link from "next/link";
import { ArrowRight, Clock3, CreditCard, Gamepad2, Headphones, ReceiptText, ShieldCheck, Sparkles, Zap } from "lucide-react";
import { Button } from "@/components/ui/button";
import { HomeProductBrowser } from "@/components/home-product-browser";
import { QuickTools } from "@/components/quick-tools";
import { StoreLayout } from "@/components/store-layout";

const steps = [
  { number: "01", title: "Pilih produk", text: "Cari game atau voucher yang kamu inginkan.", icon: Gamepad2 },
  { number: "02", title: "Isi data", text: "Masukkan ID dan pilih nominal top up.", icon: ReceiptText },
  { number: "03", title: "Bayar aman", text: "Selesaikan pembayaran sesuai total pesanan.", icon: CreditCard },
  { number: "04", title: "Pesanan diproses", text: "Status dapat dipantau melalui nomor invoice.", icon: Zap },
];

export default function Home() {
  return (
    <StoreLayout>
      <main>
        <section className="relative overflow-hidden border-b border-white/[0.07]">
          <div className="hero-glow" aria-hidden="true" />
          <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
            <div className="relative overflow-hidden rounded-[28px] border border-white/[0.09] bg-[#10131b] p-6 sm:p-9 lg:grid lg:grid-cols-[1.15fr_.85fr] lg:items-center lg:gap-10 lg:p-12">
              <div className="absolute inset-0 bg-[radial-gradient(circle_at_80%_20%,rgba(185,255,53,.13),transparent_34%)]" />
              <div className="relative z-10">
                <p className="inline-flex items-center gap-2 rounded-full border border-[#b9ff35]/20 bg-[#b9ff35]/[0.08] px-3 py-1.5 text-[10px] font-extrabold uppercase tracking-[0.18em] text-[#d9ff92]"><Sparkles className="size-3.5" /> LFAMILIA STORE</p>
                <h1 className="mt-5 max-w-3xl text-balance text-[clamp(2.55rem,7vw,5.5rem)] font-black leading-[0.92] tracking-[-0.06em]">Top up favoritmu,<span className="block text-[#b9ff35]">sat set tanpa ribet.</span></h1>
                <p className="mt-5 max-w-xl text-sm leading-7 text-white/48 sm:text-base">Game, voucher, dan kalkulator dalam satu website LFAMILIA yang nyaman digunakan kapan saja.</p>
                <div className="mt-7 flex flex-col gap-3 sm:flex-row">
                  <Button asChild className="h-12 rounded-xl bg-[#b9ff35] px-6 font-black text-[#091006] hover:bg-[#d0ff75]"><Link href="#produk"><Gamepad2 className="mr-2 size-4" />Top up sekarang</Link></Button>
                  <Button asChild variant="outline" className="h-12 rounded-xl border-white/10 bg-white/[0.035] px-6 text-white hover:bg-white/[0.08] hover:text-white"><Link href="/track"><ReceiptText className="mr-2 size-4 text-[#b9ff35]" />Cek transaksi</Link></Button>
                </div>
                <div className="mt-7 flex flex-wrap gap-x-5 gap-y-2 text-[10px] font-medium text-white/35 sm:text-[11px]"><span className="inline-flex items-center gap-1.5"><Clock3 className="size-3.5 text-[#b9ff35]" /> Pemesanan 24/7</span><span className="inline-flex items-center gap-1.5"><ShieldCheck className="size-3.5 text-[#b9ff35]" /> Data terlindungi</span><span className="inline-flex items-center gap-1.5"><Headphones className="size-3.5 text-[#b9ff35]" /> Bantuan pelanggan</span></div>
              </div>

              <div className="relative z-10 mt-9 lg:mt-0">
                <div className="rounded-[24px] border border-white/10 bg-[#090b11]/80 p-4 shadow-2xl shadow-black/30 sm:p-5">
                  <div className="flex items-center justify-between border-b border-white/[0.08] pb-4"><div><p className="text-[9px] uppercase tracking-[0.2em] text-white/30">Pesanan populer</p><strong className="mt-1 block text-sm">Mobile Legends</strong></div><span className="rounded-full bg-[#b9ff35]/10 px-3 py-1.5 text-[9px] font-bold text-[#cfff72]">PROSES CEPAT</span></div>
                  <div className="mt-4 grid grid-cols-2 gap-3">{[["59 Diamonds", "Produk"], ["User ID + Zone", "Tujuan"], ["iPaymu", "Pembayaran"], ["24/7", "Otomatis"]].map(([value, label]) => <div key={label} className="rounded-2xl border border-white/[0.07] bg-white/[0.03] p-4"><span className="text-[9px] uppercase tracking-wider text-white/27">{label}</span><strong className="mt-2 block text-xs sm:text-sm">{value}</strong></div>)}</div>
                  <div className="mt-4 rounded-2xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.07] p-4"><div className="flex items-center justify-between text-xs font-bold text-[#d9ff92]"><span>LFAMILIA Secure Flow</span><ShieldCheck className="size-4" /></div><div className="mt-3 grid grid-cols-4 gap-1.5">{[0, 1, 2, 3].map((item) => <span key={item} className="h-1.5 rounded-full bg-[#b9ff35]" />)}</div></div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section className="mx-auto max-w-7xl px-4 pt-12 sm:px-6 sm:pt-16 lg:px-8">
          <div className="mb-6"><p className="eyebrow">Menu cepat</p><h2 className="text-2xl font-black tracking-tight sm:text-3xl">Semua yang kamu butuhkan</h2></div>
          <QuickTools />
        </section>

        <HomeProductBrowser />

        <section className="border-y border-white/[0.07] bg-white/[0.018]">
          <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p className="eyebrow">Cara top up</p><h2 className="section-title">Empat langkah sederhana</h2></div><Link href="/faq" className="inline-flex items-center gap-2 text-xs font-bold text-[#cfff72]">Pelajari lebih lanjut <ArrowRight className="size-4" /></Link></div>
            <div className="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{steps.map(({ number, title, text, icon: Icon }) => <article key={number} className="rounded-2xl border border-white/[0.08] bg-[#0d1017] p-5"><div className="flex items-center justify-between"><span className="grid size-10 place-items-center rounded-xl bg-[#b9ff35]/[0.09] text-[#cfff72]"><Icon className="size-5" /></span><span className="font-mono text-[10px] text-white/20">{number}</span></div><h3 className="mt-5 text-sm font-bold">{title}</h3><p className="mt-2 text-xs leading-5 text-white/36">{text}</p></article>)}</div>
          </div>
        </section>

        <section className="mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
          <div className="flex flex-col items-start justify-between gap-6 overflow-hidden rounded-[28px] border border-[#b9ff35]/20 bg-[#b9ff35]/[0.07] p-7 sm:flex-row sm:items-center sm:p-10"><div><p className="text-[10px] font-bold uppercase tracking-[0.2em] text-[#cfff72]">Butuh bantuan?</p><h2 className="mt-3 text-2xl font-black tracking-tight sm:text-3xl">Tim LFAMILIA siap membantu.</h2><p className="mt-3 max-w-xl text-sm leading-6 text-white/42">Sampaikan pertanyaan mengenai produk, pembayaran, atau status pesanan melalui pusat bantuan.</p></div><Button asChild className="h-12 shrink-0 rounded-xl bg-[#b9ff35] px-6 font-black text-[#091006] hover:bg-[#d0ff75]"><Link href="/contact"><Headphones className="mr-2 size-4" />Hubungi Kami</Link></Button></div>
        </section>
      </main>
    </StoreLayout>
  );
}
