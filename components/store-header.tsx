"use client";

import Link from "next/link";
import { Calculator, Gamepad2, Headphones, Menu, ReceiptText, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { StoreSearch } from "@/components/store-search";

const navItems = [
  { href: "/catalog", label: "Top Up", icon: Gamepad2 },
  { href: "/tools", label: "Kalkulator", icon: Calculator },
  { href: "/track", label: "Transaksi", icon: ReceiptText },
];

export function StoreHeader() {
  return (
    <header className="sticky top-0 z-50 border-b border-white/[0.08] bg-[#07090f]/90 backdrop-blur-xl">
      <div className="mx-auto flex h-[68px] w-full max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <Link className="flex items-center gap-2.5" href="/" aria-label="LFAMILIA STORE">
          <span className="grid size-10 place-items-center rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006] shadow-[0_0_24px_rgba(185,255,53,0.18)]">LF</span>
          <span className="leading-none"><strong className="block text-xs tracking-[0.13em] text-white">LFAMILIA</strong><span className="mt-1 block text-[9px] font-semibold tracking-[0.28em] text-white/40">STORE</span></span>
        </Link>
        <div className="hidden flex-1 justify-center lg:flex"><StoreSearch /></div>
        <nav className="hidden items-center gap-0.5 xl:flex" aria-label="Navigasi utama">
          {navItems.map((item) => <Link key={item.href} href={item.href} className="rounded-lg px-3 py-2 text-xs font-semibold text-white/55 transition hover:bg-white/[0.06] hover:text-white">{item.label}</Link>)}
        </nav>
        <div className="ml-auto flex items-center gap-2">
          <StoreSearch mobile />
          <Button asChild variant="outline" className="hidden h-10 rounded-xl border-white/10 bg-white/[0.035] px-3 text-xs font-bold text-white hover:bg-white/[0.08] hover:text-white sm:inline-flex"><Link href="/contact"><Headphones className="mr-2 size-4 text-[#b9ff35]" />Hubungi</Link></Button>
          <Button asChild className="hidden h-10 rounded-xl bg-[#b9ff35] px-4 text-xs font-bold text-[#091006] hover:bg-[#d0ff75] md:inline-flex"><Link href="/login"><UserRound className="mr-2 size-4" />Masuk</Link></Button>
          <Sheet>
            <SheetTrigger asChild><Button variant="ghost" size="icon" className="rounded-xl text-white xl:hidden" aria-label="Buka menu"><Menu className="size-5" /></Button></SheetTrigger>
            <SheetContent className="border-white/10 bg-[#0b0e16] text-white">
              <SheetHeader className="border-b border-white/[0.08]"><SheetTitle className="text-left text-white">LFAMILIA STORE</SheetTitle><SheetDescription className="text-left text-white/45">Top up, kalkulator game, dan bantuan.</SheetDescription></SheetHeader>
              <nav className="flex flex-col gap-1 px-4 py-5">
                <SheetClose asChild><Link href="/" className="rounded-xl px-4 py-3.5 text-sm font-semibold text-white/70 hover:bg-white/[0.06]">Beranda</Link></SheetClose>
                {navItems.map(({ href, label, icon: Icon }) => <SheetClose asChild key={href}><Link href={href} className="flex items-center gap-3 rounded-xl px-4 py-3.5 text-sm font-semibold text-white/70 hover:bg-white/[0.06] hover:text-white"><Icon className="size-4 text-[#b9ff35]" />{label}</Link></SheetClose>)}
                <SheetClose asChild><Link href="/contact" className="flex items-center gap-3 rounded-xl px-4 py-3.5 text-sm font-semibold text-white/70 hover:bg-white/[0.06]"><Headphones className="size-4 text-[#b9ff35]" />Hubungi Kami</Link></SheetClose>
                <div className="my-3 h-px bg-white/[0.08]" />
                <SheetClose asChild><Link href="/login" className="flex items-center gap-3 rounded-xl bg-[#b9ff35] px-4 py-3.5 text-sm font-bold text-[#091006]"><UserRound className="size-4" /> Masuk ke akun</Link></SheetClose>
              </nav>
            </SheetContent>
          </Sheet>
        </div>
      </div>
    </header>
  );
}
