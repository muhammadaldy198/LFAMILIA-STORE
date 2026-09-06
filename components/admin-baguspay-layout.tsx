"use client";

import {
  BarChart3,
  Bell,
  CreditCard,
  FileText,
  KeyRound,
  Package,
  Settings,
  ShoppingCart,
  Users,
  Wallet,
  Ticket,
} from "lucide-react";

const menus = [
  ["Dashboard", BarChart3],
  ["Pesanan Realtime", ShoppingCart],
  ["Produk & Nominal", Package],
  ["Voucher", Ticket],
  ["Provider API", KeyRound],
  ["Pembayaran", CreditCard],
  ["Wallet Pelanggan", Wallet],
  ["Pelanggan & Staff", Users],
  ["Konten Website", FileText],
  ["Notifikasi", Bell],
  ["Pengaturan", Settings],
] as const;

export function AdminBagusPayLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="grid min-h-[70vh] gap-3 lg:grid-cols-[220px_1fr]">
      <aside className="rounded-xl border border-white/10 bg-[#0d1019] p-2">
        <div className="mb-3 px-2 text-[11px] font-black uppercase tracking-widest text-[#c9ff70]">
          LFAMILIA ADMIN
        </div>
        <nav className="space-y-1">
          {menus.map(([label, Icon]) => (
            <button key={label} className="flex h-9 w-full items-center rounded-lg px-2 text-xs text-white/70 transition hover:bg-white/10 hover:text-white">
              <Icon className="mr-2 size-3.5" />
              {label}
            </button>
          ))}
        </nav>
      </aside>
      <section className="rounded-xl border border-white/10 bg-[#0d1019] p-3">
        {children}
      </section>
    </div>
  );
}
