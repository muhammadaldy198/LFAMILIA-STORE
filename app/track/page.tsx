"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  Clock3,
  Copy,
  Eye,
  History,
  Loader2,
  PackageCheck,
  Radio,
  Search,
  XCircle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { StoreLayout } from "@/components/store-layout";
import { formatRupiah } from "@/lib/store-data";

type OrderEvent = { source: string; status: string; createdAt: string };
type TrackedOrder = {
  referenceId: string;
  productName: string;
  packageLabel: string;
  destination: string | null;
  total: number;
  paymentMethod: string;
  paymentChannel: string;
  paymentStatus: string;
  fulfillmentStatus: string;
  fulfillmentType: "automatic" | "manual";
  voucherCode?: string | null;
  events: OrderEvent[];
  createdAt: string;
  updatedAt: string;
};
type OrderSummary = {
  referenceId: string | null;
  maskedReferenceId: string;
  productName: string;
  packageLabel: string;
  total: number;
  status: string;
  createdAt: string;
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
const eventLabels: Record<string, string> = {
  created: "Pesanan dibuat",
  pending: "Menunggu pembayaran",
  paid: "Pembayaran diterima",
  settlement: "Pembayaran diterima",
  processing: "Pesanan sedang diproses",
  manual_pending: "Menunggu diproses admin",
  success: "Pesanan berhasil",
  failed: "Transaksi gagal",
  failure: "Transaksi gagal",
  expired: "Pembayaran kedaluwarsa",
  expire: "Pembayaran kedaluwarsa",
  cancel: "Transaksi dibatalkan",
  needs_review: "Perlu diperiksa admin",
  error: "Terjadi kendala pemrosesan",
};
const sourceLabels: Record<string, string> = {
  system: "LFAMILIA",
  midtrans: "Midtrans",
  wallet: "Saldo",
  digiflazz: "DigiFlazz",
  vippayment: "VIP Payment",
  voucher_stock: "Voucher",
  admin: "Admin",
};
const publicStatusLabels: Record<string, string> = {
  pending: "Menunggu pembayaran",
  paid: "Pembayaran diterima",
  processing: "Diproses",
  manual_pending: "Menunggu admin",
  needs_review: "Perlu diperiksa",
  success: "Berhasil",
  failed: "Gagal",
  error: "Gagal",
  expired: "Kedaluwarsa",
};

function dateLabel(value: string) {
  const date = new Date(value.includes("T") ? value : `${value.replace(" ", "T")}Z`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "medium",
    timeZone: "Asia/Jakarta",
  }).format(date) + " WIB";
}
function statusPresentation(order: TrackedOrder) {
  if (["failed", "expired"].includes(order.paymentStatus))
    return { label: paymentLabels[order.paymentStatus], tone: "text-red-300 bg-red-400/10", Icon: XCircle };
  if (["error", "failed", "needs_review"].includes(order.fulfillmentStatus))
    return { label: fulfillmentLabels[order.fulfillmentStatus], tone: "text-amber-300 bg-amber-400/10", Icon: AlertTriangle };
  if (order.fulfillmentStatus === "success")
    return { label: "Berhasil", tone: "text-[#cfff72] bg-[#b9ff35]/10", Icon: CheckCircle2 };
  return {
    label: order.paymentStatus === "paid"
      ? fulfillmentLabels[order.fulfillmentStatus] || "Sedang diproses"
      : paymentLabels[order.paymentStatus] || "Menunggu pembayaran",
    tone: "text-sky-300 bg-sky-400/10",
    Icon: Clock3,
  };
}
function isFinal(order: TrackedOrder) {
  return ["failed", "expired"].includes(order.paymentStatus)
    || ["success", "failed", "error"].includes(order.fulfillmentStatus);
}
function looksLikeInvoice(value: string) {
  return value.trim().toUpperCase().startsWith("LF");
}

export default function TrackPage() {
  const [query, setQuery] = useState("");
  const [activeReference, setActiveReference] = useState("");
  const [order, setOrder] = useState<TrackedOrder | null>(null);
  const [phoneOrders, setPhoneOrders] = useState<OrderSummary[]>([]);
  const [transactions, setTransactions] = useState<OrderSummary[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [copied, setCopied] = useState(false);
  const [copiedCode, setCopiedCode] = useState(false);

  const loadPublicFeed = useCallback(async () => {
    try {
      const response = await fetch("/api/orders/search", { cache: "no-store" });
      const result = await response.json() as { transactions?: OrderSummary[] };
      if (response.ok && Array.isArray(result.transactions)) setTransactions(result.transactions);
    } catch {
      // Feed publik tidak boleh mengganggu fungsi pencarian utama.
    }
  }, []);

  const loadOrder = useCallback(async (referenceId: string, quiet = false) => {
    const clean = referenceId.trim().toUpperCase();
    if (!clean) return;
    if (!quiet) setLoading(true);
    try {
      const response = await fetch("/api/orders/status", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ referenceId: clean }),
        cache: "no-store",
      });
      const result = await response.json() as { order?: TrackedOrder; error?: string };
      if (!response.ok || !result.order) throw new Error(result.error || "Invoice tidak ditemukan.");
      setOrder(result.order);
      setQuery(result.order.referenceId);
      setActiveReference(result.order.referenceId);
      setPhoneOrders([]);
      setError("");
    } catch (reason) {
      if (!quiet) {
        setOrder(null);
        setError(reason instanceof Error ? reason.message : "Status pesanan gagal dimuat.");
      }
    } finally {
      if (!quiet) setLoading(false);
    }
  }, []);

  const searchByPhone = useCallback(async (phone: string) => {
    setLoading(true);
    try {
      const response = await fetch("/api/orders/search", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ phone }),
        cache: "no-store",
      });
      const result = await response.json() as { orders?: OrderSummary[]; error?: string };
      if (!response.ok) throw new Error(result.error || "Pesanan tidak ditemukan.");
      const orders = result.orders ?? [];
      setOrder(null);
      setActiveReference("");
      setPhoneOrders(orders);
      setError(orders.length ? "" : "Belum ada pesanan untuk nomor WhatsApp tersebut.");
    } catch (reason) {
      setOrder(null);
      setPhoneOrders([]);
      setError(reason instanceof Error ? reason.message : "Pesanan tidak ditemukan.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadPublicFeed();
    const timer = window.setInterval(() => void loadPublicFeed(), 15_000);
    return () => window.clearInterval(timer);
  }, [loadPublicFeed]);

  useEffect(() => {
    const value = new URLSearchParams(window.location.search).get("invoice")?.trim().toUpperCase();
    if (!value) return;
    setQuery(value);
    setActiveReference(value);
    void loadOrder(value);
  }, [loadOrder]);

  useEffect(() => {
    if (!order || !activeReference || isFinal(order)) return;
    const timer = window.setInterval(() => void loadOrder(activeReference, true), 3000);
    return () => window.clearInterval(timer);
  }, [activeReference, loadOrder, order]);

  async function search(event: FormEvent) {
    event.preventDefault();
    setCopiedCode(false);
    const value = query.trim();
    if (!value) {
      setError("Masukkan nomor invoice atau nomor WhatsApp terlebih dahulu.");
      return;
    }
    if (looksLikeInvoice(value)) await loadOrder(value);
    else await searchByPhone(value);
  }
  function copyId() {
    if (!order) return;
    void navigator.clipboard?.writeText(order.referenceId);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1500);
  }
  function copyVoucherCode() {
    if (!order?.voucherCode) return;
    void navigator.clipboard?.writeText(order.voucherCode);
    setCopiedCode(true);
    window.setTimeout(() => setCopiedCode(false), 1500);
  }
  const status = order ? statusPresentation(order) : null;

  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-4xl px-4 py-10 sm:px-6 sm:py-16">
        <div className="text-center">
          <p className="eyebrow">Lacak pesanan</p>
          <h1 className="section-title">Cek status transaksi</h1>
          <p className="mx-auto mt-3 max-w-xl text-xs leading-5 text-white/42">
            Masukkan invoice LFAMILIA atau nomor WhatsApp yang digunakan saat checkout.
          </p>
        </div>

        <form onSubmit={search} className="panel mt-7 p-4 sm:p-5">
          <label className="field-label" htmlFor="order-query">Invoice / Nomor WhatsApp</label>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Input
              id="order-query"
              value={query}
              onChange={(event) => { setQuery(event.target.value.replace(/\s/g, "")); setError(""); }}
              placeholder="Contoh: LF260903ABC123 atau 081234567890"
              autoComplete="off"
              inputMode="text"
              className="h-11 rounded-lg border-white/10 bg-white/[0.035] font-mono text-sm text-white placeholder:font-sans placeholder:text-white/22"
            />
            <Button type="submit" disabled={loading} className="h-11 rounded-lg bg-[#b9ff35] px-6 font-black text-[#091006] hover:bg-[#d0ff75] disabled:opacity-60">
              {loading ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Search className="mr-2 size-4" />}
              {loading ? "Mencari" : "Cari"}
            </Button>
          </div>
          <p className="mt-2 text-[9px] text-white/28">
            Nomor WhatsApp boleh menggunakan format 08, 628, atau +628. Invoice lama dengan tanda “-” tetap dapat digunakan.
          </p>
        </form>

        {error && <div role="alert" className="panel mt-4 p-6 text-center">
          <Search className="mx-auto size-7 text-white/18" />
          <h2 className="mt-3 font-bold">Pesanan tidak ditemukan</h2>
          <p className="mt-1 text-xs leading-5 text-white/38">{error}</p>
        </div>}

        {phoneOrders.length > 0 && <section className="panel mt-4 overflow-hidden">
          <div className="border-b border-white/[0.08] px-4 py-3 sm:px-5">
            <h2 className="text-sm font-black">Pesanan dari nomor WhatsApp ini</h2>
            <p className="mt-0.5 text-[9px] text-white/35">Pilih transaksi yang ingin kamu cek.</p>
          </div>
          <div className="divide-y divide-white/[0.06]">
            {phoneOrders.map((item) => <div key={item.referenceId ?? item.maskedReferenceId} className="flex items-center justify-between gap-3 px-4 py-3 sm:px-5">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-[10px] font-bold text-[#cfff72]">{item.maskedReferenceId}</span>
                  <span className="rounded-md bg-white/[0.05] px-2 py-1 text-[8px] font-bold text-white/55">{publicStatusLabels[item.status] || item.status}</span>
                </div>
                <p className="mt-1 truncate text-xs font-bold text-white/75">{item.productName} • {item.packageLabel}</p>
                <p className="mt-1 text-[9px] text-white/35">{formatRupiah(item.total)} • {dateLabel(item.createdAt)}</p>
              </div>
              {item.referenceId && <button type="button" onClick={() => void loadOrder(item.referenceId!)} className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-white/10 bg-white/[0.035] px-3 py-2 text-[9px] font-bold text-white/70 hover:border-[#b9ff35]/30 hover:text-[#cfff72]">
                <Eye className="size-3" /> Lihat
              </button>}
            </div>)}
          </div>
        </section>}

        {order && status && <>
          <section className="panel mt-4 overflow-hidden">
            <div className="flex items-start justify-between gap-4 border-b border-white/[0.08] bg-[#b9ff35]/[0.06] p-4 sm:p-5">
              <div>
                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[9px] font-black uppercase tracking-wider ${status.tone}`}><status.Icon className="size-3" /> {status.label}</span>
                <h2 className="mt-3 text-lg font-black">{fulfillmentLabels[order.fulfillmentStatus] || status.label}</h2>
                <p className="mt-1 text-[10px] text-white/38">Dibuat {dateLabel(order.createdAt)}</p>
              </div>
              <PackageCheck className="size-8 text-[#b9ff35]" />
            </div>
            <div className="p-4 sm:p-5">
              <div className="flex items-center justify-between gap-3 rounded-lg bg-white/[0.035] p-3">
                <span className="break-all font-mono text-xs font-bold text-white/70">{order.referenceId}</span>
                <button type="button" onClick={copyId} className="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold text-[#cfff72]"><Copy className="size-3" />{copied ? "Tersalin" : "Salin Invoice"}</button>
              </div>
              <dl className="mt-4 grid gap-3 text-xs sm:grid-cols-2">
                {[["Produk", order.productName], ["Item", order.packageLabel], ...(order.destination ? [["Tujuan", order.destination]] : []), ["Total", formatRupiah(order.total)]].map(([label, value]) => <div key={label} className="border-b border-white/[0.07] pb-3"><dt className="text-white/32">{label}</dt><dd className="mt-1 font-bold">{value}</dd></div>)}
              </dl>

              {order.voucherCode && <div className="mt-4 rounded-lg border border-[#b9ff35]/25 bg-[#b9ff35]/[0.07] p-3">
                <div className="flex items-center justify-between gap-3"><div><span className="text-[9px] font-black uppercase tracking-[0.14em] text-[#cfff72]">Kode Voucher</span><p className="mt-1 text-[9px] text-white/38">Simpan kode ini dan jangan bagikan.</p></div><CheckCircle2 className="size-5 text-[#b9ff35]" /></div>
                <div className="mt-3 flex items-center justify-between gap-3 rounded-lg bg-black/20 p-3"><strong className="break-all font-mono text-sm text-[#d8ff8d]">{order.voucherCode}</strong><button type="button" onClick={copyVoucherCode} className="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold text-[#cfff72]"><Copy className="size-3.5" />{copiedCode ? "Tersalin" : "Salin kode"}</button></div>
              </div>}
            </div>
          </section>

          <section className="panel mt-4 overflow-hidden">
            <div className="flex items-center justify-between gap-3 border-b border-white/[0.08] px-4 py-3 sm:px-5">
              <div><h2 className="text-sm font-black">Log transaksi</h2><p className="mt-0.5 text-[9px] text-white/35">Riwayat status invoice ini dari sistem pembayaran dan fulfillment.</p></div>
              <span className="inline-flex items-center gap-1.5 rounded-full bg-white/[0.05] px-2 py-1 text-[8px] font-bold text-white/45"><Radio className={`size-3 ${!isFinal(order) ? "animate-pulse text-[#b9ff35]" : "text-white/35"}`} />{!isFinal(order) ? "Realtime" : "Final"}</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[540px] text-left text-[10px]">
                <thead className="bg-black/15 text-[8px] uppercase tracking-wider text-white/30"><tr><th className="px-4 py-2.5 font-bold sm:px-5">Waktu</th><th className="px-3 py-2.5 font-bold">Sumber</th><th className="px-3 py-2.5 font-bold">Status</th></tr></thead>
                <tbody className="divide-y divide-white/[0.06]">
                  {order.events.map((event, index) => <tr key={`${event.source}-${event.status}-${event.createdAt}-${index}`} className="text-white/55"><td className="whitespace-nowrap px-4 py-3 font-mono text-[9px] sm:px-5">{dateLabel(event.createdAt)}</td><td className="px-3 py-3 font-bold text-white/70">{sourceLabels[event.source] || event.source}</td><td className="px-3 py-3"><span className="inline-flex rounded-md bg-white/[0.05] px-2 py-1 font-semibold text-white/65">{eventLabels[event.status.toLowerCase()] || event.status.replaceAll("_", " ")}</span></td></tr>)}
                </tbody>
              </table>
            </div>
            {!isFinal(order) && <p className="border-t border-white/[0.06] px-4 py-2.5 text-[9px] text-white/30 sm:px-5">Log diperbarui otomatis setiap 3 detik. Tidak perlu refresh halaman.</p>}
          </section>
        </>}

        <section className="panel mt-6 overflow-hidden">
          <div className="flex items-center justify-between gap-3 border-b border-white/[0.08] px-4 py-3 sm:px-5">
            <div>
              <div className="flex items-center gap-2"><History className="size-4 text-[#b9ff35]" /><h2 className="text-sm font-black">Transaksi Terbaru</h2></div>
              <p className="mt-0.5 text-[9px] text-white/35">Aktivitas top up LFAMILIA terbaru. Invoice disamarkan untuk privasi pelanggan.</p>
            </div>
            <span className="inline-flex items-center gap-1.5 rounded-full bg-[#b9ff35]/[0.08] px-2 py-1 text-[8px] font-bold text-[#cfff72]"><Radio className="size-3 animate-pulse" /> Live</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-[10px]">
              <thead className="bg-black/15 text-[8px] uppercase tracking-wider text-white/30">
                <tr><th className="px-4 py-2.5 font-bold sm:px-5">Waktu</th><th className="px-3 py-2.5 font-bold">Invoice</th><th className="px-3 py-2.5 font-bold">Produk</th><th className="px-3 py-2.5 font-bold">Nominal</th><th className="px-3 py-2.5 font-bold">Status</th></tr>
              </thead>
              <tbody className="divide-y divide-white/[0.06]">
                {transactions.map((item, index) => <tr key={`${item.maskedReferenceId}-${item.createdAt}-${index}`} className="text-white/55">
                  <td className="whitespace-nowrap px-4 py-3 font-mono text-[9px] sm:px-5">{dateLabel(item.createdAt)}</td>
                  <td className="px-3 py-3 font-mono font-bold text-white/65">{item.maskedReferenceId}</td>
                  <td className="px-3 py-3"><span className="font-bold text-white/70">{item.productName}</span><span className="ml-1 text-white/35">• {item.packageLabel}</span></td>
                  <td className="whitespace-nowrap px-3 py-3 font-bold text-white/70">{formatRupiah(item.total)}</td>
                  <td className="px-3 py-3"><span className="inline-flex rounded-md bg-white/[0.05] px-2 py-1 font-semibold text-white/65">{publicStatusLabels[item.status] || item.status}</span></td>
                </tr>)}
                {!transactions.length && <tr><td colSpan={5} className="px-4 py-8 text-center text-[10px] text-white/30">Belum ada transaksi yang dapat ditampilkan.</td></tr>}
              </tbody>
            </table>
          </div>
          <p className="border-t border-white/[0.06] px-4 py-2.5 text-[9px] text-white/28 sm:px-5">Tidak menampilkan nama, email, nomor WhatsApp, tujuan akun, atau kode voucher pelanggan.</p>
        </section>
      </main>
    </StoreLayout>
  );
}
