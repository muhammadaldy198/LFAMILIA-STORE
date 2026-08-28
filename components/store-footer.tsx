import Link from "next/link";
import { Camera, MessageCircle } from "lucide-react";

const groups = [
  { title: "Belanja", items: [["Katalog", "/catalog"], ["Cek transaksi", "/track"], ["Akun saya", "/account"]] },
  { title: "Bantuan", items: [["Pertanyaan umum", "/faq"], ["Syarat & ketentuan", "/terms"], ["Kebijakan refund", "/refund"], ["Kebijakan privasi", "/privacy"]] },
];

export function StoreFooter() {
  return (
    <footer className="border-t border-white/[0.08] bg-[#05070b]">
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr] lg:px-8">
        <div>
          <Link className="inline-flex items-center gap-3" href="/"><span className="grid size-10 place-items-center rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006]">LF</span><span className="font-black tracking-[0.08em]">LFAMILIA STORE</span></Link>
          <p className="mt-4 max-w-sm text-sm leading-6 text-white/42">Platform top up game dan voucher digital yang disiapkan untuk proses cepat, transparan, dan mudah dari HP.</p>
          <div className="mt-5 flex gap-2"><span className="grid size-9 place-items-center rounded-lg border border-white/10 text-white/45"><MessageCircle className="size-4" /></span><span className="grid size-9 place-items-center rounded-lg border border-white/10 text-white/45"><Camera className="size-4" /></span></div>
        </div>
        {groups.map((group) => <div key={group.title}><h2 className="text-xs font-bold uppercase tracking-[0.2em] text-white/75">{group.title}</h2><ul className="mt-4 space-y-3 text-sm text-white/42">{group.items.map(([label, href]) => <li key={href}><Link className="transition hover:text-[#b9ff35]" href={href}>{label}</Link></li>)}</ul></div>)}
      </div>
      <div className="border-t border-white/[0.06] px-4 py-5 text-center text-[11px] text-white/30 sm:px-6">© 2026 LFAMILIA STORE. Produk dan merek dagang adalah milik pemegang hak masing-masing.</div>
    </footer>
  );
}
