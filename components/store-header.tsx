"use client";

import Link from "next/link";
import {
  Calculator,
  Gamepad2,
  Headphones,
  LogIn,
  Menu,
  Newspaper,
  ReceiptText,
  TicketPercent,
  Trophy,
  UserPlus,
  UserRound,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { StoreSearch } from "@/components/store-search";
import { StoreBrand } from "@/components/store-brand";
import { useStorefront } from "@/hooks/use-storefront";

const desktopNavItems = [
  { href: "/catalog", label: "Top Up", icon: Gamepad2 },
  { href: "/promo", label: "Voucher", icon: TicketPercent },
  { href: "/news", label: "Berita", icon: Newspaper },
  { href: "/leaderboard", label: "Leaderboard", icon: Trophy },
  { href: "/tools", label: "Kalkulator", icon: Calculator },
  { href: "/track", label: "Transaksi", icon: ReceiptText },
];

const mobileNavItems = [
  { href: "/track", label: "Cek Pesanan", icon: ReceiptText },
  { href: "/leaderboard", label: "Leaderboard", icon: Trophy },
  { href: "/tools", label: "Kalkulator", icon: Calculator },
  { href: "/contact", label: "Hubungi Kami", icon: Headphones },
  { href: "/news", label: "Berita", icon: Newspaper },
  { href: "/account", label: "Akun Saya", icon: UserRound },
];

export function StoreHeader() {
  const { settings } = useStorefront();

  return (
    <header className="sticky top-0 z-50 border-b border-white/[0.08] bg-[#07090f]/90 backdrop-blur-xl">
      <div className="mx-auto flex h-[60px] w-full max-w-7xl items-center gap-[10px] px-4 sm:h-[62px] sm:px-6 lg:px-8">
        <Link
          className="flex items-center gap-2.5"
          href="/"
          aria-label="LFAMILIA STORE"
        >
          <StoreBrand settings={settings} />
        </Link>

        <div className="hidden flex-1 justify-center lg:flex">
          <StoreSearch />
        </div>

        <nav className="hidden items-center gap-0.5 xl:flex" aria-label="Navigasi utama">
          {desktopNavItems.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className="rounded-[7px] px-[10px] py-[7px] text-[11px] font-semibold text-white/55 transition hover:bg-white/[0.06] hover:text-white"
            >
              {item.label}
            </Link>
          ))}
        </nav>

        <div className="ml-auto flex items-center gap-[6px]">
          <StoreSearch mobile />

          <Button
            asChild
            variant="outline"
            className="hidden h-[36px] rounded-[8px] border-white/10 bg-white/[0.035] px-[10px] text-[11px] font-bold text-white hover:bg-white/[0.08] hover:text-white sm:inline-flex"
          >
            <Link href="/account">
              <UserRound className="mr-1.5 size-[14px] text-[#b9ff35]" />
              Akun
            </Link>
          </Button>

          <Button
            asChild
            variant="outline"
            className="hidden h-[36px] rounded-[8px] border-white/10 bg-white/[0.035] px-[10px] text-[11px] font-bold text-white hover:bg-white/[0.08] hover:text-white sm:inline-flex"
          >
            <Link href="/contact">
              <Headphones className="mr-1.5 size-[14px] text-[#b9ff35]" />
              Hubungi
            </Link>
          </Button>

          <Sheet>
            <SheetTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="size-[36px] rounded-[8px] text-white xl:hidden"
                aria-label="Buka menu"
              >
                <Menu className="size-[17px]" />
              </Button>
            </SheetTrigger>

            <SheetContent className="w-[80vw] max-w-[330px] overflow-y-auto border-white/10 bg-[#0b0e16] text-white sm:max-w-[330px]">
              <SheetHeader className="border-b border-white/[0.08] px-4 py-3">
                <SheetTitle className="text-left text-sm font-black text-white">
                  LFAMILIA STORE
                </SheetTitle>
                <SheetDescription className="text-left text-[10px] leading-4 text-white/40">
                  Top up, kalkulator game, dan bantuan.
                </SheetDescription>
              </SheetHeader>

              <div className="mx-3 mt-3 rounded-[16px] border border-white/[0.08] bg-gradient-to-br from-[#b9ff35]/[0.06] via-white/[0.02] to-transparent p-3">
                <p className="text-[8px] font-black uppercase tracking-[0.22em] text-[#cfff72]">
                  Selamat datang
                </p>
                <h2 className="mt-1.5 text-[13px] font-black leading-[18px] text-white">
                  Masuk untuk pengalaman lebih cepat
                </h2>
                <p className="mt-1 text-[10px] leading-4 text-white/42">
                  Simpan akun top-up favorit, cek saldo, dan riwayat transaksi.
                </p>

                <div className="mt-2.5 grid grid-cols-2 gap-2">
                  <SheetClose asChild>
                    <Link
                      href="/account"
                      className="inline-flex h-8 items-center justify-center rounded-[10px] bg-[#b9ff35] px-2.5 text-[10px] font-black text-[#091006] transition hover:bg-[#c7ff58]"
                    >
                      <LogIn className="mr-1.5 size-3.5" />
                      Masuk
                    </Link>
                  </SheetClose>

                  <SheetClose asChild>
                    <Link
                      href="/account?mode=register"
                      className="inline-flex h-8 items-center justify-center rounded-[10px] border border-white/10 bg-white/[0.03] px-2.5 text-[10px] font-bold text-white/75 transition hover:bg-white/[0.08] hover:text-white"
                    >
                      <UserPlus className="mr-1.5 size-3.5" />
                      Daftar
                    </Link>
                  </SheetClose>
                </div>
              </div>

              <nav className="flex flex-col gap-0 px-3 py-3">
                <SheetClose asChild>
                  <Link
                    href="/"
                    className="rounded-[10px] px-3 py-2.5 text-[13px] font-semibold text-white/70 transition hover:bg-white/[0.05] hover:text-white"
                  >
                    Beranda
                  </Link>
                </SheetClose>

                {mobileNavItems.map(({ href, label, icon: Icon }) => (
                  <SheetClose asChild key={label}>
                    <Link
                      href={href}
                      className="flex items-center gap-2.5 rounded-[10px] px-3 py-2.5 text-[13px] font-semibold text-white/65 transition hover:bg-white/[0.05] hover:text-white"
                    >
                      <Icon className="size-[15px] shrink-0 text-[#b9ff35]/85" />
                      {label}
                    </Link>
                  </SheetClose>
                ))}
              </nav>
            </SheetContent>
          </Sheet>
        </div>
      </div>
    </header>
  );
}
