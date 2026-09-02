"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Check, Copy, ReceiptText, X } from "lucide-react";

const legacyReference = /LF-\d{8}-[A-F0-9]{8,12}/g;

function publicInvoice(referenceId: string) {
  const token = referenceId.split("-").at(-1) ?? referenceId;
  return `LF${token}`;
}

export function CheckoutInvoiceReminder() {
  const [invoice, setInvoice] = useState("");
  const [copied, setCopied] = useState(false);
  const [dismissed, setDismissed] = useState(false);

  useEffect(() => {
    let frame = 0;
    function check() {
      if (frame) return;
      frame = window.requestAnimationFrame(() => {
        frame = 0;
        const matches = document.body.textContent?.match(legacyReference) ?? [];
        const found = matches.at(-1);
        if (found) {
          setInvoice(publicInvoice(found));
          setDismissed(false);
        }
      });
    }
    const observer = new MutationObserver(check);
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    check();
    return () => {
      observer.disconnect();
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, []);

  if (!invoice || dismissed) return null;

  return (
    <div className="fixed inset-x-3 top-3 z-[80] mx-auto max-w-md rounded-xl border border-amber-300/25 bg-[#17191f]/95 p-3 shadow-2xl backdrop-blur sm:top-5">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-amber-300/10 text-amber-200"><ReceiptText className="size-4" /></span>
        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-2">
            <div><strong className="block text-xs">Simpan invoice kamu</strong><p className="mt-0.5 text-[9px] leading-4 text-white/40">Gunakan invoice ini jika halaman tertutup atau transaksi bermasalah.</p></div>
            <button type="button" onClick={() => setDismissed(true)} className="text-white/35 hover:text-white" aria-label="Tutup"><X className="size-4" /></button>
          </div>
          <div className="mt-2 flex items-center justify-between gap-2 rounded-lg bg-black/20 px-3 py-2">
            <code className="break-all text-xs font-black tracking-wider text-white">{invoice}</code>
            <button type="button" onClick={() => { void navigator.clipboard.writeText(invoice); setCopied(true); window.setTimeout(() => setCopied(false), 1600); }} className="inline-flex shrink-0 items-center gap-1 text-[9px] font-bold text-[#cfff72]">{copied ? <Check className="size-3" /> : <Copy className="size-3" />}{copied ? "Tersalin" : "Salin"}</button>
          </div>
          <Link href={`/track?invoice=${encodeURIComponent(invoice)}`} className="mt-2 inline-block text-[9px] font-bold text-white/45 hover:text-[#cfff72]">Cek invoice ini →</Link>
        </div>
      </div>
    </div>
  );
}
