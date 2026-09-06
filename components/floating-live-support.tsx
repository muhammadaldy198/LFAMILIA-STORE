"use client";

import Link from "next/link";
import { Headphones, MessageCircle, MessagesSquare, X } from "lucide-react";
import { useState } from "react";
import { useStorefront } from "@/hooks/use-storefront";

export function FloatingLiveSupport() {
  const { settings } = useStorefront();
  const [open, setOpen] = useState(false);
  if (!settings.supportWidgetEnabled) return null;

  const whatsapp = settings.supportWhatsapp?.replace(/\D/g, "").replace(/^0/, "62");
  const hasExternal = Boolean(whatsapp || settings.discordUrl);

  return <div className="fixed bottom-20 right-3 z-40 sm:bottom-6 sm:right-5">
    {open && <div className="mb-2 w-[240px] overflow-hidden rounded-2xl border border-white/[0.1] bg-[#0d1019] shadow-2xl shadow-black/40">
      <div className="flex items-center justify-between border-b border-white/[0.08] px-3 py-2.5"><div><p className="text-xs font-black">Butuh bantuan?</p><p className="mt-0.5 text-[9px] text-white/35">{settings.supportHours}</p></div><button type="button" onClick={() => setOpen(false)} className="grid size-7 place-items-center rounded-lg text-white/45 hover:bg-white/[0.08] hover:text-white" aria-label="Tutup bantuan"><X className="size-3.5" /></button></div>
      <div className="space-y-1 p-2">
        {whatsapp && <a href={`https://wa.me/${whatsapp}`} target="_blank" rel="noreferrer" className="flex items-center gap-2 rounded-xl px-2.5 py-2 text-[11px] font-semibold text-white/70 hover:bg-white/[0.06] hover:text-white"><MessageCircle className="size-4 text-emerald-300" />WhatsApp</a>}
        {settings.discordUrl && <a href={settings.discordUrl} target="_blank" rel="noreferrer" className="flex items-center gap-2 rounded-xl px-2.5 py-2 text-[11px] font-semibold text-white/70 hover:bg-white/[0.06] hover:text-white"><MessagesSquare className="size-4 text-indigo-300" />Discord</a>}
        <Link href="/account" className="flex items-center gap-2 rounded-xl px-2.5 py-2 text-[11px] font-semibold text-white/70 hover:bg-white/[0.06] hover:text-white"><Headphones className="size-4 text-[#d8ff8d]" />Support Ticket</Link>
        {!hasExternal && <p className="px-2.5 pb-1 text-[9px] leading-4 text-white/30">WhatsApp/Discord belum diaktifkan. Support Ticket tetap tersedia.</p>}
      </div>
    </div>}
    <button type="button" onClick={() => setOpen((value) => !value)} className="ml-auto grid size-12 place-items-center rounded-full border border-[#b9ff35]/30 bg-[#b9ff35] text-[#091006] shadow-lg shadow-black/30 transition hover:scale-[1.03]" aria-label={open ? "Tutup live support" : "Buka live support"}><MessageCircle className="size-5" /></button>
  </div>;
}
