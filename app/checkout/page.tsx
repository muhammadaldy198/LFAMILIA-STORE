"use client";
/* eslint-disable @next/next/no-img-element */

import { FormEvent, Suspense, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import {
  AlertCircle,
  ArrowLeft,
  BadgeCheck,
  CheckCircle2,
  Copy,
  CreditCard,
  ExternalLink,
  Info,
  Landmark,
  LoaderCircle,
  LockKeyhole,
  QrCode,
  ShieldCheck,
  WalletCards,
  X,
  Zap,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { StoreLayout } from "@/components/store-layout";
import { ProductArtwork } from "@/components/product-artwork";
import { ProductReviews } from "@/components/product-reviews";
import { useStoreProducts } from "@/hooks/use-store-products";
import { paymentChannels, paymentGroups, type PaymentChannel, type PaymentMethodCode } from "@/lib/payment-methods";
import { formatRupiah } from "@/lib/store-data";
import type { CustomerSession } from "@/lib/server/customer-auth";

const nicknameSupported = new Set(["mobile-legends", "free-fire", "genshin-impact", "valorant"]);

type NicknameState = {
  status: "idle" | "loading" | "success" | "error";
  key?: string;
  nickname?: string;
  country?: string | null;
  message?: string;
};

type PaymentResult = {
  referenceId: string;
  paymentNo: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  fee: number;
  total: number;
  expiredAt: string | null;
  fulfillmentType: "automatic" | "manual";
  providerCode: string | null;
  basePrice: number;
  sellingPrice: number;
  discountAmount: number;
  voucherCode: string | null;
  flashSaleId: number | null;
  paymentStatus?: "paid" | "pending";
  balanceAfter?: number;
};

type PromotionQuote = {
  basePrice: number;
  sellingPrice: number;
  discountAmount: number;
  finalPrice: number;
  voucherCode: string | null;
  flashSaleId: number | null;
  flashSaleEndsAt: string | null;
};

type CheckoutPaymentMethod = PaymentMethodCode | "wallet";
type DisplayPaymentChannel = PaymentChannel & { imageUrl?: string };
const checkoutGroups = [...paymentGroups, { code: "wallet" as const, name: "Saldo LFAMILIA", description: "Bayar langsung dari saldo akun" }];
const groupIcons = { va: Landmark, ewallet: WalletCards, qris: QrCode, wallet: WalletCards };

export default function CheckoutPage() {
  return (
    <Suspense fallback={<StoreLayout><main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-14 text-sm text-white/40">Memuat formulir pemesanan…</main></StoreLayout>}>
      <CheckoutRoute />
    </Suspense>
  );
}

function CheckoutRoute() {
  const searchParams = useSearchParams();
  return <CheckoutContent key={searchParams.get("product") ?? "default"} />;
}

function CheckoutContent() {
  const { products } = useStoreProducts();
  const searchParams = useSearchParams();
  const requestedProduct = searchParams.get("product");
  const product = useMemo(() => products.find((item) => item.slug === requestedProduct) ?? products[0], [products, requestedProduct]);
  const requestedPackage = searchParams.get("package");
  const [packageId, setPackageId] = useState(() => requestedPackage && product.packages.some((item) => item.id === requestedPackage) ? requestedPackage : "");
  const [destination, setDestination] = useState("");
  const [server, setServer] = useState("");
  const [buyerName, setBuyerName] = useState("");
  const [buyerEmail, setBuyerEmail] = useState("");
  const [contact, setContact] = useState("");
  const [customerNotes, setCustomerNotes] = useState("");
  const [paymentMethod, setPaymentMethod] = useState<CheckoutPaymentMethod>("qris");
  const [paymentChannel, setPaymentChannel] = useState("mpm");
  const [agreed, setAgreed] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [payment, setPayment] = useState<PaymentResult | null>(null);
  const [error, setError] = useState("");
  const [nickname, setNickname] = useState<NicknameState>({ status: "idle" });
  const [noticeOpen, setNoticeOpen] = useState(false);
  const [noticeIndex, setNoticeIndex] = useState(0);
  const [hideNotice, setHideNotice] = useState(false);
  const [voucherCode, setVoucherCode] = useState("");
  const [voucherMessage, setVoucherMessage] = useState("");
  const [quote, setQuote] = useState<PromotionQuote | null>(null);
  const [applyingVoucher, setApplyingVoucher] = useState(false);
  const [account, setAccount] = useState<CustomerSession | null>(null);
  const [availableChannels, setAvailableChannels] = useState<DisplayPaymentChannel[]>(paymentChannels);

  const selectedPackage = product.packages.find((item) => item.id === packageId);
  const subtotal = quote?.finalPrice ?? selectedPackage?.price ?? 0;
  const isManual = product.fulfillmentType === "manual";
  const isVoucherStock = selectedPackage?.providerCode === "voucher-stock";
  const providerReady = isManual || Boolean(selectedPackage?.providerCode && selectedPackage?.providerSku);
  const canCheckNickname = nicknameSupported.has(product.slug);
  const lookupNeedsServer = product.slug === "mobile-legends";
  const lookupKey = `${product.slug}:${destination.trim()}:${server.trim()}`;
  const visibleNickname: NicknameState = nickname.key === lookupKey ? nickname : { status: "idle" };
  const channels = paymentMethod === "wallet" ? [] : availableChannels.filter((item) => item.method === paymentMethod);
  const notices = (product.notices ?? []).filter((item) => item.isActive !== false);
  const noticeSignature = noticeVersion(notices);

  useEffect(() => {
    void fetch("/api/payment-methods", { cache: "no-store" }).then(async (response) => {
      if (!response.ok) return;
      const data = await response.json() as { channels?: DisplayPaymentChannel[] };
      if (data.channels?.length) setAvailableChannels(data.channels);
    }).catch(() => undefined);
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setNoticeIndex(0);
      setHideNotice(false);
      if (!notices.length) { setNoticeOpen(false); return; }
      const key = `lfamilia-notice:${product.slug}:${noticeSignature}`;
      const hiddenUntil = Number(window.localStorage.getItem(key) || 0);
      setNoticeOpen(hiddenUntil < Date.now());
    }, 0);
    return () => window.clearTimeout(timer);
  }, [noticeSignature, notices.length, product.slug]);

  useEffect(() => {
    void fetch("/api/account", { cache: "no-store" }).then(async (response) => {
      if (!response.ok) return;
      const data = await response.json() as { customer?: CustomerSession };
      if (!data.customer) return;
      setAccount(data.customer);
      setBuyerName((value) => value || data.customer!.name);
      setBuyerEmail((value) => value || data.customer!.email);
      setContact((value) => value || data.customer!.phone);
    }).catch(() => undefined);
  }, []);

  useEffect(() => {
    if (!selectedPackage) return;
    const controller = new AbortController();
    void requestQuote(product.slug, selectedPackage.id, "", controller.signal).then(setQuote).catch(() => undefined);
    return () => controller.abort();
  }, [product.slug, selectedPackage]);

  useEffect(() => {
    const cleanId = destination.trim();
    const cleanServer = server.trim();
    if (!canCheckNickname || cleanId.length < 4 || (lookupNeedsServer && cleanServer.length < 1)) return;
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setNickname({ status: "loading", key: lookupKey });
      try {
        const response = await fetch("/api/nickname", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ game: product.slug, userId: cleanId, server: cleanServer || undefined }),
          signal: controller.signal,
        });
        const data = await response.json() as { nickname?: string; country?: string | null; error?: string };
        if (!response.ok || !data.nickname) throw new Error(data.error ?? "ID atau Server tidak ditemukan.");
        setNickname({ status: "success", key: lookupKey, nickname: data.nickname, country: data.country });
      } catch (reason) {
        if (controller.signal.aborted) return;
        setNickname({ status: "error", key: lookupKey, message: reason instanceof Error ? reason.message : "Nickname gagal diperiksa." });
      }
    }, 700);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [canCheckNickname, destination, lookupKey, lookupNeedsServer, product.slug, server]);

  function chooseMethod(method: CheckoutPaymentMethod) {
    setPaymentMethod(method);
    setPaymentChannel(method === "wallet" ? "lfamilia-balance" : availableChannels.find((item) => item.method === method)?.channel ?? "");
    setPayment(null);
  }

  function choosePackage(id: string) {
    setPackageId(id);
    setPayment(null);
    setVoucherCode("");
    setVoucherMessage("");
    setQuote(null);
  }

  function closeNotice() {
    if (hideNotice) window.localStorage.setItem(`lfamilia-notice:${product.slug}:${noticeSignature}`, String(Date.now() + 7 * 24 * 60 * 60 * 1000));
    setNoticeOpen(false);
  }

  async function applyVoucher() {
    if (!selectedPackage) { setVoucherMessage("Pilih nominal terlebih dahulu."); return; }
    setApplyingVoucher(true);
    setVoucherMessage("");
    try {
      const nextQuote = await requestQuote(product.slug, selectedPackage.id, voucherCode);
      setQuote(nextQuote);
      setVoucherCode(nextQuote.voucherCode ?? "");
      setVoucherMessage(nextQuote.voucherCode ? `Voucher ${nextQuote.voucherCode} berhasil digunakan.` : "Harga promo sudah diperbarui.");
    } catch (reason) {
      setQuote(null);
      setVoucherMessage(reason instanceof Error ? reason.message : "Voucher tidak dapat digunakan.");
    } finally {
      setApplyingVoucher(false);
    }
  }

  async function submitOrder(event: FormEvent) {
    event.preventDefault();
    if (!destination.trim() || (product.needsServer && !server.trim()) || !buyerName.trim() || !buyerEmail.trim() || !contact.trim() || !packageId || !agreed) {
      setError("Lengkapi data akun, nominal, identitas pembeli, pembayaran, dan persetujuan.");
      return;
    }
    if (canCheckNickname && visibleNickname.status !== "success") {
      setError("Tunggu sampai nickname akun berhasil diverifikasi.");
      return;
    }
    if (!providerReady) {
      setError("Produk otomatis ini belum memiliki provider dan SKU. Atur dahulu dari panel admin.");
      return;
    }
    setSubmitting(true);
    setPayment(null);
    setError("");
    try {
      const response = await fetch(paymentMethod === "wallet" ? "/api/payments/wallet/create" : "/api/payments/ipaymu/create", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          productSlug: product.slug,
          packageSku: packageId,
          destination: destination.trim(),
          server: server.trim() || undefined,
          nickname: visibleNickname.nickname,
          buyerName: buyerName.trim(),
          buyerEmail: buyerEmail.trim(),
          buyerPhone: contact.replace(/[\s()-]/g, ""),
          customerNotes: customerNotes.trim() || undefined,
          paymentMethod,
          paymentChannel,
          voucherCode: voucherCode.trim() || undefined,
        }),
      });
      const data = await response.json() as PaymentResult & { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Pembayaran gagal dibuat.");
      setPayment(data);
      if (data.balanceAfter != null) setAccount((current) => current ? { ...current, balance: data.balanceAfter! } : current);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pembayaran gagal dibuat.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <StoreLayout>
      <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
        <Link href="/catalog" className="inline-flex items-center gap-2 text-xs font-semibold text-white/42 hover:text-white"><ArrowLeft className="size-4" /> Kembali ke katalog</Link>
        <section className="relative mt-5 min-h-52 overflow-hidden rounded-[26px] border border-white/10 bg-[#10131b] sm:min-h-64">
          {(product.bannerUrl || product.imageUrl) && <img src={product.bannerUrl || product.imageUrl} alt={`Banner ${product.name}`} className="absolute inset-0 size-full object-cover" />}
          <div className={`absolute inset-0 bg-gradient-to-br ${product.accent} opacity-75`} />
          {(product.bannerUrl || product.imageUrl) && <div className="absolute inset-0 bg-gradient-to-r from-black/90 via-black/48 to-black/10" />}
          <div className="relative z-10 flex min-h-52 items-end p-6 sm:min-h-64 sm:p-8"><div><span className="rounded-full border border-white/15 bg-black/30 px-3 py-1 text-[9px] font-bold uppercase tracking-[0.16em] text-white/68">{product.publisher}</span><h1 className="mt-4 text-3xl font-black tracking-[-0.04em] sm:text-5xl">{product.name}</h1><p className="mt-3 text-xs text-white/52">Pilih nominal, isi data tujuan, lalu selesaikan pembayaran dengan aman.</p></div></div>
        </section>
        <section className="relative z-10 mx-2 -mt-7 overflow-hidden rounded-2xl border border-white/[0.10] bg-[#10131b]/95 p-4 shadow-2xl backdrop-blur sm:mx-6 sm:-mt-9 sm:p-5">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-4"><span className="block size-16 shrink-0 overflow-hidden rounded-2xl border border-white/10 shadow-lg sm:size-20"><ProductArtwork product={product} compact /></span><div><p className="text-[9px] font-black uppercase tracking-[0.18em] text-[#cfff72]">Top up game</p><h2 className="mt-1 text-lg font-black sm:text-xl">{product.name}</h2><p className="mt-1 text-xs text-white/45">{product.publisher}</p></div></div>
            <div className="grid grid-cols-3 gap-3 text-center text-[9px] text-white/48 sm:w-[360px]"><span><Zap className="mx-auto mb-1 size-4 text-[#cfff72]" />Proses cepat</span><span><ShieldCheck className="mx-auto mb-1 size-4 text-[#cfff72]" />Pembayaran aman</span><span><BadgeCheck className="mx-auto mb-1 size-4 text-[#cfff72]" />Status realtime</span></div>
          </div>
        </section>
        <div className="mt-6 grid items-start gap-6 lg:grid-cols-[1fr_380px]">
          <form id="checkout-form" onSubmit={submitOrder} className="space-y-5">
            <section className="panel overflow-hidden">
              <div className="flex items-center justify-between gap-4 border-b border-white/[0.08] bg-white/[0.02] p-5 sm:p-6">
                <StepTitle number="1" title="Masukkan Data Akun" description="Nickname diperiksa otomatis jika game mendukung." />
                <div className="hidden items-center gap-3 sm:flex"><span className="block size-10 overflow-hidden rounded-xl"><ProductArtwork product={product} compact /></span><div className="max-w-36"><strong className="block truncate text-xs">{product.name}</strong><Link href="/catalog" className="mt-1 block text-[9px] font-semibold text-[#cfff72]">Ganti produk</Link></div></div>
              </div>
              <div className="p-5 sm:p-6">
                <div className="mb-5 flex items-center gap-3 sm:hidden"><span className="block size-10 overflow-hidden rounded-xl"><ProductArtwork product={product} compact /></span><div><strong className="block text-xs">{product.name}</strong><Link href="/catalog" className="mt-1 block text-[9px] font-semibold text-[#cfff72]">Ganti produk</Link></div></div>
                <div className={product.needsServer ? "grid gap-4 sm:grid-cols-2" : "grid gap-4"}>
                  <Field label={product.inputLabel}><Input value={destination} onChange={(event) => { setDestination(event.target.value); setPayment(null); setError(""); }} placeholder={product.inputPlaceholder} autoComplete="off" className="checkout-input" /></Field>
                  {product.needsServer && <Field label="Server / Zone ID"><Input inputMode="numeric" value={server} onChange={(event) => { setServer(event.target.value.replace(/\D/g, "")); setPayment(null); setError(""); }} placeholder="Contoh: 1234" autoComplete="off" className="checkout-input" /></Field>}
                </div>
                {canCheckNickname ? <NicknameResult state={visibleNickname} /> : <p className="mt-4 flex items-start gap-2 rounded-xl border border-white/[0.07] bg-white/[0.025] p-3 text-[10px] leading-5 text-white/32"><Info className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" /> Verifikasi nickname otomatis belum tersedia. Periksa kembali data sebelum membayar.</p>}
                {product.manualInstructions && <div className="mt-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3 text-[10px] leading-5 text-amber-100/65"><strong className="block text-amber-200">Instruksi produk manual</strong>{product.manualInstructions}</div>}
              </div>
            </section>

            <section className="panel p-5 sm:p-6">
              <StepTitle number="2" title="Pilih nominal" description={isManual ? "Pesanan diproses admin setelah pembayaran." : isVoucherStock ? "Satu kode stok dikirim otomatis setelah pembayaran." : "Pesanan diteruskan otomatis ke provider."} />
              <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">{product.packages.map((item) => {
                const ready = isManual || Boolean(item.providerCode && item.providerSku);
                return <button key={item.id} type="button" onClick={() => choosePackage(item.id)} className={`relative min-h-24 rounded-2xl border p-3 text-left transition ${packageId === item.id ? "border-[#b9ff35] bg-[#b9ff35]/10 shadow-[inset_0_0_0_1px_rgba(185,255,53,.2)]" : "border-white/[0.09] bg-white/[0.025] hover:border-white/20"}`}>
                  {item.note && <span className="absolute right-2 top-2 rounded-full bg-[#b9ff35] px-2 py-0.5 text-[8px] font-black uppercase text-[#091006]">{item.note}</span>}
                  <strong className="block pr-8 text-xs leading-5">{item.label}</strong><span className="mt-2 block text-[11px] font-bold text-[#cfff72]">{formatRupiah(item.price)}</span>
                  {!ready && <span className="mt-2 block text-[8px] font-semibold text-amber-300/70">SKU belum diatur</span>}
                </button>;
              })}</div>
              <div className="mt-5 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4"><label className="field-label" htmlFor="voucher-code">Kode voucher diskon</label><div className="flex gap-2"><Input id="voucher-code" value={voucherCode} onChange={(event) => { setVoucherCode(event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, "")); setVoucherMessage(""); }} placeholder="Masukkan kode promo" className="checkout-input font-mono uppercase" /><Button type="button" onClick={() => void applyVoucher()} disabled={applyingVoucher || !packageId} variant="outline" className="h-12 shrink-0 rounded-xl border-white/10 bg-white/[0.04] px-4 text-white hover:bg-white/[0.08] hover:text-white">{applyingVoucher ? <LoaderCircle className="size-4 animate-spin" /> : "Gunakan"}</Button></div>{voucherMessage && <p className={`mt-2 text-[10px] ${quote?.voucherCode ? "text-[#cfff72]" : "text-amber-200"}`}>{voucherMessage}</p>}</div>
            </section>

            <section className="panel p-5 sm:p-6">
              <StepTitle number="3" title="Pilih metode pembayaran" description="Biaya layanan dan total akhir ditampilkan dengan jelas." />
              <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{checkoutGroups.map((group) => {
                const Icon = groupIcons[group.code];
                const selected = paymentMethod === group.code;
                const disabled = group.code === "wallet" && !account;
                return <button key={group.code} type="button" disabled={disabled} onClick={() => chooseMethod(group.code)} className={`rounded-2xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-45 ${selected ? "border-[#b9ff35] bg-[#b9ff35]/[0.08]" : "border-white/[0.09] bg-white/[0.025] hover:border-white/20"}`}><div className="flex items-center justify-between"><span className={`grid size-9 place-items-center rounded-xl ${selected ? "bg-[#b9ff35] text-[#091006]" : "bg-white/[0.06] text-white/55"}`}><Icon className="size-4" /></span>{selected && <CheckCircle2 className="size-4 text-[#b9ff35]" />}</div><strong className="mt-3 block text-xs">{group.name}</strong><p className="mt-1 text-[9px] leading-4 text-white/32">{group.code === "wallet" && account ? `Saldo ${formatRupiah(account.balance)}` : group.code === "wallet" ? "Masuk akun untuk memakai saldo" : group.description}</p></button>;
              })}</div>
              {!account && <p className="mt-3 text-[10px] text-white/35">Ingin membayar memakai saldo? <Link href="/login" className="font-bold text-[#cfff72]">Masuk atau daftar akun</Link>.</p>}
              <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">{channels.map((channel) => <button key={channel.channel} type="button" onClick={() => { setPaymentChannel(channel.channel); setPayment(null); }} className={`flex min-h-14 items-center gap-2 rounded-xl border px-3 py-3 text-left text-[10px] font-bold transition ${paymentChannel === channel.channel ? "border-[#b9ff35]/60 bg-[#b9ff35]/[0.08] text-[#d8ff8d]" : "border-white/[0.08] bg-white/[0.02] text-white/45 hover:text-white"}`}>{channel.imageUrl ? <img src={channel.imageUrl} alt="" className="size-8 rounded-lg object-contain" /> : <span className="grid size-8 place-items-center rounded-lg bg-white/[0.07] text-[8px] font-black text-white/80">{channel.name.slice(0, 3)}</span>}<span>{channel.name}</span></button>)}</div>
            </section>

            <section className="panel p-5 sm:p-6">
              <StepTitle number="4" title="Data pembeli" description="Digunakan untuk invoice dan status transaksi." />
              <div className="mt-5 grid gap-4 sm:grid-cols-2">
                <Field label="Nama lengkap"><Input value={buyerName} onChange={(event) => setBuyerName(event.target.value)} placeholder="Nama pembeli" className="checkout-input" /></Field>
                <Field label="Nomor WhatsApp"><Input inputMode="tel" value={contact} onChange={(event) => setContact(event.target.value)} placeholder="081234567890" className="checkout-input" /></Field>
                <Field label="Email"><Input type="email" value={buyerEmail} onChange={(event) => setBuyerEmail(event.target.value)} placeholder="nama@email.com" className="checkout-input" /></Field>
                <Field label="Catatan (opsional)"><Input value={customerNotes} onChange={(event) => setCustomerNotes(event.target.value)} placeholder={isManual ? "Nama item atau instruksi aman" : "Catatan pesanan"} className="checkout-input" /></Field>
              </div>
              <p className="mt-3 flex items-start gap-1.5 text-[10px] leading-4 text-white/30"><ShieldCheck className="mt-0.5 size-3 shrink-0" /> Jangan pernah memasukkan password, PIN, atau kode OTP ke catatan.</p>
            </section>

            <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4 text-xs leading-5 text-white/45"><input type="checkbox" checked={agreed} onChange={(event) => setAgreed(event.target.checked)} className="mt-1 size-4 accent-[#b9ff35]" /><span>Saya sudah memeriksa data tujuan dan menyetujui <Link href="/terms" className="font-semibold text-[#cfff72] hover:underline">syarat transaksi</Link>.</span></label>
            {error && <div className="flex items-start gap-2 rounded-xl border border-red-400/20 bg-red-400/[0.07] p-3 text-xs leading-5 text-red-200"><AlertCircle className="mt-0.5 size-4 shrink-0" />{error}</div>}
            <Button disabled={submitting} type="submit" className="h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75] lg:hidden">{submitting ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <LockKeyhole className="mr-2 size-4" />}Buat pembayaran</Button>
          </form>

          <aside className="panel p-5 lg:sticky lg:top-28">
            <div className="flex items-center justify-between"><h2 className="font-bold">Ringkasan pesanan</h2><span className={`rounded-full px-2.5 py-1 text-[9px] font-black uppercase ${isManual ? "bg-amber-400/10 text-amber-300" : "bg-[#b9ff35]/10 text-[#d8ff8d]"}`}>{isManual ? "Manual" : "Otomatis"}</span></div>
            <div className="my-5 h-px bg-white/[0.08]" />
            <dl className="space-y-3 text-xs">
              <SummaryRow label="Produk" value={product.name} />
              {visibleNickname.nickname && <SummaryRow label="Nickname" value={visibleNickname.nickname} highlight />}
              <SummaryRow label="Nominal" value={selectedPackage?.label ?? "Belum dipilih"} />
              <SummaryRow label="Harga" value={formatRupiah(subtotal)} />
              {quote && quote.sellingPrice < quote.basePrice && <SummaryRow label="Harga promo" value={`-${formatRupiah(quote.basePrice - quote.sellingPrice)}`} highlight />}
              {quote && quote.discountAmount > 0 && <SummaryRow label={`Voucher ${quote.voucherCode ?? ""}`} value={`-${formatRupiah(quote.discountAmount)}`} highlight />}
              <SummaryRow label="Biaya layanan" value={paymentMethod === "wallet" ? formatRupiah(0) : payment ? formatRupiah(payment.fee) : "Dihitung otomatis"} />
              <SummaryRow label="Proses" value={isManual ? "Antrean admin" : isVoucherStock ? "Kirim kode otomatis" : (selectedPackage?.providerCode || "Provider belum diatur")} />
            </dl>
            <div className="my-5 h-px bg-white/[0.08]" />
            <div className="flex items-end justify-between"><span className="text-sm font-bold">Total</span><strong className="text-xl font-black text-[#b9ff35]">{formatRupiah(payment?.total ?? subtotal)}</strong></div>
            <p className="mt-3 rounded-xl bg-white/[0.035] p-3 text-[9px] leading-4 text-white/30">Biaya layanan, jika ada, dihitung oleh channel yang dipilih dan ditampilkan sebelum kamu melanjutkan pembayaran.</p>
            <Button form="checkout-form" disabled={submitting} type="submit" className="mt-5 hidden h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75] lg:flex">{submitting ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <LockKeyhole className="mr-2 size-4" />}Buat pembayaran</Button>
            <div className="mt-4 flex items-center justify-center gap-4 text-[9px] text-white/30"><span className="inline-flex items-center gap-1"><ShieldCheck className="size-3" /> Data aman</span><span className="inline-flex items-center gap-1"><CreditCard className="size-3" /> Pembayaran terlindungi</span></div>
            {payment && <PaymentBox payment={payment} />}
          </aside>
        </div>
        <ProductReviews productSlug={product.slug} />
      </main>
      <Dialog open={noticeOpen} onOpenChange={(open) => { if (!open) closeNotice(); else setNoticeOpen(true); }}>
        <DialogContent className="max-w-lg overflow-hidden border-white/10 bg-[#080b14] p-0 text-white" showCloseButton={false}>
          {notices.length > 0 && <><div className="flex items-center justify-between border-b border-white/[0.08] px-6 py-4"><span className="font-mono text-xs text-white/40">{noticeIndex + 1}/{notices.length}</span><button type="button" onClick={closeNotice} className="rounded-lg p-1.5 text-white/55 hover:bg-white/[0.06] hover:text-white" aria-label="Tutup informasi"><X className="size-5" /></button></div><div className="px-6 py-6"><DialogHeader><DialogTitle className="text-left text-lg font-black uppercase leading-7">{formatNotice(notices[noticeIndex].title, product)}</DialogTitle><DialogDescription className="whitespace-pre-line text-left text-sm leading-7 text-white/64">{formatNotice(notices[noticeIndex].body, product)}</DialogDescription></DialogHeader>{notices.length > 1 && <div className="mt-6 flex items-center justify-between gap-3"><Button type="button" variant="outline" disabled={noticeIndex === 0} onClick={() => setNoticeIndex((value) => Math.max(0, value - 1))} className="border-white/10 bg-white/[0.03] text-white">Sebelumnya</Button><Button type="button" disabled={noticeIndex === notices.length - 1} onClick={() => setNoticeIndex((value) => Math.min(notices.length - 1, value + 1))} className="bg-[#b9ff35] text-[#091006]">Berikutnya</Button></div>}</div><label className="flex cursor-pointer items-center gap-3 border-t border-white/[0.08] px-6 py-5 text-xs text-white/45"><input type="checkbox" checked={hideNotice} onChange={(event) => setHideNotice(event.target.checked)} className="size-4 accent-[#b9ff35]" />Jangan tampilkan lagi dalam 7 hari</label></>}
        </DialogContent>
      </Dialog>
    </StoreLayout>
  );
}

function formatNotice(value: string, product: { manualOpenTime?: string; manualCloseTime?: string; manualTimezone?: string }) {
  const zone = product.manualTimezone === "Asia/Makassar" ? "WITA" : product.manualTimezone === "Asia/Jayapura" ? "WIT" : "WIB";
  return value
    .replaceAll("{{jam_buka}}", product.manualOpenTime ?? "-")
    .replaceAll("{{jam_tutup}}", product.manualCloseTime ?? "-")
    .replaceAll("{{zona_waktu}}", zone);
}

function noticeVersion(notices: Array<{ title: string; body: string }>) {
  let hash = 2166136261;
  for (const character of notices.map((item) => `${item.title}\n${item.body}`).join("\n---\n")) {
    hash ^= character.charCodeAt(0);
    hash = Math.imul(hash, 16777619);
  }
  return (hash >>> 0).toString(36);
}

async function requestQuote(productSlug: string, packageSku: string, voucherCode = "", signal?: AbortSignal) {
  const response = await fetch("/api/promotions/quote", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ productSlug, packageSku, voucherCode: voucherCode || undefined }), signal });
  const data = await response.json() as PromotionQuote & { error?: string };
  if (!response.ok) throw new Error(data.error ?? "Harga promo gagal dihitung.");
  return data;
}

function StepTitle({ title, description }: { number: string; title: string; description: string }) {
  return <div><h2 className="font-bold">{title}</h2><p className="mt-1 text-[11px] text-white/35">{description}</p></div>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label><span className="field-label">{label}</span>{children}</label>;
}

function SummaryRow({ label, value, highlight = false }: { label: string; value: string; highlight?: boolean }) {
  return <div className="flex justify-between gap-4"><dt className="text-white/38">{label}</dt><dd className={`max-w-52 truncate text-right font-semibold ${highlight ? "text-[#cfff72]" : ""}`}>{value}</dd></div>;
}

function PaymentBox({ payment }: { payment: PaymentResult }) {
  const fulfillmentMessage = payment.fulfillmentType === "manual"
    ? "Setelah lunas, pesanan masuk antrean admin."
    : payment.providerCode === "voucher-stock"
      ? "Setelah lunas, satu kode stok dikirim otomatis ke email/WhatsApp pembeli."
      : "Setelah lunas, pesanan diteruskan otomatis ke provider.";
  return <div className="mt-5 rounded-2xl border border-[#b9ff35]/30 bg-[#b9ff35]/[0.08] p-4"><BadgeCheck className="size-6 text-[#b9ff35]" /><h3 className="mt-3 text-sm font-black">{payment.paymentStatus === "paid" ? "Pembayaran berhasil" : "Pembayaran dibuat"}</h3><p className="mt-1 break-all text-[10px] text-white/45">{payment.referenceId}</p>{payment.paymentNo && <div className="mt-3 rounded-xl bg-black/20 p-3"><span className="text-[9px] uppercase tracking-wider text-white/35">{payment.paymentName || "Nomor pembayaran"}</span><div className="mt-1 flex items-center justify-between gap-2"><strong className="break-all text-sm text-[#d8ff8d]">{payment.paymentNo}</strong><button type="button" onClick={() => void navigator.clipboard.writeText(payment.paymentNo!)} className="shrink-0 text-white/45 hover:text-white" aria-label="Salin nomor pembayaran"><Copy className="size-4" /></button></div></div>}{payment.balanceAfter != null && <p className="mt-3 rounded-xl bg-black/20 p-3 text-[10px] text-white/55">Sisa saldo: <strong className="text-[#d8ff8d]">{formatRupiah(payment.balanceAfter)}</strong></p>}{payment.expiredAt && <p className="mt-3 text-[9px] text-white/35">Berlaku sampai {payment.expiredAt}</p>}{payment.paymentUrl && <Button asChild className="mt-4 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]"><a href={payment.paymentUrl} target="_blank" rel="noreferrer">Lanjut bayar <ExternalLink className="ml-2 size-4" /></a></Button>}<p className="mt-3 flex items-start gap-2 text-[9px] leading-4 text-white/38">{payment.fulfillmentType === "automatic" ? <Zap className="mt-0.5 size-3 shrink-0 text-[#b9ff35]" /> : <Info className="mt-0.5 size-3 shrink-0 text-amber-300" />}{fulfillmentMessage}</p></div>;
}

function NicknameResult({ state }: { state: NicknameState }) {
  if (state.status === "loading") return <div className="mt-4 flex items-center gap-3 rounded-xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.05] p-3 text-xs text-white/48"><LoaderCircle className="size-4 animate-spin text-[#b9ff35]" />Memeriksa ID dan Server…</div>;
  if (state.status === "success") return <div className="mt-4 flex items-start gap-3 rounded-xl border border-emerald-400/30 bg-emerald-400/[0.08] p-4"><BadgeCheck className="mt-0.5 size-5 shrink-0 text-emerald-400" /><div><p className="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-300/70">Akun ditemukan</p><strong className="mt-1 block break-words text-sm text-emerald-200">{state.nickname}</strong>{state.country && <p className="mt-1 text-[10px] text-emerald-100/55">dari {state.country}{state.country.toLowerCase() === "indonesia" ? " 🇮🇩" : ""}</p>}</div></div>;
  if (state.status === "error") return <div className="mt-4 flex items-start gap-3 rounded-xl border border-red-400/25 bg-red-400/[0.07] p-4"><AlertCircle className="mt-0.5 size-5 shrink-0 text-red-300" /><div><p className="text-xs font-bold text-red-200">Akun belum terverifikasi</p><p className="mt-1 text-[10px] leading-5 text-red-100/55">{state.message}</p></div></div>;
  return <div className="mt-4 flex items-start gap-3 rounded-xl border border-white/[0.07] bg-white/[0.025] p-3 text-[10px] leading-5 text-white/32"><Info className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />Nickname akan tampil otomatis setelah User ID dan Server yang diperlukan terisi.</div>;
}
