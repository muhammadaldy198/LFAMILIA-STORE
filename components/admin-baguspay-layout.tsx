"use client";

import { useState } from "react";
import { BarChart3, Bell, CreditCard, FileText, KeyRound, Package, Settings, ShoppingCart, Users, Wallet, Ticket, Menu, X } from "lucide-react";

const menus = [
  ["Dashboard", BarChart3],
  ["Pesanan Realtime", ShoppingCart],
  ["Produk & Nominal", Package],
  ["Voucher & Stok", Ticket],
  ["Provider API", KeyRound],
  ["Pembayaran", CreditCard],
  ["Wallet Pelanggan", Wallet],
  ["Pelanggan & Staff", Users],
  ["Konten Website", FileText],
  ["Notifikasi", Bell],
  ["Pengaturan", Settings],
] as const;

function SidebarContent() {
  return (
    <>
      <div className="mb-3 px-2 text-[10px] font-black uppercase tracking-[0.18em] text-[#c9ff70]">
        LFAMILIA ADMIN
      </div>
      <nav className="space-y-1">
        {menus.map(([label, Icon]) => (
          <button key={label} className="flex h-8 w-full items-center rounded-md px-2 text-xs text-white/65 transition hover:bg-white/10 hover:text-white">
            <Icon className="mr-2 size-3.5" />
            {label}
          </button>
        ))}
      </nav>
    </>
  );
}

export function AdminBagusPayLayout({ children }: { children: React.ReactNode }) {
  const [open, setOpen] = useState(false);

  return (
    <div className="min-h-[70vh]">
      <button onClick={() => setOpen(true)} className="mb-2 flex h-8 items-center gap-2 rounded-md border border-white/10 bg-[#0d1019] px-3 text-xs lg:hidden">
        <Menu className="size-4" /> Menu Admin
      </button>

      {open && (
        <div className="fixed inset-0 z-50 bg-black/60 lg:hidden" onClick={() => setOpen(false)}>
          <aside className="h-full w-64 border-r border-white/10 bg-[#0d1019] p-3" onClick={(e) => e.stopPropagation()}>
            <button onClick={() => setOpen(false)} className="mb-3 rounded-md p-1 hover:bg-white/10">
              <X className="size-4" />
            </button>
            <SidebarContent />
          </aside>
        </div>
      )}

      <div className="grid gap-3 lg:grid-cols-[190px_1fr]">
        <aside className="hidden rounded-lg border border-white/10 bg-[#0d1019] p-2 lg:block">
          <SidebarContent />
        </aside>
        <section className="rounded-lg border border-white/10 bg-[#0d1019] p-3">
          {children}
        </section>
      </div>
    </div>
  );
}