"use client";
/* eslint-disable @next/next/no-img-element */

import { FormEvent, Suspense, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import {
  AlertCircle,
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { StoreLayout } from "@/components/store-layout";
import { ProductArtwork } from "@/components/product-artwork";
import { ProductReviews } from "@/components/product-reviews";
import { useStoreProducts } from "@/hooks/use-store-products";
import {
  paymentChannels,
  paymentGroups,
  type PaymentChannel,
  type PaymentMethodCode,
} from "@/lib/payment-methods";
import { formatRupiah } from "@/lib/store-data";
import type { CustomerSession } from "@/lib/server/customer-auth";
import type { WalletSettings } from "@/lib/server/wallet";

const nicknameSupported = new Set([
  "mobile-legends",
  "free-fire",
  "genshin-impact",
  "valorant",
]);

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
  paymentMethod?: string;
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

type CheckoutPaymentMethod =
  PaymentMethodCode | "wallet" | "manual_qris" | "manual_bank";
type DisplayPaymentChannel = PaymentChannel & { imageUrl?: string };
const manualPaymentGroups = [
  {
    code: "manual_qris" as const,
    name: "QRIS",
    description: "Scan QRIS toko, lalu pembayaran diperiksa Pemilik",
  },
  {
    code: "manual_bank" as const,
    name: "Transfer bank",
    description: "Transfer ke rekening toko, lalu pembayaran diperiksa Pemilik",
  },
];
const groupIcons = {
  va: Landmark,
  ewallet: WalletCards,
  qris: QrCode,
  wallet: WalletCards,
  manual_qris: QrCode,
  manual_bank: Landmark,
};

export default function CheckoutPage() {
  return (
    <Suspense
      fallback={
        <StoreLayout>
          <main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-14 text-sm text-white/40">
            Memuat formulir pemesanan…
          </main>
        </StoreLayout>
      }
    >
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
  const product = useMemo(
    () =>
      products.find((item) => item.slug === requestedProduct) ?? products[0],
    [products, requestedProduct],
  );
  const requestedPackage = searchParams.get("package");
  const [packageId, setPackageId] = useState(() =>
    requestedPackage &&
    product.packages.some((item) => item.id === requestedPackage)
      ? requestedPackage
      : "",
  );
  const [destination, setDestination] = useState("");
  const [server, setServer] = useState("");
  const [buyerName, setBuyerName] = useState("");
  const [buyerEmail, setBuyerEmail] = useState("");
  const [contact, setContact] = useState("");
  const [customerNotes, setCustomerNotes] = useState("");
  const [activeTab, setActiveTab] = useState<"transaction" | "details">("transaction");
  const [confirmationOpen, setConfirmationOpen] = useState(false);
  const [summaryOpen, setSummaryOpen] = useState(true);
  const [paymentMethod, setPaymentMethod] =
    useState<CheckoutPaymentMethod>("qris");
  const [paymentChannel, setPaymentChannel] = useState("qris");
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
  const [availableChannels, setAvailableChannels] =
    useState<DisplayPaymentChannel[]>(paymentChannels);
  const [walletSettings, setWalletSettings] = useState<WalletSettings | null>(
    null,
  );

  const selectedPackage = product.packages.find(
    (item) => item.id === packageId,
  );
  const subtotal = quote?.finalPrice ?? selectedPackage?.price ?? 0;
  const isManual = product.fulfillmentType === "manual";
  const isVoucherStock = selectedPackage?.providerCode === "voucher-stock";
  const providerReady =
    isManual ||
    Boolean(selectedPackage?.providerCode && selectedPackage?.providerSku);
  const canCheckNickname = nicknameSupported.has(product.slug);
  const lookupNeedsServer = product.slug === "mobile-legends";
  const lookupKey = `${product.slug}:${destination.trim()}:${server.trim()}`;
  const visibleNickname: NicknameState =
    nickname.key === lookupKey ? nickname : { status: "idle" };
  const isGatewayMethod =
    paymentMethod === "va" ||
    paymentMethod === "ewallet" ||
    paymentMethod === "qris";
  const checkoutGroups = [
    { code: "wallet" as const, name: "Koin LFAMILIA", description: "Bayar langsung dari saldo akun" },
    ...paymentGroups.filter((item) => item.code === "qris" || item.code === "ewallet" || item.code === "va").sort((left, right) => ["qris", "ewallet", "va"].indexOf(left.code) - ["qris", "ewallet", "va"].indexOf(right.code)),
  ];
  const channels = isGatewayMethod
    ? availableChannels.filter((item) => item.method === paymentMethod)
    : [];
  const notices = (product.notices ?? []).filter(
    (item) => item.isActive !== false,
  );
  const noticeSignature = noticeVersion(notices);

  useEffect(() => {
    void fetch("/api/payment-methods", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return;
        const data = (await response.json()) as {
          channels?: DisplayPaymentChannel[];
        };
        if (data.channels?.length) setAvailableChannels(data.channels);
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    void fetch("/api/wallet", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return;
        const data = (await response.json()) as { settings?: WalletSettings };
        if (data.settings) setWalletSettings(data.settings);
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setNoticeIndex(0);
      setHideNotice(false);
      if (!notices.length) {
        setNoticeOpen(false);
        return;
      }
      const key = `lfamilia-notice:${product.slug}:${noticeSignature}`;
      const hiddenUntil = Number(window.localStorage.getItem(key) || 0);
      setNoticeOpen(hiddenUntil < Date.now());
    }, 0);
    return () => window.clearTimeout(timer);
  }, [noticeSignature, notices.length, product.slug]);

  useEffect(() => {
    void fetch("/api/account", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return;
        const data = (await response.json()) as { customer?: CustomerSession };
        if (!data.customer) return;
        setAccount(data.customer);
        setBuyerName((value) => value || data.customer!.name);
        setBuyerEmail((value) => value || data.customer!.email);
        setContact((value) => value || data.customer!.phone);
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    if (!selectedPackage) return;
    const controller = new AbortController();
    void requestQuote(product.slug, selectedPackage.id, "", controller.signal)
      .then(setQuote)
      .catch(() => undefined);
    return () => controller.abort();
  }, [product.slug, selectedPackage]);

  useEffect(() => {
    const cleanId = destination.trim();
    const cleanServer = server.trim();
    if (
      !canCheckNickname ||
      cleanId.length < 4 ||
      (lookupNeedsServer && cleanServer.length < 1)
    )
      return;
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setNickname({ status: "loading", key: lookupKey });
      try {
        const response = await fetch("/api/nickname", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({
            game: product.slug,
            userId: cleanId,
            server: cleanServer || undefined,
          }),
          signal: controller.signal,
        });
        const data = (await response.json()) as {
          nickname?: string;
          country?: string | null;
          error?: string;
        };
        if (!response.ok || !data.nickname)
          throw new Error(data.error ?? "ID atau Server tidak ditemukan.");
        setNickname({
          status: "success",
          key: lookupKey,
          nickname: data.nickname,
          country: data.country,
        });
      } catch (reason) {
        if (controller.signal.aborted) return;
        setNickname({
          status: "error",
          key: lookupKey,
          message:
            reason instanceof Error
              ? reason.message
              : "Nickname gagal diperiksa.",
        });
      }
    }, 700);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [
    canCheckNickname,
    destination,
    lookupKey,
    lookupNeedsServer,
    product.slug,
    server,
  ]);

  useEffect(() => {
    if (!walletSettings) return;
    const enabled = checkoutGroups.map((group) => group.code);
    if (enabled.includes(paymentMethod)) return;
    if (walletSettings.midtransCheckoutEnabled) chooseMethod("qris");
    else chooseMethod("wallet");
  }, [walletSettings, paymentMethod]);

  function chooseMethod(method: CheckoutPaymentMethod) {
    setPaymentMethod(method);
    setPaymentChannel(
      method === "wallet"
        ? "lfamilia-balance"
        : method === "qris"
          ? "qris"
          : method === "manual_qris" || method === "manual_bank"
            ? method
            : (availableChannels.find((item) => item.method === method)
                ?.channel ?? ""),
    );
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
    if (hideNotice)
      window.localStorage.setItem(
        `lfamilia-notice:${product.slug}:${noticeSignature}`,
        String(Date.now() + 7 * 24 * 60 * 60 * 1000),
      );
    setNoticeOpen(false);
  }

  async function applyVoucher() {
    if (!selectedPackage) {
      setVoucherMessage("Pilih nominal terlebih dahulu.");
      return;
    }
    setApplyingVoucher(true);
    setVoucherMessage("");
    try {
      const nextQuote = await requestQuote(
        product.slug,
        selectedPackage.id,
        voucherCode,
      );
      setQuote(nextQuote);
      setVoucherCode(nextQuote.voucherCode ?? "");
      setVoucherMessage(
        nextQuote.voucherCode
          ? `Voucher ${nextQuote.voucherCode} berhasil digunakan.`
          : "Harga promo sudah diperbarui.",
      );
    } catch (reason) {
      setQuote(null);
      setVoucherMessage(
        reason instanceof Error
          ? reason.message
          : "Voucher tidak dapat digunakan.",
      );
    } finally {
      setApplyingVoucher(false);
    }
  }

  function requestConfirmation(event: FormEvent) {
    event.preventDefault();
    if (!destination.trim() || (product.needsServer && !server.trim()) || !buyerEmail.trim() || !contact.trim() || !packageId) {
      setError("Lengkapi data akun, nominal, email, nomor WhatsApp, dan pembayaran.");
      return;
    }
    if (canCheckNickname && visibleNickname.status !== "success") {
      setError("Tunggu sampai nickname akun berhasil diverifikasi.");
      return;
    }
    setError("");
    setConfirmationOpen(true);
  }

  async function submitOrder(event?: FormEvent) {
    event?.preventDefault();
    if (!destination.trim() || (product.needsServer && !server.trim()) || !buyerEmail.trim() || !contact.trim() || !packageId || !agreed) {
      setError(
        "Lengkapi data akun, nominal, identitas pembeli, pembayaran, dan persetujuan.",
      );
      return;
    }
    if (canCheckNickname && visibleNickname.status !== "success") {
      setError("Tunggu sampai nickname akun berhasil diverifikasi.");
      return;
    }
    if (!providerReady) {
      setError(
        "Produk otomatis ini belum memiliki provider dan SKU. Atur dahulu dari panel admin.",
      );
      return;
    }
    setSubmitting(true);
    setPayment(null);
    setError("");
    try {
      const endpoint =
        paymentMethod === "wallet"
          ? "/api/payments/wallet/create"
          : paymentMethod === "manual_qris" || paymentMethod === "manual_bank"
            ? "/api/payments/manual/create"
            : "/api/payments/midtrans/create";
      const response = await fetch(endpoint, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          productSlug: product.slug,
          packageSku: packageId,
          destination: destination.trim(),
          server: server.trim() || undefined,
          nickname: visibleNickname.nickname,
          buyerName: buyerName.trim() || buyerEmail.trim().split("@")[0] || "Pelanggan",
          buyerEmail: buyerEmail.trim(),
          buyerPhone: contact.replace(/[\s()-]/g, ""),
          customerNotes: undefined,
          paymentMethod,
          paymentChannel,
          voucherCode: voucherCode.trim() || undefined,
        }),
      });
      const data = (await response.json()) as PaymentResult & {
        error?: string;
      };
      if (!response.ok)
        throw new Error(data.error ?? "Pembayaran gagal dibuat.");
      setPayment(data);
      if (data.balanceAfter != null)
        setAccount((current) =>
          current ? { ...current, balance: data.balanceAfter! } : current,
        );
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Pembayaran gagal dibuat.",
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <StoreLayout>
      <main className="mx-auto max-w-7xl px-4 pb-[11rem] pt-3 sm:px-6 sm:py-8 lg:px-8">
        <section className="relative mt-5 -mx-4 h-52 overflow-hidden bg-[#10131b] sm:-mx-6 sm:h-72 lg:-mx-8 lg:h-80">
          {(product.bannerUrl || product.imageUrl) && (
            <img
              src={product.bannerUrl || product.imageUrl}
              alt={`Banner ${product.name}`}
              className="absolute inset-0 size-full scale-[1.32] object-cover object-center sm:scale-100"
            />
          )}
          <div className="absolute inset-0 bg-gradient-to-t from-[#10131b]/65 via-transparent to-transparent" />
        </section>
        <section className="relative z-10 -mx-4 -mt-1 overflow-visible border-y border-white/[0.10] bg-[#14171e] px-5 py-5 shadow-2xl sm:-mx-6 sm:px-8 lg:-mx-8">
          <span className="absolute -top-12 left-5 block aspect-[3/3] size-24 overflow-hidden rounded-[22px] border-4 border-[#14171e] shadow-2xl [perspective:800px] [transform:rotateY(-14deg)_rotateZ(-3deg)] sm:-top-14 sm:left-8 sm:size-28">
            <ProductArtwork product={product} compact />
          </span>
          <div className="space-y-5">
            <div className="min-h-20 pl-32 sm:min-h-24 sm:pl-36">
              <h1 className="text-sm font-black uppercase tracking-[0.08em] text-white sm:text-base">
                {product.name}
              </h1>
              <p className="mt-2 text-xs font-medium text-white/68">
                {product.publisher}
              </p>
            </div>
            <div className="grid grid-cols-3 gap-3 text-center text-[9px] text-white/48">
              <span>
                <Zap className="mx-auto mb-1 size-4 text-[#cfff72]" />
                Proses cepat
              </span>
              <span>
                <ShieldCheck className="mx-auto mb-1 size-4 text-[#cfff72]" />
                Layanan Chat 24/7
              </span>
              <span>
                <BadgeCheck className="mx-auto mb-1 size-4 text-[#cfff72]" />
                Pembayaran aman
              </span>
            </div>
          </div>
        </section>
        <div className="mx-auto mt-5 grid max-w-7xl grid-cols-2 rounded-xl bg-white/[0.06] p-1 text-sm font-bold">
          <button type="button" onClick={() => setActiveTab("transaction")} className={`rounded-lg py-3 ${activeTab === "transaction" ? "bg-[#b9ff35] text-[#091006]" : "text-white/55"}`}>Transaksi</button>
          <button type="button" onClick={() => setActiveTab("details")} className={`rounded-lg py-3 ${activeTab === "details" ? "bg-[#b9ff35] text-[#091006]" : "text-white/55"}`}>Keterangan</button>
        </div>
        {activeTab === "transaction" ? <div className="mt-6 grid items-start gap-6 lg:grid-cols-[1fr_380px]">
          <form id="checkout-form" onSubmit={requestConfirmation} className="space-y-5">
            <section className="panel overflow-hidden">
              <div className="flex items-center justify-between gap-4 border-b border-white/[0.08] bg-white/[0.02] p-5 sm:p-6">
                <StepTitle
                  number="1"
                  title="Masukkan Data Akun"
                  description="Nickname diperiksa otomatis jika game mendukung."
                />
                <div className="hidden items-center gap-3 sm:flex">
                  <span className="block size-10 overflow-hidden rounded-xl aspect-square">
                    <ProductArtwork product={product} compact />
                  </span>
                  <div className="max-w-36">
                    <strong className="block truncate text-xs">
                      {product.name}
                    </strong>
                    <Link
                      href="/catalog"
                      className="mt-1 block text-[9px] font-semibold text-[#cfff72]"
                    >
                      Ganti produk
                    </Link>
                  </div>
                </div>
              </div>
              <div className="p-5 sm:p-6">
                <div className="mb-5 flex items-center gap-3 sm:hidden">
                  <span className="block size-10 overflow-hidden rounded-xl aspect-square">
                    <ProductArtwork product={product} compact />
                  </span>
                  <div>
                    <strong className="block text-xs">{product.name}</strong>
                    <Link
                      href="/catalog"
                      className="mt-1 block text-[9px] font-semibold text-[#cfff72]"
                    >
                      Ganti produk
                    </Link>
                  </div>
                </div>
                <div
                  className={
                    product.needsServer
                      ? "grid gap-4 sm:grid-cols-2"
                      : "grid gap-4"
                  }
                >
                  <Field label={product.inputLabel}>
                    <Input
                      value={destination}
                      onChange={(event) => {
                        setDestination(event.target.value);
                        setPayment(null);
                        setError("");
                      }}
                      placeholder={product.inputPlaceholder}
                      autoComplete="off"
                      className="checkout-input"
                    />
                  </Field>
                  {product.needsServer && (
                    <Field label="Server / Zone ID">
                      <Input
                        inputMode="numeric"
                        value={server}
                        onChange={(event) => {
                          setServer(event.target.value.replace(/\D/g, ""));
                          setPayment(null);
                          setError("");
                        }}
                        placeholder="Contoh: 1234"
                        autoComplete="off"
                        className="checkout-input"
                      />
                    </Field>
                  )}
                </div>
                {canCheckNickname ? (
                  <NicknameResult state={visibleNickname} />
                ) : (
                  <p className="mt-4 flex items-start gap-2 rounded-xl border border-white/[0.07] bg-white/[0.025] p-3 text-[10px] leading-5 text-white/32">
                    <Info className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />{" "}
                    Verifikasi nickname otomatis belum tersedia. Periksa kembali
                    data sebelum membayar.
                  </p>
                )}
                {product.manualInstructions && (
                  <div className="mt-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3 text-[10px] leading-5 text-amber-100/65">
                    <strong className="block text-amber-200">
                      Instruksi produk manual
                    </strong>
                    {product.manualInstructions}
                  </div>
                )}
              </div>
            </section>

            <section className="panel p-5 sm:p-6">
              <StepTitle
                number="2"
                title="Pilih nominal"
                description={
                  isManual
                    ? "Pesanan diproses admin setelah pembayaran."
                    : isVoucherStock
                      ? "Satu kode stok dikirim otomatis setelah pembayaran."
                      : "Pesanan diteruskan otomatis ke provider."
                }
              />
              <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                {product.packages.map((item) => {
                  const ready =
                    isManual || Boolean(item.providerCode && item.providerSku);
                  return (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => choosePackage(item.id)}
                      className={`relative min-h-24 rounded-2xl border p-3 text-left transition ${packageId === item.id ? "border-[#b9ff35] bg-[#b9ff35]/10 shadow-[inset_0_0_0_1px_rgba(185,255,53,.2)]" : "border-white/[0.09] bg-white/[0.025] hover:border-white/20"}`}
                    >
                      {item.note && (
                        <span className="absolute right-2 top-2 rounded-full bg-[#b9ff35] px-2 py-0.5 text-[8px] font-black uppercase text-[#091006]">
                          {item.note}
                        </span>
                      )}
                      <strong className="block pr-8 text-xs leading-5">
                        {item.label}
                      </strong>
                      <span className="mt-2 block text-[11px] font-bold text-[#cfff72]">
                        {formatRupiah(item.price)}
                      </span>
                      {!ready && (
                        <span className="mt-2 block text-[8px] font-semibold text-amber-300/70">
                          SKU belum diatur
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            </section>

            <section className="panel p-5 sm:p-6">
              <StepTitle
                number="3"
                title="Pilih metode pembayaran"
                description="Metode yang aktif ditampilkan di sini."
              />
              <div className="mt-5 space-y-3">
                {checkoutGroups.map((group) => {
                  const Icon = groupIcons[group.code];
                  const selected = paymentMethod === group.code;
                  const disabled = group.code === "wallet" && !account;
                  return (
                    <div
                      key={group.code}
                      className={`overflow-hidden rounded-2xl border transition ${selected ? "border-[#b9ff35]/60 bg-[#b9ff35]/[0.06]" : "border-white/[0.09] bg-white/[0.025]"}`}
                    >
                      <button
                        type="button"
                        disabled={disabled}
                        onClick={() => chooseMethod(group.code)}
                        className="flex w-full items-center gap-3 p-4 text-left disabled:cursor-not-allowed disabled:opacity-45"
                      >
                        <span
                          className={`grid size-10 place-items-center rounded-xl ${selected ? "bg-[#b9ff35] text-[#091006]" : "bg-white/[0.06] text-white/55"}`}
                        >
                          <Icon className="size-4" />
                        </span>
                        <span className="min-w-0 flex-1">
                          <strong className="block text-xs">
                            {group.name}
                          </strong>
                          <span className="mt-1 block text-[9px] text-white/35">
                            {group.code === "wallet" && account
                              ? `Saldo ${formatRupiah(account.balance)}`
                              : group.code === "wallet"
                                ? "Masuk akun untuk memakai saldo"
                                : group.description}
                          </span>
                        </span>
                        {selected && (
                          <CheckCircle2 className="size-4 text-[#b9ff35]" />
                        )}
                      </button>
                      {group.code !== "wallet" && (
                        <div className="flex min-h-10 items-center gap-2 overflow-hidden border-t border-white/[0.08] bg-black/10 px-4 py-2">
                          {group.code === "qris" ? (
                            <span className="text-[10px] font-bold text-white/75">QRIS • DANA • GoPay • ShopeePay • OVO</span>
                          ) : (
                            availableChannels.filter((channel) => channel.method === group.code).slice(0, 7).map((channel) => channel.imageUrl ? <img key={channel.channel} src={channel.imageUrl} alt={channel.name} className="h-5 max-w-14 object-contain" /> : <span key={channel.channel} className="rounded bg-white/[0.08] px-1.5 py-1 text-[8px] font-bold text-white/65">{channel.name}</span>)
                          )}
                        </div>
                      )}
                      {selected && isGatewayMethod && group.code !== "qris" && (
                        <div className="grid grid-cols-2 gap-2 border-t border-white/[0.08] bg-black/10 p-3 sm:grid-cols-3">
                          {availableChannels
                            .filter((channel) => channel.method === group.code)
                            .map((channel) => (
                              <button
                                key={channel.channel}
                                type="button"
                                onClick={() => {
                                  setPaymentChannel(channel.channel);
                                  setPayment(null);
                                }}
                                className={`flex min-h-14 items-center gap-2 rounded-xl border px-3 py-2 text-left text-[10px] font-bold transition ${paymentChannel === channel.channel ? "border-[#b9ff35]/60 bg-[#b9ff35]/[0.10] text-[#d8ff8d]" : "border-white/[0.08] bg-white/[0.02] text-white/45 hover:text-white"}`}
                              >
                                {channel.imageUrl ? (
                                  <img
                                    src={channel.imageUrl}
                                    alt=""
                                    className="size-8 rounded-lg object-contain"
                                  />
                                ) : (
                                  <span className="grid size-8 place-items-center rounded-lg bg-white/[0.07] text-[8px] font-black text-white/80">
                                    {channel.name.slice(0, 3)}
                                  </span>
                                )}
                                <span className="truncate">{channel.name}</span>
                              </button>
                            ))}
                        </div>
                      )}
                      {selected &&
                        (group.code === "manual_qris" ||
                          group.code === "manual_bank") && (
                          <p className="border-t border-white/[0.08] bg-black/10 px-4 py-3 text-[10px] leading-5 text-white/45">
                            {group.code === "manual_qris"
                              ? "QRIS dan total pembayaran akan muncul setelah pesanan dibuat."
                              : "Nomor rekening dan total transfer akan muncul setelah pesanan dibuat."}{" "}
                            Pemilik mengonfirmasi pembayaran sebelum pesanan
                            diproses.
                          </p>
                        )}
                    </div>
                  );
                })}
              </div>
              {!account && (
                <p className="mt-3 text-[10px] text-white/35">
                  Ingin membayar memakai saldo?{" "}
                  <Link href="/login" className="font-bold text-[#cfff72]">
                    Masuk atau daftar akun
                  </Link>
                  .
                </p>
              )}
            </section>

            <section className="panel p-5 sm:p-6">
              <StepTitle
                number="4"
                title="Data pembeli"
                description="Digunakan untuk invoice dan status transaksi."
              />
              <div className="mt-5 grid gap-4 sm:grid-cols-2">
                <Field label="Email"><Input type="email" value={buyerEmail} onChange={(event) => setBuyerEmail(event.target.value)} placeholder="nama@email.com" className="checkout-input" /></Field>
                <Field label="Nomor WhatsApp"><Input inputMode="tel" value={contact} onChange={(event) => setContact(event.target.value)} placeholder="081234567890" className="checkout-input" /></Field>
              </div>
              <p className="mt-3 flex items-start gap-1.5 text-[10px] leading-4 text-white/30"><ShieldCheck className="mt-0.5 size-3 shrink-0" /> Kami hanya memakai email dan WhatsApp untuk invoice serta status transaksi.</p>
            </section>

            <section className="panel p-5 sm:p-6">
              <StepTitle number="5" title="Kode voucher" description="Masukkan kode promo setelah data kontak." />
              <div className="mt-5 flex gap-2"><Input id="voucher-code" value={voucherCode} onChange={(event) => { setVoucherCode(event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, "")); setVoucherMessage(""); }} placeholder="Masukkan kode promo" className="checkout-input font-mono uppercase" /><Button type="button" onClick={() => void applyVoucher()} disabled={applyingVoucher || !packageId} variant="outline" className="h-12 shrink-0 rounded-xl border-white/10 bg-white/[0.04] px-4 text-white hover:bg-white/[0.08] hover:text-white">{applyingVoucher ? <LoaderCircle className="size-4 animate-spin" /> : "Gunakan"}</Button></div>
              {voucherMessage && <p className={`mt-2 text-[10px] ${quote?.voucherCode ? "text-[#cfff72]" : "text-amber-200"}`}>{voucherMessage}</p>}
            </section>
            {error && (
              <div className="flex items-start gap-2 rounded-xl border border-red-400/20 bg-red-400/[0.07] p-3 text-xs leading-5 text-red-200">
                <AlertCircle className="mt-0.5 size-4 shrink-0" />
                {error}
              </div>
            )}
            <Button
              disabled={submitting}
              type="submit"
              className="hidden h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]"
            >
              {submitting ? (
                <LoaderCircle className="mr-2 size-4 animate-spin" />
              ) : (
                <LockKeyhole className="mr-2 size-4" />
              )}
              Pesan Sekarang
            </Button>
          </form>

          <aside className="hidden panel p-5 lg:sticky lg:top-28 lg:block">
            <div className="flex items-center justify-between">
              <h2 className="font-bold">Ringkasan pesanan</h2>
              <span
                className={`rounded-full px-2.5 py-1 text-[9px] font-black uppercase ${isManual ? "bg-amber-400/10 text-amber-300" : "bg-[#b9ff35]/10 text-[#d8ff8d]"}`}
              >
                {isManual ? "Manual" : "Otomatis"}
              </span>
            </div>
            <div className="my-5 h-px bg-white/[0.08]" />
            <dl className="space-y-3 text-xs">
              <SummaryRow label="Produk" value={product.name} />
              {visibleNickname.nickname && (
                <SummaryRow
                  label="Nickname"
                  value={visibleNickname.nickname}
                  highlight
                />
              )}
              <SummaryRow
                label="Nominal"
                value={selectedPackage?.label ?? "Belum dipilih"}
              />
              <SummaryRow label="Harga" value={formatRupiah(subtotal)} />
              {quote && quote.sellingPrice < quote.basePrice && (
                <SummaryRow
                  label="Harga promo"
                  value={`-${formatRupiah(quote.basePrice - quote.sellingPrice)}`}
                  highlight
                />
              )}
              {quote && quote.discountAmount > 0 && (
                <SummaryRow
                  label={`Voucher ${quote.voucherCode ?? ""}`}
                  value={`-${formatRupiah(quote.discountAmount)}`}
                  highlight
                />
              )}
              <SummaryRow
                label="Biaya layanan"
                value={
                  paymentMethod === "wallet" ||
                  paymentMethod === "manual_qris" ||
                  paymentMethod === "manual_bank"
                    ? formatRupiah(0)
                    : payment
                      ? formatRupiah(payment.fee)
                      : "Dihitung otomatis"
                }
              />
              <SummaryRow
                label="Proses"
                value={
                  isManual
                    ? "Antrean admin"
                    : isVoucherStock
                      ? "Kirim kode otomatis"
                      : selectedPackage?.providerCode || "Provider belum diatur"
                }
              />
            </dl>
            <div className="my-5 h-px bg-white/[0.08]" />
            <div className="flex items-end justify-between">
              <span className="text-sm font-bold">Total</span>
              <strong className="text-xl font-black text-[#b9ff35]">
                {formatRupiah(payment?.total ?? subtotal)}
              </strong>
            </div>
            <p className="mt-3 rounded-xl bg-white/[0.035] p-3 text-[9px] leading-4 text-white/30">
              Biaya layanan, jika ada, dihitung oleh channel yang dipilih dan
              ditampilkan sebelum kamu melanjutkan pembayaran.
            </p>
            <Button
              form="checkout-form"
              disabled={submitting}
              type="submit"
              className="mt-5 hidden h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75] lg:flex"
            >
              {submitting ? (
                <LoaderCircle className="mr-2 size-4 animate-spin" />
              ) : (
                <LockKeyhole className="mr-2 size-4" />
              )}
              Pesan Sekarang
            </Button>
            <div className="mt-4 flex items-center justify-center gap-4 text-[9px] text-white/30">
              <span className="inline-flex items-center gap-1">
                <ShieldCheck className="size-3" /> Data aman
              </span>
              <span className="inline-flex items-center gap-1">
                <CreditCard className="size-3" /> Pembayaran terlindungi
              </span>
            </div>
            {payment && <PaymentBox payment={payment} />}
          </aside>
        </div> : (
          <section className="mt-6 space-y-5">
            <article className="panel p-5 sm:p-6"><h2 className="text-lg font-black">Deskripsi {product.name}</h2><p className="mt-3 whitespace-pre-line text-sm leading-7 text-white/60">{(product as { description?: string }).description || `Top up ${product.name} cepat, aman, dan diproses otomatis setelah pembayaran berhasil.`}</p></article>
            <ProductReviews productSlug={product.slug} />
            <article className="panel p-5 sm:p-6"><h2 className="text-lg font-black">Pertanyaan umum</h2><div className="mt-4 space-y-2">{["Bagaimana cara top up?","Metode pembayaran apa saja yang tersedia?","Berapa lama proses pesanan?","Apakah transaksi aman?"].map((question) => <details key={question} className="rounded-xl bg-white/[0.04] p-4"><summary className="cursor-pointer text-sm font-bold">{question}</summary><p className="pt-3 text-sm leading-6 text-white/55">Lengkapi data akun, pilih nominal dan metode pembayaran, lalu konfirmasi pesanan. Status transaksi dapat diperiksa setelah pembayaran dibuat.</p></details>)}</div></article>
          </section>
        )}
      </main>
      {activeTab === "transaction" && (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-white/10 bg-[#101217]/95 p-3 backdrop-blur lg:hidden">
          {summaryOpen && (
            <div className="mx-auto mb-3 max-w-xl rounded-2xl border border-white/[0.12] bg-[#191b20] p-4 shadow-2xl">
              <button type="button" onClick={() => setSummaryOpen(false)} className="flex w-full items-center gap-3 text-left">
                <span className="block size-11 shrink-0 overflow-hidden rounded-lg"><ProductArtwork product={product} compact /></span>
                <span className="min-w-0 flex-1"><strong className="block truncate text-sm">{product.name}</strong><span className="block truncate text-xs text-white/50">{selectedPackage?.label ?? "Pilih nominal"}</span></span>
                <strong className="text-sm text-[#cfff72]">{formatRupiah(subtotal)}</strong>
              </button>
              <dl className="mt-3 space-y-2 border-t border-white/10 pt-3 text-xs"><SummaryRow label="Harga" value={formatRupiah(subtotal)} /><SummaryRow label="Jumlah Pembelian" value="1" /><SummaryRow label="Biaya" value={formatRupiah(0)} /><SummaryRow label="Total Pembayaran" value={formatRupiah(subtotal)} highlight /></dl>
            </div>
          )}
          {!summaryOpen && <button type="button" onClick={() => setSummaryOpen(true)} className="mx-auto mb-2 block text-xs font-bold text-white/75">Tampilkan ringkasan pesanan</button>}
          <Button form="checkout-form" type="submit" disabled={submitting} className="mx-auto h-12 w-full max-w-xl rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]"><LockKeyhole className="mr-2 size-4" />Pesan Sekarang</Button>
        </div>
      )}
      <Dialog open={confirmationOpen} onOpenChange={setConfirmationOpen}>
        <DialogContent className="max-w-md border-white/10 bg-[#191b20] text-white" showCloseButton={false}>
          <DialogHeader><div className="mx-auto grid size-14 place-items-center rounded-full bg-[#b9ff35]/15"><CheckCircle2 className="size-8 text-[#b9ff35]" /></div><DialogTitle className="pt-3 text-center text-lg font-black">Buat Pesanan</DialogTitle><DialogDescription className="text-center text-xs leading-5 text-white/55">Pastikan data akun dan produk yang kamu pilih sudah valid dan sesuai.</DialogDescription></DialogHeader>
          <dl className="rounded-xl bg-black/15 p-4 text-xs"><SummaryRow label="Username" value={visibleNickname.nickname || "-"} /><SummaryRow label="ID" value={destination || "-"} />{product.needsServer && <SummaryRow label="Server" value={server || "-"} />}<SummaryRow label="Item" value={selectedPackage?.label ?? "-"} /><SummaryRow label="Produk" value={product.name} /><SummaryRow label="Payment" value={checkoutGroups.find((item) => item.code === paymentMethod)?.name ?? "-"} /></dl>
          <label className="flex cursor-pointer items-start gap-3 text-xs leading-5 text-white/60"><input type="checkbox" checked={agreed} onChange={(event) => setAgreed(event.target.checked)} className="mt-0.5 size-4 accent-[#b9ff35]" />Dengan melanjutkan, saya menyetujui syarat & ketentuan yang berlaku.</label>
          <div className="grid grid-cols-2 gap-3"><Button type="button" onClick={() => { setConfirmationOpen(false); void submitOrder(); }} disabled={!agreed || submitting} className="bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]">{submitting ? "Memproses..." : "Pesan Sekarang"}</Button><Button type="button" variant="outline" onClick={() => setConfirmationOpen(false)} className="border-white/10 bg-white/[0.04] text-white hover:bg-white/[0.08] hover:text-white">Batalkan</Button></div>
        </DialogContent>
      </Dialog>
      <Dialog
        open={noticeOpen}
        onOpenChange={(open) => {
          if (!open) closeNotice();
          else setNoticeOpen(true);
        }}
      >
        <DialogContent
          className="max-w-lg overflow-hidden border-white/10 bg-[#080b14] p-0 text-white"
          showCloseButton={false}
        >
          {notices.length > 0 && (
            <>
              <div className="flex items-center justify-between border-b border-white/[0.08] px-6 py-4">
                <span className="font-mono text-xs text-white/40">
                  {noticeIndex + 1}/{notices.length}
                </span>
                <button
                  type="button"
                  onClick={closeNotice}
                  className="rounded-lg p-1.5 text-white/55 hover:bg-white/[0.06] hover:text-white"
                  aria-label="Tutup informasi"
                >
                  <X className="size-5" />
                </button>
              </div>
              <div className="px-6 py-6">
                <DialogHeader>
                  <DialogTitle className="text-left text-lg font-black uppercase leading-7">
                    {formatNotice(notices[noticeIndex].title, product)}
                  </DialogTitle>
                  <DialogDescription className="whitespace-pre-line text-left text-sm leading-7 text-white/64">
                    {formatNotice(notices[noticeIndex].body, product)}
                  </DialogDescription>
                </DialogHeader>
                {notices.length > 1 && (
                  <div className="mt-6 flex items-center justify-between gap-3">
                    <Button
                      type="button"
                      variant="outline"
                      disabled={noticeIndex === 0}
                      onClick={() =>
                        setNoticeIndex((value) => Math.max(0, value - 1))
                      }
                      className="border-white/10 bg-white/[0.03] text-white"
                    >
                      Sebelumnya
                    </Button>
                    <Button
                      type="button"
                      disabled={noticeIndex === notices.length - 1}
                      onClick={() =>
                        setNoticeIndex((value) =>
                          Math.min(notices.length - 1, value + 1),
                        )
                      }
                      className="bg-[#b9ff35] text-[#091006]"
                    >
                      Berikutnya
                    </Button>
                  </div>
                )}
              </div>
              <label className="flex cursor-pointer items-center gap-3 border-t border-white/[0.08] px-6 py-5 text-xs text-white/45">
                <input
                  type="checkbox"
                  checked={hideNotice}
                  onChange={(event) => setHideNotice(event.target.checked)}
                  className="size-4 accent-[#b9ff35]"
                />
                Jangan tampilkan lagi dalam 7 hari
              </label>
            </>
          )}
        </DialogContent>
      </Dialog>
    </StoreLayout>
  );
}

function formatNotice(
  value: string,
  product: {
    manualOpenTime?: string;
    manualCloseTime?: string;
    manualTimezone?: string;
  },
) {
  const zone =
    product.manualTimezone === "Asia/Makassar"
      ? "WITA"
      : product.manualTimezone === "Asia/Jayapura"
        ? "WIT"
        : "WIB";
  return value
    .replaceAll("{{jam_buka}}", product.manualOpenTime ?? "-")
    .replaceAll("{{jam_tutup}}", product.manualCloseTime ?? "-")
    .replaceAll("{{zona_waktu}}", zone);
}

function noticeVersion(notices: Array<{ title: string; body: string }>) {
  let hash = 2166136261;
  for (const character of notices
    .map((item) => `${item.title}\n${item.body}`)
    .join("\n---\n")) {
    hash ^= character.charCodeAt(0);
    hash = Math.imul(hash, 16777619);
  }
  return (hash >>> 0).toString(36);
}

async function requestQuote(
  productSlug: string,
  packageSku: string,
  voucherCode = "",
  signal?: AbortSignal,
) {
  const response = await fetch("/api/promotions/quote", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({
      productSlug,
      packageSku,
      voucherCode: voucherCode || undefined,
    }),
    signal,
  });
  const data = (await response.json()) as PromotionQuote & { error?: string };
  if (!response.ok)
    throw new Error(data.error ?? "Harga promo gagal dihitung.");
  return data;
}

function StepTitle({
  title,
  description,
}: {
  number: string;
  title: string;
  description: string;
}) {
  return (
    <div>
      <h2 className="font-bold">{title}</h2>
      <p className="mt-1 text-[11px] text-white/35">{description}</p>
    </div>
  );
}

function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <label>
      <span className="field-label">{label}</span>
      {children}
    </label>
  );
}

function SummaryRow({
  label,
  value,
  highlight = false,
}: {
  label: string;
  value: string;
  highlight?: boolean;
}) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-white/38">{label}</dt>
      <dd
        className={`max-w-52 truncate text-right font-semibold ${highlight ? "text-[#cfff72]" : ""}`}
      >
        {value}
      </dd>
    </div>
  );
}

function PaymentBox({ payment }: { payment: PaymentResult }) {
  const fulfillmentMessage =
    payment.fulfillmentType === "manual"
      ? "Setelah lunas, pesanan masuk antrean admin."
      : payment.providerCode === "voucher-stock"
        ? "Setelah lunas, satu kode stok dikirim otomatis ke email/WhatsApp pembeli."
        : "Setelah lunas, pesanan diteruskan otomatis ke provider.";
  const isManualQris = payment.paymentMethod === "manual_qris";
  return (
    <div className="mt-5 rounded-2xl border border-[#b9ff35]/30 bg-[#b9ff35]/[0.08] p-4">
      <BadgeCheck className="size-6 text-[#b9ff35]" />
      <h3 className="mt-3 text-sm font-black">
        {payment.paymentStatus === "paid"
          ? "Pembayaran berhasil"
          : "Pembayaran dibuat"}
      </h3>
      <p className="mt-1 break-all text-[10px] text-white/45">
        {payment.referenceId}
      </p>
      {payment.paymentNo && (
        <div className="mt-3 rounded-xl bg-black/20 p-3">
          <span className="text-[9px] uppercase tracking-wider text-white/35">
            {payment.paymentName || "Nomor pembayaran"}
          </span>
          <div className="mt-1 flex items-center justify-between gap-2">
            <strong className="break-all text-sm text-[#d8ff8d]">
              {payment.paymentNo}
            </strong>
            <button
              type="button"
              onClick={() =>
                void navigator.clipboard.writeText(payment.paymentNo!)
              }
              className="shrink-0 text-white/45 hover:text-white"
              aria-label="Salin nomor pembayaran"
            >
              <Copy className="size-4" />
            </button>
          </div>
        </div>
      )}
      {payment.balanceAfter != null && (
        <p className="mt-3 rounded-xl bg-black/20 p-3 text-[10px] text-white/55">
          Sisa saldo:{" "}
          <strong className="text-[#d8ff8d]">
            {formatRupiah(payment.balanceAfter)}
          </strong>
        </p>
      )}
      {isManualQris && payment.paymentUrl && (
        <div className="mt-4 rounded-xl bg-white p-3">
          <img
            src={payment.paymentUrl}
            alt="QRIS pembayaran"
            className="mx-auto aspect-square w-full max-w-64 object-contain"
          />
        </div>
      )}
      {payment.expiredAt && (
        <p className="mt-3 text-[9px] text-white/35">
          Berlaku sampai {payment.expiredAt}
        </p>
      )}
      {payment.paymentUrl && !isManualQris && (
        <Button
          asChild
          className="mt-4 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]"
        >
          <a href={payment.paymentUrl} target="_blank" rel="noreferrer">
            Lanjut bayar <ExternalLink className="ml-2 size-4" />
          </a>
        </Button>
      )}
      <p className="mt-3 flex items-start gap-2 text-[9px] leading-4 text-white/38">
        {payment.fulfillmentType === "automatic" ? (
          <Zap className="mt-0.5 size-3 shrink-0 text-[#b9ff35]" />
        ) : (
          <Info className="mt-0.5 size-3 shrink-0 text-amber-300" />
        )}
        {fulfillmentMessage}
      </p>
    </div>
  );
}

function NicknameResult({ state }: { state: NicknameState }) {
  if (state.status === "loading")
    return (
      <div className="mt-4 flex items-center gap-3 rounded-xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.05] p-3 text-xs text-white/48">
        <LoaderCircle className="size-4 animate-spin text-[#b9ff35]" />
        Memeriksa ID dan Server…
      </div>
    );
  if (state.status === "success")
    return (
      <div className="mt-4 flex items-start gap-3 rounded-xl border border-emerald-400/30 bg-emerald-400/[0.08] p-4">
        <BadgeCheck className="mt-0.5 size-5 shrink-0 text-emerald-400" />
        <div>
          <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-300/70">
            Akun ditemukan
          </p>
          <strong className="mt-1 block break-words text-sm text-emerald-200">
            {state.nickname}
          </strong>
          {state.country && (
            <p className="mt-1 text-[10px] text-emerald-100/55">
              dari {state.country}
              {state.country.toLowerCase() === "indonesia" ? " 🇮🇩" : ""}
            </p>
          )}
        </div>
      </div>
    );
  if (state.status === "error")
    return (
      <div className="mt-4 flex items-start gap-3 rounded-xl border border-red-400/25 bg-red-400/[0.07] p-4">
        <AlertCircle className="mt-0.5 size-5 shrink-0 text-red-300" />
        <div>
          <p className="text-xs font-bold text-red-200">
            Akun belum terverifikasi
          </p>
          <p className="mt-1 text-[10px] leading-5 text-red-100/55">
            {state.message}
          </p>
        </div>
      </div>
    );
  return (
    <div className="mt-4 flex items-start gap-3 rounded-xl border border-white/[0.07] bg-white/[0.025] p-3 text-[10px] leading-5 text-white/32">
      <Info className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />
      Nickname akan tampil otomatis setelah User ID dan Server yang diperlukan
      terisi.
    </div>
  );
}
