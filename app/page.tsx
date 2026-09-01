import Link from "next/link";
import { ArrowRight, CreditCard, Gamepad2, Headphones, ReceiptText, Zap } from "lucide-react";
import { Button } from "@/components/ui/button";
import { HomeProductBrowser } from "@/components/home-product-browser";
import { QuickTools } from "@/components/quick-tools";
import { StoreLayout } from "@/components/store-layout";
import { GlobalHomePopup } from "@/components/global-home-popup";
import { HomeBannerCarousel } from "@/components/home-banner-carousel";
import { PopularNow } from "@/components/popular-now";
import { HomeNewsPreview } from "@/components/home-news-preview";

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
        <HomeBannerCarousel />
        <PopularNow />
        <GlobalHomePopup />

        <section className="mx-auto max-w-7xl px-4 pt-12 sm:px-6 sm:pt-16 lg:px-8">
          <div className="mb-6"><p className="eyebrow">Menu cepat</p><h2 className="text-2xl font-black tracking-tight sm:text-3xl">Semua yang kamu butuhkan</h2></div>
          <QuickTools />
        </section>

        <HomeProductBrowser />

        <HomeNewsPreview />

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
