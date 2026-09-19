"use client";

import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { SitePopupRecord } from "@/lib/server/content";

export function GlobalHomePopup() {
  const [items, setItems] = useState<SitePopupRecord[]>([]);
  const [index, setIndex] = useState(0);
  const [open, setOpen] = useState(false);
  const [hideAgain, setHideAgain] = useState(false);
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    void fetch("/api/home-content", { cache: "no-store" }).then(async (response) => {
      const data = await response.json().catch(() => ({})) as { popups?: SitePopupRecord[]; error?: string };
      if (!response.ok) throw new Error(data.error || "Pop-up gagal dimuat.");
      return data;
    }).then((data) => {
      if (!mounted) return;
      const visible = (data.popups ?? []).filter((item) => Number(window.localStorage.getItem(popupKey(item)) || 0) < Date.now());
      setItems(visible);
      setOpen(visible.length > 0);
    }).catch(() => {
      if (!mounted || retryKey >= 1) return;
      window.setTimeout(() => {
        if (mounted) setRetryKey((value) => value + 1);
      }, 1500);
    });
    return () => { mounted = false; };
  }, [retryKey]);

  function close() {
    if (hideAgain) {
      for (const item of items) window.localStorage.setItem(popupKey(item), String(Date.now() + Math.max(1, item.dismissDays) * 86_400_000));
    }
    setOpen(false);
  }

  if (!items.length) return null;
  const item = items[index];
  return (
    <Dialog open={open} onOpenChange={(value) => { if (!value) close(); else setOpen(true); }}>
      <DialogContent className="max-w-xl overflow-hidden border-white/10 bg-[#111318] p-0 text-white" showCloseButton={false}>
        <div className="flex items-center justify-between border-b border-white/[0.08] px-5 py-4"><span className="font-mono text-xs text-white/42">{index + 1}/{items.length}</span><button type="button" onClick={close} className="grid size-10 place-items-center rounded-xl border border-[#d9b989]/50 text-[#ead0a8] hover:bg-white/[0.05]" aria-label="Tutup pop-up"><X className="size-6" /></button></div>
        <div className="px-5 py-6 sm:px-7"><DialogHeader><DialogTitle className="text-left text-lg font-black leading-7">{item.title}</DialogTitle><DialogDescription className="mt-3 whitespace-pre-line text-left text-sm leading-7 text-white/68">{item.body}</DialogDescription></DialogHeader>
          {(item.primaryHref || item.secondaryHref) && <div className="mt-5 flex flex-wrap gap-2">{item.primaryHref && <Button asChild className="rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006]"><a href={item.primaryHref} target={item.primaryHref.startsWith("http") ? "_blank" : undefined} rel="noreferrer">{item.primaryLabel || "Buka tautan"}</a></Button>}{item.secondaryHref && <Button asChild variant="outline" className="rounded-xl border-white/10 bg-white/[0.03] text-xs text-white"><a href={item.secondaryHref} target={item.secondaryHref.startsWith("http") ? "_blank" : undefined} rel="noreferrer">{item.secondaryLabel || "Tautan lainnya"}</a></Button>}</div>}
          {items.length > 1 && <div className="mt-6 flex items-center justify-between"><Button type="button" variant="ghost" disabled={index === 0} onClick={() => setIndex((value) => value - 1)} className="text-white/55"><ChevronLeft className="mr-1 size-4" />Sebelumnya</Button><Button type="button" variant="ghost" disabled={index === items.length - 1} onClick={() => setIndex((value) => value + 1)} className="text-white/55">Berikutnya<ChevronRight className="ml-1 size-4" /></Button></div>}
        </div>
        <label className="flex cursor-pointer items-center gap-3 border-t border-white/[0.08] px-5 py-5 text-xs font-semibold text-white/58 sm:px-7"><input type="checkbox" checked={hideAgain} onChange={(event) => setHideAgain(event.target.checked)} className="size-5 accent-[#b9ff35]" />Jangan tampilkan lagi</label>
      </DialogContent>
    </Dialog>
  );
}

function popupKey(item: SitePopupRecord) {
  return `lfamilia-home-popup:${item.id ?? "fallback"}:${item.title.slice(0, 24)}`;
}
