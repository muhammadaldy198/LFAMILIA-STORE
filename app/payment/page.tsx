"use client";
/* eslint-disable @next/next/no-img-element */

import { Suspense, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { BadgeCheck, CheckCircle2, Clock3, Copy, ExternalLink, LoaderCircle, ReceiptText, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { StoreLayout } from "@/components/store-layout";
import { formatRupiah } from "@/lib/store-data";

type PaymentOrder = {
  referenceId: string;
  productName: string;
  packageLabel: string;
  total: number;
  paymentMethod: string;
  paymentChannel: string;
  paymentStatus: string;
  fulfillmentStatus: string;
  paymentGateway: "midtrans" | "ipaymu" | null;
  midtransMode: "snap" | "bisnap" | null;
  paymentNo: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  createdAt: string;
  updatedAt: string;
};

type MidtransClientConfig = {
  enabled: boolean;
  environment: "sandbox" | "production";
  clientKey: string | null;
  scriptUrl: string;
};

type SnapCallbacks = {
  onSuccess?: () => void;
  onPending?: () => void;
  onError?: () => void;
  onClose?: () => void;
};

declare global {
  interface Window {
    snap?: {
      pay: (token: string, callbacks?: SnapCallbacks) => void;
    };
  }
}

function snapTokenFromUrl(value: string | null) {
  if (!value) return null;
  try {
    const url = new URL(value);
    const parts = url.pathname.split("/").filter(Boolean);
    const redirectionIndex = parts.lastIndexOf("redirection");
    if (redirectionIndex >= 0 && parts[redirectionIndex + 1])
      return decodeURIComponent(parts[redirectionIndex + 1]);
    return null;
  } catch {
    return null;
  }
}

export default function PaymentPage() {
  return (
    <Suspense fallback={<StoreLayout><main className="mx-auto min-h-[70vh] max-w-xl px-4 py-16 text-center text-sm text-white/40">Memuat pembayaran…</main></StoreLayout>}>
      <PaymentContent />
    </Suspense>
  );
}

function PaymentContent() {
  const searchParams = useSearchParams();
  const invoice = (searchParams.get("invoice") ?? "").trim().toUpperCase();
  const querySnapToken = (searchParams.get("token") ?? "").trim();
  const [order, setOrder] = useState<PaymentOrder | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);
  const [snapToken, setSnapToken] = useState(querySnapToken);
  const [snapReady, setSnapReady] = useState(false);
  const [openingPayment, setOpeningPayment] = useState(false);

  const load = useCallback(async (quiet = false) => {
    if (!invoice) {
      setError("Invoice pembayaran tidak ditemukan.");
      setLoading(false);
      return;
    }
    if (!quiet) setLoading(true);
    try {
      const response = await fetch("/api/orders/status", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ referenceId: invoice }),
        cache: "no-store",
      });
      const result = await response.json() as { order?: PaymentOrder; error?: string };
      if (!response.ok || !result.order) throw new Error(result.error || "Pembayaran tidak ditemukan.");
      setOrder(result.order);
      setError("");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pembayaran gagal dimuat.");
    } finally {
      if (!quiet) setLoading(false);
    }
  }, [invoice]);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    if (!invoice || snapToken) return;
    try {
      const stored = window.sessionStorage.getItem(`lfamilia-snap-token:${invoice}`);
      if (stored) setSnapToken(stored);
    } catch {
      // Token query dan payment URL tetap menjadi fallback.
    }
  }, [invoice, snapToken]);

  useEffect(() => {
    if (
      order?.paymentGateway !== "midtrans" ||
      order.midtransMode !== "snap"
    )
      return;
    if (snapToken || !order?.paymentUrl) return;
    const token = snapTokenFromUrl(order.paymentUrl);
    if (token) setSnapToken(token);
  }, [order?.paymentGateway, order?.paymentUrl, snapToken]);

  useEffect(() => {
    if (!snapToken) return;
    let cancelled = false;
    let script: HTMLScriptElement | null = null;

    void fetch("/api/payments/midtrans/client-config", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return null;
        return await response.json() as MidtransClientConfig;
      })
      .then((config) => {
        if (cancelled || !config?.enabled || !config.clientKey) return;
        if (window.snap?.pay) {
          setSnapReady(true);
          return;
        }

        const existing = document.querySelector<HTMLScriptElement>("script[data-lfamilia-midtrans-snap='true']");
        if (existing) {
          existing.addEventListener("load", () => !cancelled && setSnapReady(Boolean(window.snap?.pay)), { once: true });
          return;
        }

        script = document.createElement("script");
        script.src = config.scriptUrl;
        script.async = true;
        script.setAttribute("data-client-key", config.clientKey);
        script.setAttribute("data-lfamilia-midtrans-snap", "true");
        script.onload = () => {
          if (!cancelled) setSnapReady(Boolean(window.snap?.pay));
        };
        script.onerror = () => {
          if (!cancelled) setSnapReady(false);
        };
        document.body.appendChild(script);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
      if (script) {
        script.onload = null;
        script.onerror = null;
      }
    };
  }, [snapToken]);

  useEffect(() => {
    if (!order || ["paid", "failed", "expired"].includes(order.paymentStatus)) return;
    const timer = window.setInterval(() => void load(true), 3000);
    return () => window.clearInterval(timer);
  }, [load, order]);

  function copyInvoice() {
    if (!order) return;
    void navigator.clipboard.writeText(order.referenceId);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1600);
  }

  function payNow() {
    if (!order?.paymentUrl) return;
    if (!snapReady || !snapToken || !window.snap?.pay) {
      window.location.assign(order.paymentUrl);
      return;
    }

    setOpeningPayment(true);
    const refreshStatus = () => {
      setOpeningPayment(false);
      window.setTimeout(() => void load(true), 800);
    };
    window.snap.pay(snapToken, {
      onSuccess: refreshStatus,
      onPending: refreshStatus,
      onError: refreshStatus,
      onClose: () => setOpeningPayment(false),
    });
  }

  if (loading) {
    return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-xl px-4 py-16 text-center"><LoaderCircle className="mx-auto size-7 animate-spin text-[#b9ff35]" /><p className="mt-3 text-xs text-white/40">Menyiapkan pembayaran…</p></main></StoreLayout>;
  }

  if (error || !order) {
    return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-xl px-4 py-16"><section className="panel p-6 text-center"><ReceiptText className="mx-auto size-8 text-white/25" /><h1 className="mt-4 text-lg font-black">Pembayaran tidak ditemukan</h1><p className="mt-2 text-xs leading-5 text-white/45">{error}</p><Button asChild className="mt-5 bg-[#b9ff35] font-black text-[#091006]"><Link href="/track">Cek transaksi</Link></Button></section></main></StoreLayout>;
  }

  const paid = order.paymentStatus === "paid";
  const failed = ["failed", "expired"].includes(order.paymentStatus);
  const isBisnapQris =
    order.paymentGateway === "midtrans" &&
    order.midtransMode === "bisnap" &&
    order.paymentMethod === "qris";

  return (
    <StoreLayout>
      <main className="mx-auto min-h-[76vh] max-w-xl px-4 py-8 sm:py-12">
        <section className="overflow-hidden rounded-2xl border border-white/[0.09] bg-[#15181f] shadow-2xl">
          <div className="border-b border-white/[0.08] bg-gradient-to-br from-[#b9ff35]/12 via-transparent to-transparent p-5 sm:p-6">
            <div className="flex items-center justify-between gap-4">
              <div>
                <p className="text-[9px] font-black uppercase tracking-[0.2em] text-[#cfff72]">LFAMILIA PAYMENT</p>
                <h1 className="mt-1 text-xl font-black">{paid ? "Pembayaran berhasil" : failed ? "Pembayaran tidak aktif" : "Selesaikan pembayaran"}</h1>
              </div>
              <span className={`grid size-11 place-items-center rounded-xl ${paid ? "bg-[#b9ff35] text-[#091006]" : "bg-white/[0.06] text-[#cfff72]"}`}>
                {paid ? <CheckCircle2 className="size-5" /> : <ShieldCheck className="size-5" />}
              </span>
            </div>
            <p className="mt-2 text-xs leading-5 text-white/45">Pembayaran diproses melalui {order.paymentGateway === "ipaymu" ? "iPaymu" : "Midtrans"}. Data transaksi tetap tercatat di LFAMILIA STORE.</p>
          </div>

          <div className="p-5 sm:p-6">
            <div className="rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3">
              <strong className="text-[10px] text-amber-200">Simpan invoice sebelum membayar</strong>
              <p className="mt-1 text-[9px] leading-4 text-white/42">Invoice diperlukan untuk mengecek transaksi jika halaman pembayaran tertutup atau terjadi kendala.</p>
              <div className="mt-3 flex items-center justify-between gap-3 rounded-lg bg-black/20 px-3 py-2.5">
                <code className="break-all text-sm font-black tracking-wider text-white">{order.referenceId}</code>
                <button type="button" onClick={copyInvoice} className="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold text-[#cfff72]"><Copy className="size-3.5" />{copied ? "Tersalin" : "Salin Invoice"}</button>
              </div>
            </div>

            <dl className="mt-5 space-y-3 text-xs">
              <Row label="Produk" value={order.productName} />
              <Row label="Nominal" value={order.packageLabel} />
              <Row label="Metode" value={paymentLabel(order.paymentMethod, order.paymentChannel)} />
              <div className="h-px bg-white/[0.08]" />
              <Row label="Total pembayaran" value={formatRupiah(order.total)} strong />
            </dl>

            {!paid && !failed && order.paymentNo && (
              <div className="mt-5 rounded-xl border border-white/[0.08] bg-black/20 p-3">
                <span className="text-[9px] uppercase tracking-wider text-white/35">
                  {order.paymentName || "Nomor pembayaran"}
                </span>
                <div className="mt-1.5 flex items-center justify-between gap-3">
                  <strong className="break-all text-sm text-[#d8ff8d]">
                    {order.paymentNo}
                  </strong>
                  <button
                    type="button"
                    onClick={() =>
                      void navigator.clipboard.writeText(order.paymentNo!)
                    }
                    className="shrink-0 text-white/45 hover:text-white"
                    aria-label="Salin nomor pembayaran"
                  >
                    <Copy className="size-4" />
                  </button>
                </div>
              </div>
            )}

            {!paid && !failed && isBisnapQris && order.paymentUrl && (
              <div className="mt-5 rounded-xl bg-white p-3">
                <img
                  src={order.paymentUrl}
                  alt="QRIS pembayaran Midtrans"
                  className="mx-auto aspect-square w-full max-w-72 object-contain"
                />
              </div>
            )}

            {!paid && !failed && order.expiredAt && (
              <p className="mt-3 text-center text-[9px] text-white/35">
                Berlaku sampai {order.expiredAt}
              </p>
            )}

            <div className="mt-5 flex items-center gap-2 rounded-xl bg-white/[0.035] p-3 text-[10px] text-white/48">
              {paid ? <BadgeCheck className="size-4 shrink-0 text-[#b9ff35]" /> : <Clock3 className="size-4 shrink-0 text-sky-300" />}
              <span>{paid ? "Pembayaran sudah diterima. Status pesanan akan diperbarui otomatis." : failed ? "Transaksi ini tidak dapat dilanjutkan. Buat checkout baru bila diperlukan." : "Status diperiksa otomatis setiap 3 detik."}</span>
            </div>

            {!paid && !failed && order.paymentUrl && !isBisnapQris && (
              <>
                <Button type="button" onClick={payNow} disabled={openingPayment} className="mt-5 h-12 w-full rounded-xl bg-[#bca17d] font-black text-white hover:bg-[#d1b18b]">
                  {openingPayment ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : null}
                  {openingPayment ? "Membuka pembayaran..." : "Bayar Sekarang"}
                  {!openingPayment && (snapReady ? <ShieldCheck className="ml-2 size-4" /> : <ExternalLink className="ml-2 size-4" />)}
                </Button>
                <p className="mt-2 text-center text-[9px] text-white/30">
                  {order.paymentGateway === "ipaymu"
                    ? "Pembayaran dibuka melalui halaman iPaymu."
                    : snapReady
                      ? "Pembayaran dibuka di atas halaman LFAMILIA."
                      : "Jika popup belum aktif, pembayaran dibuka melalui halaman Midtrans."}
                </p>
              </>
            )}
            {paid && (
              <Button asChild className="mt-5 h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]">
                <Link href={`/track?invoice=${encodeURIComponent(order.referenceId)}`}>Lihat status pesanan</Link>
              </Button>
            )}
            <div className="mt-3 grid grid-cols-2 gap-2">
              <Button type="button" variant="outline" onClick={() => void load()} className="border-white/10 bg-white/[0.03] text-white">Cek status</Button>
              <Button asChild variant="outline" className="border-white/10 bg-white/[0.03] text-white"><Link href={`/track?invoice=${encodeURIComponent(order.referenceId)}`}>Cek invoice</Link></Button>
            </div>
          </div>
        </section>
      </main>
    </StoreLayout>
  );
}

function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return <div className="flex items-start justify-between gap-4"><dt className="text-white/35">{label}</dt><dd className={`max-w-[65%] text-right ${strong ? "text-lg font-black text-[#cfff72]" : "font-bold"}`}>{value}</dd></div>;
}

function paymentLabel(method: string, channel: string) {
  if (method === "qris") return "QRIS";
  if (method === "va") return `Virtual Account • ${channel.toUpperCase()}`;
  if (method === "ewallet") return `E-Wallet • ${channel.toUpperCase()}`;
  if (method === "wallet") return "Koin LFAMILIA";
  return method.replaceAll("_", " ").toUpperCase();
}
