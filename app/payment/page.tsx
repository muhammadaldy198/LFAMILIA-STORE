"use client";
/* eslint-disable @next/next/no-img-element */

import { Suspense, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { QRCodeSVG } from "qrcode.react";
import { useSearchParams } from "next/navigation";
import {
  BadgeCheck,
  CheckCircle2,
  Clock3,
  Copy,
  ExternalLink,
  LoaderCircle,
  ReceiptText,
  ShieldCheck,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { StoreLayout } from "@/components/store-layout";
import { StoreBrand } from "@/components/store-brand";
import { useStorefront } from "@/hooks/use-storefront";
import {
  defaultPaymentPageSettings,
  type PaymentPageSettings,
} from "@/lib/payment-page-settings";
import { formatRupiah } from "@/lib/store-data";

type PaymentOrder = {
  referenceId: string;
  productName: string;
  packageLabel: string;
  productTotal: number;
  paymentFee: number;
  total: number;
  paymentMethod: string;
  paymentChannel: string;
  paymentStatus: string;
  fulfillmentStatus: string;
  paymentNo: string | null;
  qrContent: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  createdAt: string;
  updatedAt: string;
};

export default function PaymentPage() {
  return (
    <Suspense
      fallback={
        <StoreLayout>
          <main className="mx-auto min-h-[70vh] max-w-xl px-4 py-16 text-center text-sm text-white/40">
            Memuat pembayaran…
          </main>
        </StoreLayout>
      }
    >
      <PaymentContent />
    </Suspense>
  );
}

function PaymentContent() {
  const { settings } = useStorefront();
  const searchParams = useSearchParams();
  const invoice = (searchParams.get("invoice") ?? "").trim().toUpperCase();
  const [order, setOrder] = useState<PaymentOrder | null>(null);
  const [pageSettings, setPageSettings] = useState<PaymentPageSettings>(
    defaultPaymentPageSettings,
  );
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);
  const [paymentCopied, setPaymentCopied] = useState(false);
  const [openingPayment, setOpeningPayment] = useState(false);

  const load = useCallback(
    async (quiet = false) => {
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
        const result = (await response.json()) as {
          order?: PaymentOrder;
          error?: string;
        };
        if (!response.ok || !result.order) {
          throw new Error(result.error || "Pembayaran tidak ditemukan.");
        }
        setOrder(result.order);
        setError("");
      } catch (reason) {
        setError(
          reason instanceof Error ? reason.message : "Pembayaran gagal dimuat.",
        );
      } finally {
        if (!quiet) setLoading(false);
      }
    },
    [invoice],
  );

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    void fetch("/api/payment-page-settings", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return;
        const data = (await response.json()) as {
          settings?: PaymentPageSettings;
        };
        if (data.settings) setPageSettings(data.settings);
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    if (
      !order ||
      ["paid", "failed", "expired"].includes(order.paymentStatus)
    ) {
      return;
    }
    const timer = window.setInterval(() => void load(true), 3000);
    return () => window.clearInterval(timer);
  }, [load, order]);

  function copyInvoice() {
    if (!order) return;
    void navigator.clipboard.writeText(order.referenceId);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1600);
  }

  function copyPaymentValue(value: string) {
    void navigator.clipboard.writeText(value);
    setPaymentCopied(true);
    window.setTimeout(() => setPaymentCopied(false), 1600);
  }

  function payNow() {
    if (!order?.paymentUrl) return;
    setOpeningPayment(true);
    window.location.assign(order.paymentUrl);
  }

  if (loading) {
    return (
      <StoreLayout>
        <main className="mx-auto min-h-[70vh] max-w-xl px-4 py-16 text-center">
          <LoaderCircle
            className="mx-auto size-7 animate-spin"
            style={{ color: pageSettings.accentColor }}
          />
          <p className="mt-3 text-xs text-white/40">
            Menyiapkan pembayaran…
          </p>
        </main>
      </StoreLayout>
    );
  }

  if (error || !order) {
    return (
      <StoreLayout>
        <main className="mx-auto min-h-[70vh] max-w-xl px-4 py-16">
          <section className="panel p-6 text-center">
            <ReceiptText className="mx-auto size-8 text-white/25" />
            <h1 className="mt-4 text-lg font-black">
              Pembayaran tidak ditemukan
            </h1>
            <p className="mt-2 text-xs leading-5 text-white/45">{error}</p>
            <Button
              asChild
              className="mt-5 font-black text-[#091006]"
              style={{ backgroundColor: pageSettings.accentColor }}
            >
              <Link href="/track">{pageSettings.checkInvoiceButtonText}</Link>
            </Button>
          </section>
        </main>
      </StoreLayout>
    );
  }

  const paid = order.paymentStatus === "paid";
  const failed = ["failed", "expired"].includes(order.paymentStatus);
  const canLaunchPayment = !paid && !failed && Boolean(order.paymentUrl);
  const statusTitle = paid
    ? pageSettings.paidTitle
    : failed
      ? pageSettings.failedTitle
      : pageSettings.pendingTitle;
  const statusText = paid
    ? pageSettings.paidStatusText
    : failed
      ? pageSettings.failedStatusText
      : pageSettings.pendingStatusText;

  return (
    <StoreLayout>
      <main className="mx-auto min-h-[76vh] max-w-xl px-4 py-8 sm:py-12">
        <section className="overflow-hidden rounded-2xl border border-white/[0.09] bg-[#15181f] shadow-2xl">
          {pageSettings.headerImageUrl ? (
            <img
              src={pageSettings.headerImageUrl}
              alt=""
              className="h-36 w-full object-cover sm:h-44"
            />
          ) : null}

          <div className="border-b border-white/[0.08] bg-gradient-to-br from-white/[0.035] via-transparent to-transparent p-5 sm:p-6">
            <div className="mb-5 flex items-center justify-between gap-4 border-b border-white/[0.07] pb-4">
              {pageSettings.showStoreBrand ? (
                <StoreBrand settings={settings} compact />
              ) : (
                <span />
              )}
              <button
                type="button"
                onClick={copyInvoice}
                className="rounded-lg border border-white/[0.08] bg-black/20 px-3 py-2 text-right"
                aria-label="Salin nomor invoice"
              >
                <span className="block text-[8px] font-bold uppercase tracking-[0.16em] text-white/35">
                  Invoice
                </span>
                <span
                  className="mt-0.5 block max-w-40 truncate font-mono text-[10px] font-black"
                  style={{ color: pageSettings.accentColor }}
                >
                  {order.referenceId}
                </span>
              </button>
            </div>

            <div className="flex items-center justify-between gap-4">
              <div>
                <p
                  className="text-[9px] font-black uppercase tracking-[0.2em]"
                  style={{ color: pageSettings.accentColor }}
                >
                  {pageSettings.eyebrow}
                </p>
                <h1 className="mt-1 text-xl font-black">{statusTitle}</h1>
              </div>
              <span
                className="grid size-11 place-items-center rounded-xl"
                style={{
                  backgroundColor: paid
                    ? pageSettings.accentColor
                    : "rgba(255,255,255,0.06)",
                  color: paid ? "#091006" : pageSettings.accentColor,
                }}
              >
                {paid ? (
                  <CheckCircle2 className="size-5" />
                ) : (
                  <ShieldCheck className="size-5" />
                )}
              </span>
            </div>
            {pageSettings.subtitle ? (
              <p className="mt-2 text-xs leading-5 text-white/45">
                {pageSettings.subtitle}
              </p>
            ) : null}
          </div>

          <div className="p-5 sm:p-6">
            {pageSettings.showInvoiceNotice ? (
              <div className="rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3">
                <strong className="text-[10px] text-amber-200">
                  {pageSettings.invoiceNoticeTitle}
                </strong>
                <p className="mt-1 text-[9px] leading-4 text-white/42">
                  {pageSettings.invoiceNoticeText}
                </p>
                <div className="mt-3 flex items-center justify-between gap-3 rounded-lg bg-black/20 px-3 py-2.5">
                  <code className="break-all text-sm font-black tracking-wider text-white">
                    {order.referenceId}
                  </code>
                  <button
                    type="button"
                    onClick={copyInvoice}
                    className="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold"
                    style={{ color: pageSettings.accentColor }}
                  >
                    <Copy className="size-3.5" />
                    {copied ? "Tersalin" : "Salin Invoice"}
                  </button>
                </div>
              </div>
            ) : null}

            {pageSettings.showOrderSummary ? (
              <dl
                className={
                  pageSettings.showInvoiceNotice
                    ? "mt-5 space-y-3 text-xs"
                    : "space-y-3 text-xs"
                }
              >
                <Row label="Produk" value={order.productName} />
                <Row label="Nominal" value={order.packageLabel} />
                <Row
                  label="Metode"
                  value={paymentLabel(
                    order.paymentMethod,
                    order.paymentChannel,
                  )}
                />
                <div className="h-px bg-white/[0.08]" />
                <Row label="Harga setelah promo" value={formatRupiah(order.productTotal)} />
                <Row label="Biaya pembayaran" value={formatRupiah(order.paymentFee)} />
                <Row
                  label="Total pembayaran"
                  value={formatRupiah(order.total)}
                  strong
                  accentColor={pageSettings.accentColor}
                />
              </dl>
            ) : null}

            {!paid && !failed && order.qrContent ? (
              <div className="mt-5 rounded-xl border border-white/[0.08] bg-white p-4 text-center">
                <QRCodeSVG
                  value={order.qrContent}
                  size={220}
                  level="M"
                  className="mx-auto h-auto w-full max-w-[220px]"
                />
                <p className="mt-3 text-[10px] font-black text-[#091006]">
                  Scan QRIS untuk membayar
                </p>
                <p className="mt-1 text-[8px] text-black/55">
                  Gunakan aplikasi bank atau e-wallet yang mendukung QRIS.
                </p>
              </div>
            ) : null}

            {!paid && !failed && order.paymentNo ? (
              <div className="mt-5 rounded-xl border border-white/[0.08] bg-white/[0.03] p-4">
                <p className="text-[9px] font-bold uppercase tracking-[0.14em] text-white/35">
                  {order.paymentName || "Nomor pembayaran"}
                </p>
                <div className="mt-2 flex items-center justify-between gap-3 rounded-lg bg-black/25 px-3 py-3">
                  <code className="break-all text-base font-black tracking-wider text-white">
                    {order.paymentNo}
                  </code>
                  <button
                    type="button"
                    onClick={() => copyPaymentValue(order.paymentNo!)}
                    className="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold"
                    style={{ color: pageSettings.accentColor }}
                  >
                    <Copy className="size-3.5" />
                    {paymentCopied ? "Tersalin" : "Salin"}
                  </button>
                </div>
              </div>
            ) : null}

            {!paid && !failed && order.expiredAt ? (
              <p className="mt-3 text-center text-[9px] text-white/35">
                Berlaku sampai {order.expiredAt}
              </p>
            ) : null}

            {pageSettings.showStatusBox ? (
              <div className="mt-5 flex items-center gap-2 rounded-xl bg-white/[0.035] p-3 text-[10px] text-white/48">
                {paid ? (
                  <BadgeCheck
                    className="size-4 shrink-0"
                    style={{ color: pageSettings.accentColor }}
                  />
                ) : (
                  <Clock3 className="size-4 shrink-0 text-sky-300" />
                )}
                <span>{statusText}</span>
              </div>
            ) : null}

            {canLaunchPayment ? (
              <Button
                type="button"
                onClick={payNow}
                disabled={openingPayment}
                className="mt-5 h-12 w-full rounded-xl font-black text-[#091006]"
                style={{ backgroundColor: pageSettings.accentColor }}
              >
                {openingPayment ? (
                  <LoaderCircle className="mr-2 size-4 animate-spin" />
                ) : (
                  <ExternalLink className="mr-2 size-4" />
                )}
                {openingPayment ? "Memproses…" : pageSettings.payButtonText}
              </Button>
            ) : null}

            {paid ? (
              <Button
                asChild
                className="mt-5 h-12 w-full rounded-xl font-black text-[#091006]"
                style={{ backgroundColor: pageSettings.accentColor }}
              >
                <Link
                  href={`/track?invoice=${encodeURIComponent(order.referenceId)}`}
                >
                  Lihat status pesanan
                </Link>
              </Button>
            ) : null}

            <div className="mt-3 grid grid-cols-2 gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => void load()}
                className="border-white/10 bg-white/[0.03] text-white"
              >
                {pageSettings.checkStatusButtonText}
              </Button>
              <Button
                asChild
                variant="outline"
                className="border-white/10 bg-white/[0.03] text-white"
              >
                <Link
                  href={`/track?invoice=${encodeURIComponent(order.referenceId)}`}
                >
                  {pageSettings.checkInvoiceButtonText}
                </Link>
              </Button>
            </div>

            {pageSettings.showSupport && pageSettings.supportText ? (
              <div className="mt-4 text-center text-[9px] text-white/35">
                {pageSettings.supportUrl ? (
                  <Link
                    href={pageSettings.supportUrl}
                    className="font-bold underline underline-offset-4"
                    style={{ color: pageSettings.accentColor }}
                  >
                    {pageSettings.supportText}
                  </Link>
                ) : (
                  pageSettings.supportText
                )}
              </div>
            ) : null}
          </div>
        </section>
      </main>
    </StoreLayout>
  );
}

function Row({
  label,
  value,
  strong = false,
  accentColor,
}: {
  label: string;
  value: string;
  strong?: boolean;
  accentColor?: string;
}) {
  return (
    <div className="flex items-start justify-between gap-4">
      <dt className="text-white/35">{label}</dt>
      <dd
        className={`max-w-[65%] text-right ${
          strong ? "text-lg font-black" : "font-bold"
        }`}
        style={strong && accentColor ? { color: accentColor } : undefined}
      >
        {value}
      </dd>
    </div>
  );
}

function paymentLabel(method: string, channel: string) {
  if (method === "qris") return "QRIS";
  if (method === "va") return `Virtual Account • ${channel.toUpperCase()}`;
  if (method === "ewallet") return `E-Wallet • ${channel.toUpperCase()}`;
  if (method === "wallet") return "Koin LFAMILIA";
  return method.replaceAll("_", " ").toUpperCase();
}
