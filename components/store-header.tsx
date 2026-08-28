"use client";

import Link from "next/link";
import { Menu, Search, ShoppingBag, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";

const navItems = [
  { href: "/", label: "Beranda" }, { href: "/catalog", label: "Katalog" },
  { href: "/track", label: "Cek Transaksi" }, { href: "/faq", label: "Bantuan" },
];

export function StoreHeader() {
  return (
    <header className="sticky top-0 z-50 border-b border-white/[0.08] bg-[#07090f]/90 backdrop-blur-xl">
      <div className="mx-auto flex h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <Link className="flex items-center gap-2.5" href="/" aria-label="LFAMILIA STORE">
          <span className="grid size-9 place-items-center rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006] shadow-[0_0_24px_rgba(185,255,53,0.18)]">LF</span>
          <span className="leading-none"><strong className="block text-xs tracking-[0.13em] text-white">LFAMILIA</strong><span className="mt-1 block text-[9px] font-semibold tracking-[0.28em] text-white/40">STORE</span></span>
        </Link>
        <nav className="hidden items-center gap-1 md:flex" aria-label="Navigasi utama">
          {navItems.map((item) => <Link key={item.href} href={item.href} className="rounded-lg px-3.5 py-2 text-sm font-medium text-white/58 transition hover:bg-white/[0.06] hover:text-white">{item.label}</Link>)}
        </nav>
        <div className="flex items-center gap-2">
          <Button asChild variant="ghost" size="icon" className="hidden rounded-xl text-white/60 hover:bg-white/[0.07] hover:text-white sm:inline-flex"><Link href="/catalog" aria-label="Cari produk"><Search className="size-4" /></Link></Button>
          <Button asChild className="hidden h-9 rounded-xl bg-[#b9ff35] px-4 text-xs font-bold text-[#091006] hover:bg-[#d0ff75] sm:inline-flex"><Link href="/login"><UserRound className="mr-2 size-4" />Masuk</Link></Button>
          <Sheet>
            <SheetTrigger asChild><Button variant="ghost" size="icon" className="rounded-xl text-white md:hidden" aria-label="Buka menu"><Menu className="size-5" /></Button></SheetTrigger>
            <SheetContent className="border-white/10 bg-[#0b0e16] text-white">
              <SheetHeader className="border-b border-white/[0.08]"><SheetTitle className="text-left text-white">LFAMILIA STORE</SheetTitle><SheetDescription className="text-left text-white/45">Top up game dan voucher digital.</SheetDescription></SheetHeader>
              <nav className="flex flex-col gap-1 px-4 py-5">
                {navItems.map((item) => <SheetClose asChild key={item.href}><Link href={item.href} className="rounded-xl px-4 py-3.5 text-sm font-semibold text-white/70 hover:bg-white/[0.06] hover:text-white">{item.label}</Link></SheetClose>)}
                <div className="my-3 h-px bg-white/[0.08]" />
                <SheetClose asChild><Link href="/login" className="flex items-center gap-3 rounded-xl bg-[#b9ff35] px-4 py-3.5 text-sm font-bold text-[#091006]"><UserRound className="size-4" /> Masuk ke akun</Link></SheetClose>
                <SheetClose asChild><Link href="/admin" className="mt-2 flex items-center gap-3 rounded-xl px-4 py-3.5 text-sm text-white/45 hover:bg-white/[0.06]"><ShoppingBag className="size-4" /> Demo panel admin</Link></SheetClose>
              </nav>
            </SheetContent>
          </Sheet>
        </div>
      </div>
    </header>
  );
}

