import Link from "next/link";
import { ArrowRight, BadgeCheck, Clock3, CreditCard, Gamepad2, Search, ShieldCheck, Zap } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ProductCard } from "@/components/product-card";
import { StoreLayout } from "@/components/store-layout";
import { products } from "@/lib/store-data";

const benefits = [
  { icon: Zap, title: "Proses otomatis", text: "Pesanan diteruskan setelah pembayaran terverifikasi." },
  { icon: ShieldCheck, title: "Pembayaran aman", text: "Rencana pembayaran QRIS diproses melalui Midtrans." },
  { icon: Clock3, title: "Bisa kapan saja", text: "Website menerima pesanan online selama 24 jam." },
];

export default function Home() {
  const popular = products.filter((product) => product.popular).slice(0, 6);
  return (
    <StoreLayout>
      <main>
        <section className="relative overflow-hidden">
          <div className="hero-glow" aria-hidden="true" />
          <div className="mx-auto grid max-w-7xl items-center gap-12 px-4 pb-20 pt-14 sm:px-6 sm:pb-24 sm:pt-20 lg:grid-cols-[1.08fr_.92fr] lg:px-8 lg:py-28">
            <div className="relative z-10">
              <p className="mb-5 inline-flex items-center gap-2 rounded-full border border-[#b9ff35]/25 bg-[#b9ff35]/[0.08] px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.2em] text-[#d4ff83] sm:text-[11px]"><span className="size-1.5 rounded-full bg-[#b9ff35] shadow-[0_0_10px_#b9ff35]" /> Top up game Indonesia</p>
              <h1 className="max-w-4xl text-balance text-[clamp(3.15rem,9vw,7rem)] font-black leading-[0.86] tracking-[-0.07em]">MAIN LEBIH LAMA.<span className="mt-2 block text-[#b9ff35]">ISI LEBIH CEPAT.</span></h1>
              <p className="mt-7 max-w-xl text-base leading-7 text-white/56 sm:text-lg sm:leading-8">Top up game dan beli voucher digital dalam beberapa langkah. Cepat, praktis, dan mudah dipantau dari HP.</p>
              <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                <Button asChild className="h-12 rounded-xl bg-[#b9ff35] px-6 font-bold text-[#091006] hover:bg-[#d0ff75]"><Link href="/catalog"><Gamepad2 className="mr-2 size-4" />Pilih produk</Link></Button>
                <Button asChild variant="outline" className="h-12 rounded-xl border-white/12 bg-white/[0.035] px-6 text-white hover:bg-white/[0.08] hover:text-white"><Link href="/track"><Search className="mr-2 size-4" />Cek transaksi</Link></Button>
              </div>
              <div className="mt-8 flex flex-wrap gap-x-5 gap-y-2 text-[11px] text-white/38"><span className="inline-flex items-center gap-1.5"><BadgeCheck className="size-3.5 text-[#b9ff35]" /> Tanpa registrasi untuk membeli</span><span className="inline-flex items-center gap-1.5"><CreditCard className="size-3.5 text-[#b9ff35]" /> QRIS segera tersedia</span></div>
            </div>

            <div className="relative mx-auto hidden w-full max-w-md lg:block">
              <div className="absolute -inset-10 rounded-full bg-[#b9ff35]/10 blur-3xl" />
              <div className="relative rotate-2 rounded-[34px] border border-white/10 bg-[#0d1019]/95 p-5 shadow-2xl shadow-black/50">
                <div className="flex items-center justify-between border-b border-white/[0.08] pb-4"><div><p className="text-[10px] uppercase tracking-[0.2em] text-white/32">Pesanan demo</p><p className="mt-1 text-sm font-bold">Mobile Legends</p></div><span className="rounded-full bg-[#b9ff35]/10 px-3 py-1.5 text-[10px] font-bold text-[#cfff72]">SIAP DIPROSES</span></div>
                <div className="mt-5 grid grid-cols-2 gap-3">
                  {["59 Diamonds", "ID •••• 6789", "QRIS", "Rp17.624"].map((item, index) => <div key={item} className="rounded-2xl border border-white/[0.08] bg-white/[0.035] p-4"><span className="text-[9px] uppercase tracking-wider text-white/28">{["Produk", "Tujuan", "Pembayaran", "Total"][index]}</span><strong className="mt-2 block text-sm">{item}</strong></div>)}
                </div>
                <div className="mt-5 rounded-2xl bg-[#b9ff35] p-4 text-[#091006]"><div className="flex items-center justify-between"><span className="text-xs font-bold">Alur otomatis</span><Zap className="size-4" /></div><div className="mt-3 h-1.5 overflow-hidden rounded-full bg-black/15"><div className="h-full w-3/4 rounded-full bg-[#091006]" /></div><p className="mt-2 text-[10px] font-medium opacity-65">Bayar → verifikasi → produk dikirim</p></div>
              </div>
            </div>
          </div>
        </section>

        <section className="border-y border-white/[0.07] bg-white/[0.018]"><div className="mx-auto grid max-w-7xl grid-cols-3 px-4 py-6 sm:px-6 lg:px-8">{[["9+", "Produk demo"], ["24/7", "Pemesanan"], ["QRIS", "Pembayaran"]].map(([value, label]) => <div key={value} className="border-r border-white/[0.08] px-2 text-center last:border-r-0 sm:px-6"><strong className="block text-xl font-black sm:text-2xl">{value}</strong><span className="mt-1 block text-[9px] text-white/32 sm:text-xs">{label}</span></div>)}</div></section>

        <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
          <div className="mb-8 flex items-end justify-between gap-4"><div><p className="eyebrow">Paling dicari</p><h2 className="section-title">Produk populer</h2></div><Link href="/catalog" className="hidden items-center gap-2 text-sm font-bold text-[#cfff72] hover:text-white sm:flex">Semua produk <ArrowRight className="size-4" /></Link></div>
          <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">{popular.map((product) => <ProductCard key={product.slug} product={product} />)}</div>
          <Button asChild variant="outline" className="mt-6 w-full rounded-xl border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] sm:hidden"><Link href="/catalog">Lihat semua produk <ArrowRight className="ml-2 size-4" /></Link></Button>
        </section>

        <section className="mx-auto max-w-7xl px-4 pb-20 sm:px-6 sm:pb-28 lg:px-8">
          <div className="overflow-hidden rounded-[30px] border border-white/[0.09] bg-[#0b0e16] p-6 sm:p-10">
            <p className="eyebrow">Kenapa LFAMILIA</p><h2 className="section-title max-w-xl">Dibuat supaya top up tidak merepotkan.</h2>
            <div className="mt-10 grid gap-5 md:grid-cols-3">{benefits.map(({ icon: Icon, title, text }, index) => <article key={title} className="rounded-2xl border border-white/[0.08] bg-white/[0.025] p-5"><div className="flex items-center justify-between"><span className="grid size-10 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#b9ff35]"><Icon className="size-5" /></span><span className="font-mono text-[10px] text-white/20">0{index + 1}</span></div><h3 className="mt-6 font-bold">{title}</h3><p className="mt-2 text-sm leading-6 text-white/40">{text}</p></article>)}</div>
          </div>
        </section>
      </main>
    </StoreLayout>
  );
}
