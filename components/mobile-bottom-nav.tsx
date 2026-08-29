import Link from "next/link";
import { Flame, Gamepad2, Headphones, Home, ReceiptText } from "lucide-react";

const items = [
  { href: "/", label: "Beranda", icon: Home },
  { href: "/catalog", label: "Top Up", icon: Gamepad2 },
  { href: "/promo", label: "Promo", icon: Flame },
  { href: "/track", label: "Transaksi", icon: ReceiptText },
  { href: "/contact", label: "Bantuan", icon: Headphones },
];

export function MobileBottomNav() {
  return <nav aria-label="Navigasi bawah" className="fixed inset-x-3 bottom-3 z-50 grid grid-cols-5 rounded-2xl border border-white/10 bg-[#0c0f16]/95 p-1.5 shadow-2xl shadow-black/60 backdrop-blur-xl md:hidden">{items.map(({ href, label, icon: Icon }) => <Link key={href} href={href} className="flex min-w-0 flex-col items-center gap-1 rounded-xl px-1 py-2 text-[8px] font-semibold text-white/45 transition hover:bg-white/[0.06] hover:text-[#cfff72]"><Icon className="size-4" /><span className="truncate">{label}</span></Link>)}</nav>;
}
