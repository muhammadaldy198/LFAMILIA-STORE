"use client";

import {
  BarChart3,
  CreditCard,
  KeyRound,
  Package,
  Settings,
  ShoppingCart,
  Users,
  Wallet,
} from "lucide-react";

const menus = [
  ["Dashboard", BarChart3],
  ["Pesanan", ShoppingCart],
  ["Produk", Package],
  ["Provider", KeyRound],
  ["Pembayaran", CreditCard],
  ["Pelanggan", Users],
  ["Wallet", Wallet],
  ["Pengaturan", Settings],
] as const;

export function AdminBagusPayLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="grid min-h-[70vh] gap-5 lg:grid-cols-[240px_1fr]">
      <aside className="rounded-2xl border border-white/10 bg-[#0d1019] p-3">
        <div className="mb-4 px-3 text-xs font-black uppercase tracking-widest text-[#c9ff70]">
          LFAMILIA ADMIN
        </div>
        <nav className="space-y-1">
          {menus.map(([label, Icon]) => (
            <button key={label} className="flex h-11 w-full items-center rounded-xl px-3 text-sm text-white/70 hover:bg-white/10 hover:text-white">
              <Icon className="mr-3 size-4" />
              {label}
            </button>
          ))}
        </nav>
      </aside>
      <section className="rounded-2xl border border-white/10 bg-[#0d1019] p-5">
        {children}
      </section>
    </div>
  );
}
