"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import {
  BadgeCheck,
  Calculator,
  Gamepad2,
  Headphones,
  LogIn,
  LogOut,
  Menu,
  Newspaper,
  ReceiptText,
  TicketPercent,
  Trophy,
  UserPlus,
  UserRound,
  WalletCards,
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
import { formatRupiah } from "@/lib/store-data";

type SidebarCustomer = {
  id: string;
  email: string;
  name: string;
  phone: string;
  phoneVerified: boolean;
  balance: number;
};

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
  const [customer, setCustomer] = useState<SidebarCustomer | null>(null);
  const [accountChecked, setAccountChecked] = useState(false);

  const loadAccount = useCallback(async () => {
    try {
      const response = await fetch("/api/account", { cache: "no-store" });
      if (!response.ok) {
        setCustomer(null);
        return;
      }
      const payload = await response.json().catch(() => ({})) as { customer?: SidebarCustomer };
      setCustomer(payload.customer ?? null);
    } catch {
      setCustomer(null);
    } finally {
      setAccountChecked(true);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void loadAccount(), 0);
    const refresh = () => void loadAccount();
    window.addEventListener("focus", refresh);
    window.addEventListener("lfamilia:auth-changed", refresh);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener("focus", refresh);
      window.removeEventListener("lfamilia:auth-changed", refresh);
    };
  }, [loadAccount]);

  async function logout() {
    await fetch("/api/auth/logout", { method: "POST" }).catch(() => undefined);
    setCustomer(null);
    setAccountChecked(true);
    window.dispatchEvent(new Event("lfamilia:auth-changed"));
  }

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
              {customer ? customer.name.split(" ")[0] || "Akun" : "Akun"}
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

              {!accountChecked ? (
                <div className="mx-3 mt-3 animate-pulse rounded-[16px] border border-white/[0.08] bg-white/[0.025] p-3">
                  <div className="h-3 w-24 rounded bg-white/[0.08]" />
                  <div className="mt-3 h-7 rounded bg-white/[0.06]" />
                  <div className="mt-2 h-7 rounded bg-white/[0.04]" />
                </div>
              ) : customer ? (
                <div className="mx-3 mt-3 overflow-hidden rounded-[16px] border border-[#b9ff35]/15 bg-gradient-to-br from-[#b9ff35]/[0.08] via-white/[0.025] to-transparent">
                  <div className="flex items-start gap-3 p-3">
                    <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-[#b9ff35] text-[12px] font-black uppercase text-[#091006]">
                      {(customer.name.trim()[0] || "L").toUpperCase()}
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1.5">
                        <strong className="truncate text-[12px] font-black text-white">{customer.name}</strong>
                        {customer.phoneVerified && <BadgeCheck className="size-3.5 shrink-0 text-[#b9ff35]" aria-label="WhatsApp terverifikasi" />}
                      </div>
                      <p className="mt-0.5 truncate text-[9px] text-white/38">{customer.email}</p>
                      <p className={`mt-1 text-[8px] font-bold ${customer.phoneVerified ? "text-[#cfff72]" : "text-amber-300"}`}>
                        {customer.phoneVerified ? "WhatsApp terverifikasi" : "WhatsApp belum diverifikasi"}
                      </p>
                    </div>
                  </div>

                  <div className="mx-3 rounded-xl border border-white/[0.07] bg-black/20 p-3">
                    <div className="flex items-center justify-between gap-3">
                      <span className="flex items-center gap-1.5 text-[9px] font-semibold uppercase tracking-[0.12em] text-white/35">
                        <WalletCards className="size-3.5 text-[#b9ff35]" />
                        Saldo
                      </span>
                      <strong className="text-[14px] font-black text-white">{formatRupiah(customer.balance)}</strong>
                    </div>
                  </div>

                  <div className="grid grid-cols-[1fr_auto] gap-2 p-3">
                    <SheetClose asChild>
                      <Link
                        href="/account"
                        className="inline-flex h-8 items-center justify-center rounded-[10px] bg-[#b9ff35] px-2.5 text-[10px] font-black text-[#091006] transition hover:bg-[#c7ff58]"
                      >
                        {customer.phoneVerified ? "Akun & Saldo" : "Verifikasi WhatsApp"}
                      </Link>
                    </SheetClose>
                    <button
                      type="button"
                      onClick={() => void logout()}
                      className="inline-flex size-8 items-center justify-center rounded-[10px] border border-white/10 bg-white/[0.03] text-white/55 transition hover:bg-white/[0.08] hover:text-white"
                      aria-label="Keluar dari akun"
                    >
                      <LogOut className="size-3.5" />
                    </button>
                  </div>
                </div>
              ) : (
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
              )}

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
