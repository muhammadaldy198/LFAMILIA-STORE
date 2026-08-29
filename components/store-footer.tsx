"use client";

import Link from "next/link";
import { Camera, Headphones, MessageCircle } from "lucide-react";
import { StoreBrand } from "@/components/store-brand";
import { useStorefront } from "@/hooks/use-storefront";

const groups = [
  { title: "Layanan", items: [["Top Up Game", "/catalog"], ["Promo", "/promo"], ["Cek Transaksi", "/track"], ["Hubungi Kami", "/contact"]] },
  { title: "Kalkulator", items: [["Win Rate", "/tools/win-rate"], ["Zodiac", "/tools/zodiac"], ["Magic Wheel", "/tools/magic-wheel"], ["Semua Alat", "/tools"]] },
  { title: "Informasi", items: [["Pertanyaan umum", "/faq"], ["Syarat & ketentuan", "/terms"], ["Kebijakan refund", "/refund"], ["Kebijakan privasi", "/privacy"]] },
];

export function StoreFooter() {
  const { settings } = useStorefront();
  const whatsapp = settings.supportWhatsapp?.replace(/\D/g, "").replace(/^0/, "62");
  const socialLinks = [
    { href: whatsapp ? `https://wa.me/${whatsapp}` : "/contact", label: "Hubungi melalui WhatsApp", icon: MessageCircle },
    { href: settings.instagramUrl || "/contact", label: "Instagram LFAMILIA", icon: Camera },
    { href: settings.supportEmail ? `mailto:${settings.supportEmail}` : "/contact", label: "Email bantuan", icon: Headphones },
  ];
  return (
    <footer className="border-t border-white/[0.08] bg-[#05070b]">
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr] lg:px-8">
        <div>
          <Link className="inline-flex items-center gap-3" href="/"><StoreBrand settings={settings} /></Link>
          <p className="mt-4 max-w-sm text-sm leading-6 text-white/42">{settings.tagline}</p>
          <div className="mt-5 flex gap-2">{socialLinks.map(({ href, label, icon: Icon }) => <a key={label} href={href} target={href.startsWith("http") ? "_blank" : undefined} rel={href.startsWith("http") ? "noreferrer" : undefined} aria-label={label} className="grid size-9 place-items-center rounded-lg border border-white/10 text-white/45 hover:border-[#b9ff35]/30 hover:text-[#b9ff35]"><Icon className="size-4" /></a>)}</div>
        </div>
        {groups.map((group) => <div key={group.title}><h2 className="text-xs font-bold uppercase tracking-[0.2em] text-white/75">{group.title}</h2><ul className="mt-4 space-y-3 text-sm text-white/42">{group.items.map(([label, href]) => <li key={href}><Link className="transition hover:text-[#b9ff35]" href={href}>{label}</Link></li>)}</ul></div>)}
      </div>
      <div className="border-t border-white/[0.06] px-4 py-5 text-center text-[11px] text-white/30 sm:px-6">© 2026 {settings.storeName}. Produk dan merek dagang adalah milik pemegang hak masing-masing.</div>
    </footer>
  );
}
