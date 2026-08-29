import Link from "next/link";
import { Calculator, Disc3, Headphones, Sparkles } from "lucide-react";

const tools = [
  { href: "/tools/win-rate", label: "Win Rate", text: "Hitung target WR", icon: Calculator },
  { href: "/tools/zodiac", label: "Zodiac", text: "Estimasi diamond", icon: Sparkles },
  { href: "/tools/magic-wheel", label: "Magic Wheel", text: "Hitung magic point", icon: Disc3 },
  { href: "/contact", label: "Hubungi Kami", text: "Bantuan pelanggan", icon: Headphones },
];

export function QuickTools() {
  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      {tools.map(({ href, label, text, icon: Icon }) => (
        <Link key={href} href={href} className="group flex min-h-24 items-center gap-3 rounded-2xl border border-white/[0.08] bg-[#10131b] p-4 transition hover:-translate-y-0.5 hover:border-[#b9ff35]/35 hover:bg-[#141922]">
          <span className="grid size-11 shrink-0 place-items-center rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.08] text-[#cfff72] transition group-hover:bg-[#b9ff35] group-hover:text-[#091006]"><Icon className="size-5" /></span>
          <span className="min-w-0"><strong className="block text-xs sm:text-sm">{label}</strong><span className="mt-1 block truncate text-[10px] text-white/32">{text}</span></span>
        </Link>
      ))}
    </div>
  );
}
