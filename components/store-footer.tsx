"use client";

import Link from "next/link";
import { Mail } from "lucide-react";
import { StoreBrand } from "@/components/store-brand";
import { useStorefront } from "@/hooks/use-storefront";

const groups = [
  { title: "Layanan", items: [["Top Up Game", "/#produk"], ["Promo", "/promo"], ["Cek Transaksi", "/track"], ["Hubungi Kami", "/contact"]] },
  { title: "Kalkulator", items: [["Win Rate", "/tools/win-rate"], ["Zodiac", "/tools/zodiac"], ["Magic Wheel", "/tools/magic-wheel"], ["Semua Alat", "/tools"]] },
  { title: "Informasi", items: [["Pertanyaan umum", "/faq"], ["Syarat & ketentuan", "/terms"], ["Kebijakan pengembalian dana", "/refund"], ["Kebijakan privasi", "/privacy"]] },
];

function WhatsAppIcon({ className }: { className?: string }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" /></svg>;
}

function InstagramIcon({ className }: { className?: string }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor"><path d="M7.75 2h8.5A5.75 5.75 0 0 1 22 7.75v8.5A5.75 5.75 0 0 1 16.25 22h-8.5A5.75 5.75 0 0 1 2 16.25v-8.5A5.75 5.75 0 0 1 7.75 2Zm0 2A3.75 3.75 0 0 0 4 7.75v8.5A3.75 3.75 0 0 0 7.75 20h8.5A3.75 3.75 0 0 0 20 16.25v-8.5A3.75 3.75 0 0 0 16.25 4h-8.5Zm8.96 1.5a1.29 1.29 0 1 1 0 2.58 1.29 1.29 0 0 1 0-2.58ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z" /></svg>;
}

function DiscordIcon({ className }: { className?: string }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor"><path d="M20.317 4.37a19.8 19.8 0 00-4.885-1.515.074.074 0 00-.079.037c-.211.375-.445.865-.608 1.25a15.4 15.4 0 00-5.487 0c-.164-.394-.406-.875-.618-1.25a.077.077 0 00-.078-.037A19.74 19.74 0 003.677 4.37a.07.07 0 00-.032.028C.533 9.046-.319 13.58.1 18.058a.082.082 0 00.031.056c2.053 1.507 4.041 2.422 5.993 3.029a.077.077 0 00.084-.028c.462-.63.873-1.295 1.226-1.994a.077.077 0 00-.042-.106 12.3 12.3 0 01-1.872-.892.077.077 0 01-.008-.128c.126-.094.252-.192.372-.291a.074.074 0 01.078-.01c3.927 1.793 8.18 1.793 12.061 0a.073.073 0 01.079.01c.12.099.246.198.373.292a.077.077 0 01-.007.128c-.598.343-1.22.644-1.873.891a.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.077.077 0 00.084.029c1.961-.607 3.95-1.522 6.002-3.03a.082.082 0 00.032-.055c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.029ZM8.02 15.331c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.211 0 2.176 1.095 2.157 2.419 0 1.333-.956 2.419-2.157 2.419Zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.211 0 2.176 1.095 2.157 2.419 0 1.333-.946 2.419-2.157 2.419Z" /></svg>;
}

export function StoreFooter() {
  const { settings } = useStorefront();
  const whatsapp = settings.supportWhatsapp?.replace(/\D/g, "").replace(/^0/, "62");
  const socialLinks = [
    { href: whatsapp ? `https://wa.me/${whatsapp}` : "/contact", label: "Hubungi melalui WhatsApp", icon: WhatsAppIcon, tone: "hover:text-[#25d366]" },
    { href: settings.instagramUrl || "/contact", label: "Instagram LFAMILIA", icon: InstagramIcon, tone: "hover:text-[#f56040]" },
    { href: settings.supportEmail ? `mailto:${settings.supportEmail}` : "/contact", label: "Email bantuan", icon: Mail, tone: "hover:text-[#63a4ff]" },
    { href: settings.discordUrl || "/contact", label: "Discord LFAMILIA", icon: DiscordIcon, tone: "hover:text-[#7289da]" },
  ];
  return (
    <footer className="border-t border-white/[0.08] bg-[#05070b]">
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr] lg:px-8">
        <div>
          <Link className="inline-flex items-center gap-3" href="/"><StoreBrand settings={settings} /></Link>
          <p className="mt-4 max-w-sm text-sm leading-6 text-white/42">{settings.tagline}</p>
          <div className="mt-5 flex gap-2">{socialLinks.map(({ href, label, icon: Icon, tone }) => <a key={label} href={href} target={href.startsWith("http") ? "_blank" : undefined} rel={href.startsWith("http") ? "noreferrer" : undefined} aria-label={label} className={`grid size-9 place-items-center rounded-lg border border-white/10 text-white/45 transition hover:border-white/25 ${tone}`}><Icon className="size-4" /></a>)}</div>
        </div>
        {groups.map((group) => <div key={group.title}><h2 className="text-xs font-bold uppercase tracking-[0.2em] text-white/75">{group.title}</h2><ul className="mt-4 space-y-3 text-sm text-white/42">{group.items.map(([label, href]) => <li key={href}><Link className="transition hover:text-[#b9ff35]" href={href}>{label}</Link></li>)}</ul></div>)}
      </div>
      <div className="border-t border-white/[0.06] px-4 py-5 text-center text-[11px] text-white/30 sm:px-6">© 2026 {settings.storeName}. Produk dan merek dagang adalah milik pemegang hak masing-masing.</div>
    </footer>
  );
}
