import Link from "next/link";
import { Headphones, MessageCircleQuestion, Search } from "lucide-react";
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "@/components/ui/accordion";
import { Button } from "@/components/ui/button";
import { StoreLayout } from "@/components/store-layout";
import { faqs } from "@/lib/store-data";

export default function FaqPage() {
  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-4xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
        <div className="text-center"><span className="mx-auto grid size-12 place-items-center rounded-2xl bg-[#b9ff35]/10 text-[#b9ff35]"><MessageCircleQuestion className="size-6" /></span><p className="eyebrow mt-5">Pusat bantuan</p><h1 className="section-title">Ada yang ingin ditanyakan?</h1><p className="mx-auto mt-4 max-w-xl text-sm leading-6 text-white/42">Temukan jawaban tentang transaksi, pembayaran, dan proses top up.</p></div>
        <div className="panel mt-10 p-4 sm:p-6"><Accordion type="single" collapsible className="w-full">{faqs.map((faq, index) => <AccordionItem value={`item-${index}`} key={faq.question} className="border-white/[0.08] px-1"><AccordionTrigger className="py-5 text-left text-sm font-bold text-white hover:no-underline">{faq.question}</AccordionTrigger><AccordionContent className="pb-5 pr-6 text-xs leading-6 text-white/42">{faq.answer}</AccordionContent></AccordionItem>)}</Accordion></div>
        <div className="mt-6 grid gap-3 sm:grid-cols-2"><div className="panel p-5"><Search className="size-5 text-[#b9ff35]" /><h2 className="mt-4 font-bold">Cek transaksi</h2><p className="mt-2 text-xs leading-5 text-white/36">Lihat status pesanan menggunakan nomor invoice.</p><Button asChild variant="outline" className="mt-5 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white hover:bg-white/[0.08] hover:text-white"><Link href="/track">Lacak pesanan</Link></Button></div><div className="panel p-5"><Headphones className="size-5 text-[#b9ff35]" /><h2 className="mt-4 font-bold">Butuh bantuan lain?</h2><p className="mt-2 text-xs leading-5 text-white/36">Kontak WhatsApp dukungan akan ditambahkan sebelum toko aktif.</p><Button disabled variant="outline" className="mt-5 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white/30 opacity-100">Kontak segera tersedia</Button></div></div>
      </main>
    </StoreLayout>
  );
}

