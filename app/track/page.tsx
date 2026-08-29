"use client";

import { FormEvent, useState } from "react";
import { AlertTriangle, CheckCircle2, Clock3, Copy, Loader2, PackageCheck, Search, TimerReset, XCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { StoreLayout } from "@/components/store-layout";
import { demoOrder, formatRupiah } from "@/lib/store-data";

type TrackedOrder = {
  referenceId: string;
  productName: string;
  packageLabel: string;
  destination: string;
  total: number;
  paymentStatus: string;
  fulfillmentStatus: string;
  fulfillmentType: "automatic" | "manual";
  createdAt: string;
  updatedAt: string;
};

const paymentLabels: Record<string, string> = {
  pending: "Menunggu pembayaran",
  paid: "Pembayaran diterima",
  expired: "Pembayaran kedaluwarsa",
  failed: "Pembayaran gagal",
};

const fulfillmentLabels: Record<string, string> = {
  waiting_payment: "Menunggu pembayaran",
  processing: "Sedang diproses provider",
  pending: "Sedang diproses provider",
  manual_pending: "Menunggu diproses admin",
  needs_review: "Perlu diperiksa admin",
  success: "Pesanan selesai",
  error: "Pengiriman gagal",
  failed: "Pengiriman gagal",
};

function demoResult(): TrackedOrder {
  return {
    referenceId: demoOrder.id,
    productName: demoOrder.product,
    packageLabel: demoOrder.item,
    destination: demoOrder.destination,
    total: demoOrder.amount,
    paymentStatus: "paid",
    fulfillmentStatus: "success",
    fulfillmentType: "automatic",
    createdAt: "2026-08-28T07:31:00.000Z",
    updatedAt: "2026-08-28T07:32:00.000Z",
  };
}

function dateLabel(value: string) {
  const date = new Date(value.includes("T") ? value : `${value.replace(" ", "T")}Z`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "long",
    timeStyle: "short",
    timeZone: "Asia/Jakarta",
  }).format(date) + " WIB";
}

function statusPresentation(order: TrackedOrder) {
  if (["failed", "expired"].includes(order.paymentStatus)) {
    return { label: paymentLabels[order.paymentStatus], tone: "text-red-300 bg-red-400/10", Icon: XCircle };
  }
  if (["error", "failed", "needs_review"].includes(order.fulfillmentStatus)) {
    return { label: fulfillmentLabels[order.fulfillmentStatus], tone: "text-amber-300 bg-amber-400/10", Icon: AlertTriangle };
  }
  if (order.fulfillmentStatus === "success") {
    return { label: "Berhasil", tone: "text-[#cfff72] bg-[#b9ff35]/10", Icon: CheckCircle2 };
  }
  return {
    label: order.paymentStatus === "paid" ? fulfillmentLabels[order.fulfillmentStatus] || "Sedang diproses" : paymentLabels[order.paymentStatus] || "Menunggu pembayaran",
    tone: "text-sky-300 bg-sky-400/10",
    Icon: Clock3,
  };
}

export default function TrackPage() {
  const [invoice, setInvoice] = useState("");
  const [order, setOrder] = useState<TrackedOrder | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [copied, setCopied] = useState(false);

  async function search(event: FormEvent) {
    event.preventDefault();
    const referenceId = invoice.trim().toUpperCase();
    setOrder(null);
    setError("");
    if (!referenceId) {
      setError("Masukkan nomor invoice terlebih dahulu.");
      return;
    }
    if (referenceId === demoOrder.id) {
      setOrder(demoResult());
      return;
    }

    setLoading(true);
    try {
      const response = await fetch("/api/orders/status", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ referenceId }),
      });
      const result = await response.json() as { order?: TrackedOrder; error?: string };
      if (!response.ok || !result.order) throw new Error(result.error || "Invoice tidak ditemukan.");
      setOrder(result.order);
    } catch (searchError) {
      setError(searchError instanceof Error ? searchError.message : "Status pesanan gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }

  function useDemo() {
    setInvoice(demoOrder.id);
    setError("");
    setOrder(demoResult());
  }

  function copyId() {
    if (!order) return;
    void navigator.clipboard?.writeText(order.referenceId);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1500);
  }

  const status = order ? statusPresentation(order) : null;

  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-3xl px-4 py-14 sm:px-6 sm:py-20">
        <div className="text-center">
          <p className="eyebrow">Lacak pesanan</p>
          <h1 className="section-title">Cek status transaksi</h1>
          <p className="mx-auto mt-4 max-w-xl text-sm leading-6 text-white/42">Masukkan nomor invoice yang diterima setelah checkout. Informasi pribadi pelanggan tetap disembunyikan.</p>
        </div>

        <form onSubmit={search} className="panel mt-9 p-4 sm:p-5">
          <label className="field-label" htmlFor="invoice">Nomor invoice</label>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Input id="invoice" value={invoice} onChange={(event) => { setInvoice(event.target.value); setOrder(null); setError(""); }} placeholder="Contoh: LF-20260829-A1B2C3D4E5F6" autoComplete="off" className="h-12 rounded-xl border-white/10 bg-white/[0.035] font-mono text-sm uppercase text-white placeholder:font-sans placeholder:text-white/22" />
            <Button type="submit" disabled={loading} className="h-12 rounded-xl bg-[#b9ff35] px-6 font-black text-[#091006] hover:bg-[#d0ff75] disabled:opacity-60">
              {loading ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Search className="mr-2 size-4" />}{loading ? "Mencari" : "Cari"}
            </Button>
          </div>
          <button type="button" onClick={useDemo} className="mt-3 inline-flex items-center gap-2 text-[11px] font-semibold text-[#cfff72] hover:underline"><TimerReset className="size-3.5" /> Coba invoice demo: {demoOrder.id}</button>
        </form>

        {error && <div role="alert" className="panel mt-5 p-8 text-center"><Search className="mx-auto size-8 text-white/18" /><h2 className="mt-4 font-bold">Invoice tidak ditemukan</h2><p className="mt-2 text-sm leading-6 text-white/38">{error}</p></div>}

        {order && status && <section className="panel mt-5 overflow-hidden">
          <div className="flex items-start justify-between gap-4 border-b border-white/[0.08] bg-[#b9ff35]/[0.06] p-5 sm:p-6">
            <div><span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[9px] font-black uppercase tracking-wider ${status.tone}`}><status.Icon className="size-3" /> {status.label}</span><h2 className="mt-4 text-xl font-black">{fulfillmentLabels[order.fulfillmentStatus] || status.label}</h2><p className="mt-1 text-xs text-white/38">Dibuat {dateLabel(order.createdAt)}</p></div>
            <PackageCheck className="size-9 text-[#b9ff35]" />
          </div>
          <div className="p-5 sm:p-6">
            <div className="flex items-center justify-between gap-3 rounded-xl bg-white/[0.035] p-3"><span className="break-all font-mono text-xs text-white/60">{order.referenceId}</span><button type="button" onClick={copyId} className="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold text-[#cfff72]"><Copy className="size-3" />{copied ? "Tersalin" : "Salin"}</button></div>
            <dl className="mt-5 grid gap-4 text-xs sm:grid-cols-2">{[["Produk", order.productName], ["Item", order.packageLabel], ["Tujuan", order.destination], ["Total", formatRupiah(order.total)]].map(([label, value]) => <div key={label} className="border-b border-white/[0.07] pb-4"><dt className="text-white/32">{label}</dt><dd className="mt-1.5 font-bold">{value}</dd></div>)}</dl>
            <div className="mt-6 grid grid-cols-[24px_1fr] gap-x-3 gap-y-1 text-xs">
              <span className={`mt-0.5 grid size-5 place-items-center rounded-full ${order.paymentStatus === "paid" ? "bg-[#b9ff35] text-[#091006]" : "bg-white/10 text-white/45"}`}>{order.paymentStatus === "paid" ? <CheckCircle2 className="size-3" /> : <Clock3 className="size-3" />}</span>
              <div><strong>{paymentLabels[order.paymentStatus] || "Status pembayaran diperbarui"}</strong><p className="mt-1 text-[10px] text-white/32">{dateLabel(order.createdAt)}</p></div>
              <div className="ml-2 h-6 border-l border-[#b9ff35]/30" /><div />
              <span className={`mt-0.5 grid size-5 place-items-center rounded-full ${order.fulfillmentStatus === "success" ? "bg-[#b9ff35] text-[#091006]" : "bg-white/10 text-white/45"}`}>{order.fulfillmentStatus === "success" ? <CheckCircle2 className="size-3" /> : <Clock3 className="size-3" />}</span>
              <div><strong>{fulfillmentLabels[order.fulfillmentStatus] || "Status pesanan diperbarui"}</strong><p className="mt-1 text-[10px] text-white/32">Terakhir diperbarui {dateLabel(order.updatedAt)}</p></div>
            </div>
          </div>
        </section>}
      </main>
    </StoreLayout>
  );
}
