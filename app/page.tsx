import Link from "next/link";
import {
  ArrowRight,
  CreditCard,
  Gamepad2,
  Headphones,
  ReceiptText,
  Zap,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { HomeProductBrowser } from "@/components/home-product-browser";
import { QuickTools } from "@/components/quick-tools";
import { StoreLayout } from "@/components/store-layout";
import { GlobalHomePopup } from "@/components/global-home-popup";
import { HomeBannerCarousel } from "@/components/home-banner-carousel";
import { PopularNow } from "@/components/popular-now";
import { HomeNewsPreview } from "@/components/home-news-preview";
import { HomeReviewsPreview } from "@/components/home-reviews-preview";
import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Top Up Game Cepat & Aman",
  description: "Top up game dan voucher digital di LFAMILIA STORE dengan pilihan produk lengkap, pembayaran praktis, dan status transaksi yang mudah dipantau.",
  path: "/",
});

const steps = [
  {
    number: "01",
    title: "Pilih produk",
    text: "Cari game atau voucher yang kamu inginkan.",
    icon: Gamepad2,
  },
  {
    number: "02",
    title: "Isi data",
    text: "Masukkan ID dan pilih nominal top up.",
    icon: ReceiptText,
  },
  {
    number: "03",
    title: "Bayar aman",
    text: "Selesaikan pembayaran sesuai total pesanan.",
    icon: CreditCard,
  },
  {
    number: "04",
    title: "Pesanan diproses",
    text: "Status dapat dipantau melalui nomor invoice.",
    icon: Zap,
  },
];

export default function Home() {
  return (
    <StoreLayout>
      <main>
        <HomeBannerCarousel />
        <PopularNow />
        <GlobalHomePopup />

        <section className="mx-auto max-w-7xl px-4 pt-[28px] sm:px-6 sm:pt-[36px] lg:px-8">
          <div className="mb-[12px]">
            <p className="eyebrow">Menu cepat</p>
            <h2 className="text-[20px] font-black leading-tight tracking-[-0.03em] sm:text-[24px]">
              Semua yang kamu butuhkan
            </h2>
          </div>
          <QuickTools />
        </section>

        <HomeProductBrowser />
        <HomeNewsPreview />
        <HomeReviewsPreview />

        <section className="border-y border-white/[0.07] bg-white/[0.018]">
          <div className="mx-auto max-w-7xl px-4 py-[32px] sm:px-6 sm:py-[40px] lg:px-8">
            <div className="flex flex-col gap-[10px] sm:flex-row sm:items-end sm:justify-between">
              <div>
                <p className="eyebrow">Cara top up</p>
                <h2 className="text-[22px] font-black leading-tight tracking-[-0.035em] sm:text-[28px]">
                  Empat langkah sederhana
                </h2>
              </div>
              <Link
                href="/faq"
                className="inline-flex items-center gap-[5px] text-[10px] font-bold text-[#cfff72]"
              >
                Pelajari lebih lanjut
                <ArrowRight className="size-[13px]" />
              </Link>
            </div>

            <div className="mt-[14px] grid gap-[8px] sm:grid-cols-2 lg:grid-cols-4">
              {steps.map(({ number, title, text, icon: Icon }) => (
                <article
                  key={number}
                  className="rounded-[9px] border border-white/[0.08] bg-[#0d1017] p-[11px]"
                >
                  <div className="flex items-center justify-between">
                    <span className="grid size-[32px] place-items-center rounded-[8px] bg-[#b9ff35]/[0.09] text-[#cfff72]">
                      <Icon className="size-[15px]" />
                    </span>
                    <span className="font-mono text-[8px] text-white/20">{number}</span>
                  </div>
                  <h3 className="mt-[9px] text-[12px] font-bold">{title}</h3>
                  <p className="mt-[4px] text-[10px] leading-[1.45] text-white/36">
                    {text}
                  </p>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section className="mx-auto max-w-7xl px-4 py-[32px] sm:px-6 sm:py-[40px] lg:px-8">
          <div className="flex flex-col items-start justify-between gap-[14px] overflow-hidden rounded-[12px] border border-[#b9ff35]/20 bg-[#b9ff35]/[0.07] p-[14px] sm:flex-row sm:items-center sm:p-[18px]">
            <div>
              <p className="text-[8px] font-bold uppercase tracking-[0.18em] text-[#cfff72]">
                Butuh bantuan?
              </p>
              <h2 className="mt-[6px] text-[18px] font-black tracking-tight sm:text-[22px]">
                Tim LFAMILIA siap membantu.
              </h2>
              <p className="mt-[5px] max-w-xl text-[10px] leading-[1.5] text-white/42 sm:text-[11px]">
                Sampaikan pertanyaan mengenai produk, pembayaran, atau status pesanan melalui pusat bantuan.
              </p>
            </div>
            <Button
              asChild
              className="h-[34px] shrink-0 rounded-[8px] bg-[#b9ff35] px-[13px] text-[10px] font-black text-[#091006] hover:bg-[#d0ff75]"
            >
              <Link href="/contact">
                <Headphones className="mr-1.5 size-[13px]" />
                Hubungi Kami
              </Link>
            </Button>
          </div>
        </section>
      </main>
    </StoreLayout>
  );
}
