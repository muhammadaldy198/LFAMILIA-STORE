"use client";

import Link from "next/link";
import {
  ArrowRight,
  CheckCircle2,
  Clock3,
  Headphones,
  MessageCircleQuestion,
  RotateCcw,
  Search,
} from "lucide-react";
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "@/components/ui/accordion";
import { Button } from "@/components/ui/button";
import { StoreLayout } from "@/components/store-layout";
import { useStorefront } from "@/hooks/use-storefront";

const orderStatuses = [
  { icon: Clock3, title: "Menunggu pembayaran", description: "Selesaikan pembayaran sebelum invoice kedaluwarsa." },
  { icon: RotateCcw, title: "Sedang diproses", description: "Pembayaran sudah diterima dan pesanan sedang dikirim." },
  { icon: CheckCircle2, title: "Berhasil", description: "Produk telah dikirim ke data tujuan yang kamu masukkan." },
  { icon: RotateCcw, title: "Gagal / refund", description: "Pesanan ditinjau untuk diproses ulang atau dikembalikan." },
];

export default function FaqPage() {
  const { faqs } = useStorefront();

  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-6xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <section className="overflow-hidden rounded-[28px] border border-white/[0.08] bg-[radial-gradient(circle_at_top_right,rgba(185,255,53,0.12),transparent_40%),#0d1017] px-5 py-9 sm:px-10 sm:py-12">
          <span className="grid size-12 place-items-center rounded-2xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.08] text-[#cfff72]"><MessageCircleQuestion className="size-6" /></span>
          <p className="eyebrow mt-6">Pusat bantuan · FAQ</p>
          <h1 className="max-w-3xl text-3xl font-black tracking-[-0.04em] sm:text-5xl">Jawaban cepat untuk transaksi kamu.</h1>
          <p className="mt-4 max-w-2xl text-sm leading-7 text-white/45">Cari penjelasan tentang pembayaran, status pesanan, pengiriman produk, dan pengembalian dana. Nomor invoice adalah cara tercepat untuk menelusuri transaksi.</p>
          <div className="mt-7 flex flex-wrap gap-3">
            <Button asChild className="h-11 rounded-xl bg-[#b9ff35] px-5 text-xs font-black text-[#091006] hover:bg-[#d0ff75]"><Link href="/track"><Search className="mr-2 size-4" />Lacak pesanan</Link></Button>
            <Button asChild variant="outline" className="h-11 rounded-xl border-white/10 bg-white/[0.035] px-5 text-xs text-white hover:bg-white/[0.08] hover:text-white"><Link href="/contact"><Headphones className="mr-2 size-4" />Hubungi bantuan</Link></Button>
          </div>
        </section>

        <section className="mt-9">
          <div className="mb-4">
            <p className="eyebrow">Panduan status</p>
            <h2 className="text-xl font-black tracking-[-0.03em] sm:text-2xl">Kenali status pesananmu</h2>
          </div>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {orderStatuses.map(({ icon: Icon, title, description }) => (
              <div key={title} className="rounded-2xl border border-white/[0.08] bg-[#10131b] p-5">
                <span className="grid size-9 place-items-center rounded-xl bg-[#b9ff35]/[0.08] text-[#b9ff35]"><Icon className="size-4" /></span>
                <h3 className="mt-4 text-sm font-black">{title}</h3>
                <p className="mt-2 text-xs leading-5 text-white/38">{description}</p>
              </div>
            ))}
          </div>
        </section>

        <section className="mt-10 grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
          <div>
            <p className="eyebrow">Pertanyaan populer</p>
            <h2 className="text-2xl font-black tracking-[-0.03em]">Yang paling sering ditanyakan</h2>
            <div className="panel mt-5 p-4 sm:p-6">
              {faqs.length ? (
                <Accordion type="single" collapsible className="w-full">
                  {faqs.map((faq, index) => (
                    <AccordionItem value={`item-${index}`} key={`${faq.question}-${index}`} className="border-white/[0.08] px-1">
                      <AccordionTrigger className="py-5 text-left text-sm font-bold text-white hover:no-underline">{faq.question}</AccordionTrigger>
                      <AccordionContent className="pb-5 pr-6 text-xs leading-6 text-white/45">{faq.answer}</AccordionContent>
                    </AccordionItem>
                  ))}
                </Accordion>
              ) : (
                <p className="py-6 text-sm leading-6 text-white/42">FAQ sedang diperbarui. Tim bantuan tetap dapat dihubungi melalui halaman kontak.</p>
              )}
            </div>
          </div>

          <aside className="h-fit rounded-[24px] border border-[#b9ff35]/20 bg-[#b9ff35]/[0.055] p-6 lg:sticky lg:top-24">
            <Headphones className="size-6 text-[#b9ff35]" />
            <h2 className="mt-5 text-lg font-black">Butuh bantuan cepat?</h2>
            <p className="mt-3 text-xs leading-6 text-white/45">Sertakan nomor invoice, data tujuan, dan screenshot kendala agar pemeriksaan lebih cepat.</p>
            <div className="mt-4 rounded-xl border border-white/[0.08] bg-black/15 p-3 text-[11px] leading-5 text-white/38">Jangan pernah mengirim password, PIN, atau kode OTP kepada siapa pun.</div>
            <Link href="/contact" className="mt-5 inline-flex items-center gap-2 text-xs font-black text-[#cfff72] hover:text-white">Buka kanal bantuan <ArrowRight className="size-4" /></Link>
          </aside>
        </section>
      </main>
    </StoreLayout>
  );
}
