"use client";
/* eslint-disable @next/next/no-img-element */

import { FormEvent, Suspense, useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import {
  AlertCircle,
  BadgeCheck,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
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
  paymentGroups,
  type PaymentChannel,
  type PaymentMethodCode,
} from "@/lib/payment-methods";
import { formatRupiah, type StoreProduct } from "@/lib/store-data";
import { IPAYMU_MIN_CHECKOUT_AMOUNT, isIpaymuAmountSupported } from "@/lib/payment-limits";
import type { CustomerSession } from "@/lib/server/customer-auth";

const INTERNAL_VOUCHER_DESTINATION = "00000000";

const requiredNicknameGames = new Set([
  "mobile-legends",
  "free-fire",
  "genshin-impact",
  "valorant",
]);

const optionalNicknameGames = new Set([
  "pubg-mobile",
  "honor-of-kings",
  "call-of-duty-mobile",
  "wild-rift",
  "arena-of-valor",
  "fc-mobile",
  "efootball",
  "point-blank",
]);

type NicknameState = {
  status: "idle" | "loading" | "success" | "error";
  key?: string;
  nickname?: string;
  country?: string | null;
  message?: string;
};

type SavedGameValue = {
  id: string;
  label: string;
  value: string;
};

type SavedGameAccount = {
  id: string;
  label: string;
  nickname: string | null;
  values: SavedGameValue[];
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
  paymentGateway?: "ipaymu" | "midtrans";
  publicInvoice?: string;
  midtransMode?: "snap";
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
type CheckoutGateway = {
  code: "midtrans" | "ipaymu";
  label: string;
  midtransMode: "snap" | null;
  environment: "sandbox" | "production" | null;
  channels: DisplayPaymentChannel[];
};

type CheckoutGatewayConfig = {
  gateway: "midtrans" | "ipaymu" | null;
  midtransMode: "snap" | null;
  environment: "sandbox" | "production" | null;
  channels?: DisplayPaymentChannel[];
  gateways?: CheckoutGateway[];
};
const groupIcons = {
  va: Landmark,
  ewallet: WalletCards,
  qris: QrCode,
  wallet: WalletCards,
};

function packageGroupName(value?: string) {
  return value?.trim() || "Umum";
}

function normalizeWhatsapp(value: string) {
  const hasPlus = value.trim().startsWith("+");
  const digits = value.replace(/\D/g, "").slice(0, 16);
  return `${hasPlus ? "+" : ""}${digits}`;
}

function publicNicknameMessage(value: string) {
  return value
    .replace(/Melostore/gi, "layanan verifikasi")
    .replace(/API Key atau Secret Key/gi, "Konfigurasi layanan");
}

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
  const { products, databaseReady, loading } = useStoreProducts();
  const requestedProduct = searchParams.get("product");
  const product = useMemo(
    () =>
      products.find((item) => item.slug === requestedProduct) ?? products[0],
    [products, requestedProduct],
  );

  if (loading) {
    return (
      <StoreLayout>
        <main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-14 text-sm text-white/40 sm:px-6 lg:px-8">
          Memuat katalog LFAMILIA…
        </main>
      </StoreLayout>
    );
  }

  if (!product) {
    return (
      <StoreLayout>
        <main className="mx-auto min-h-[70vh] max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
          <div className="rounded-xl border border-amber-300/20 bg-amber-300/[0.05] p-5">
            <h1 className="text-lg font-black text-white">Katalog belum tersedia</h1>
            <p className="mt-2 text-xs leading-5 text-white/45">
              {databaseReady
                ? "Belum ada produk aktif di database LFAMILIA."
                : "Katalog tidak dapat dibaca dari database. Checkout dinonaktifkan agar harga lama tidak digunakan."}
            </p>
            <Button asChild variant="outline" className="mt-4 border-white/10 bg-white/[0.03] text-white">
              <Link href="/catalog">Kembali ke katalog</Link>
            </Button>
          </div>
        </main>
      </StoreLayout>
    );
  }

  return (
    <CheckoutContent
      key={`${product.slug}:${searchParams.get("package") ?? ""}`}
      product={product}
    />
  );
}

function CheckoutContent({ product }: { product: StoreProduct }) {
  const walletAttemptRef = useRef<{ fingerprint: string; key: string } | null>(null);
  const searchParams = useSearchParams();
  const requestedPackage = searchParams.get("package");
  const [packageId, setPackageId] = useState(() =>
    requestedPackage &&
    product.packages.some((item) => item.id === requestedPackage)
      ? requestedPackage
      : "",
  );
  const [packageGroupChoice, setPackageGroupChoice] = useState("");
  const [customerInputValues, setCustomerInputValues] = useState<Record<string, string>>({});
  const [buyerName, setBuyerName] = useState("");
  const [buyerEmail, setBuyerEmail] = useState("");
  const [contact, setContact] = useState("");
  const [activeTab, setActiveTab] = useState<"transaction" | "details">("transaction");
  const [confirmationOpen, setConfirmationOpen] = useState(false);
  const [summaryOpen, setSummaryOpen] = useState(false);
  const [paymentMethod, setPaymentMethod] =
    useState<CheckoutPaymentMethod>("qris");
  const [paymentChannel, setPaymentChannel] = useState("");
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
  const [savedGameAccounts, setSavedGameAccounts] = useState<SavedGameAccount[]>([]);
  const [selectedSavedGameAccountId, setSelectedSavedGameAccountId] = useState("");
  const [gatewayOptions, setGatewayOptions] = useState<CheckoutGateway[]>([]);
  const [paymentMethodsLoaded, setPaymentMethodsLoaded] = useState(false);

  const selectedPackage = product.packages.find(
    (item) => item.id === packageId,
  );
  const packageGroups = useMemo(() => {
    if (!product.packageTabsEnabled || !product.packages.length) return [];
    const assigned = Array.from(
      new Set(product.packages.map((item) => packageGroupName(item.group))),
    );
    const configured = (product.packageTabs ?? [])
      .map((item) => item.trim())
      .filter((item) => item && assigned.includes(item));
    return [
      ...configured,
      ...assigned.filter((item) => !configured.includes(item)),
    ];
  }, [product.packageTabs, product.packageTabsEnabled, product.packages]);
  const selectedPackageGroup = selectedPackage
    ? packageGroupName(selectedPackage.group)
    : "";
  const activePackageGroup = packageGroups.includes(packageGroupChoice)
    ? packageGroupChoice
    : packageGroups.includes(selectedPackageGroup)
      ? selectedPackageGroup
      : packageGroups[0] ?? "";
  const visiblePackages =
    packageGroups.length > 1
      ? product.packages.filter(
          (item) => packageGroupName(item.group) === activePackageGroup,
        )
      : product.packages;
  const productInputFields = useMemo(
    () => product.inputFields ?? [
      { id: "account-id", label: product.inputLabel, placeholder: product.inputPlaceholder, required: true },
      ...(product.needsServer ? [{ id: "server-zone", label: "Server / Zone ID", placeholder: "Contoh: 1234", required: true }] : []),
    ],
    [product.inputFields, product.inputLabel, product.inputPlaceholder, product.needsServer],
  );
  const isVoucherProduct = product.category.trim().toLowerCase() === "voucher";
  const isGameProduct = product.category.trim().toLowerCase() === "game";
  const destination = isVoucherProduct
    ? INTERNAL_VOUCHER_DESTINATION
    : productInputFields[0]
      ? (customerInputValues[productInputFields[0].id] ?? "")
      : "";
  const server = isVoucherProduct
    ? ""
    : productInputFields[1]
      ? (customerInputValues[productInputFields[1].id] ?? "")
      : "";
  const subtotal = quote?.finalPrice ?? selectedPackage?.price ?? 0;
  const eligibleGatewayOptions = useMemo(
    () => gatewayOptions.filter((gateway) =>
      gateway.code !== "ipaymu" || subtotal <= 0 || isIpaymuAmountSupported(subtotal),
    ),
    [gatewayOptions, subtotal],
  );
  const isManual = product.fulfillmentType === "manual";
  const isVoucherStock = selectedPackage?.providerCode === "voucher-stock";
  const providerReady =
    isManual ||
    Boolean(selectedPackage?.providerCode && selectedPackage?.providerSku);
  const nicknameRequired =
    !isVoucherProduct && requiredNicknameGames.has(product.slug);
  const canCheckNickname =
    !isVoucherProduct &&
    (nicknameRequired || optionalNicknameGames.has(product.slug));
  const lookupNeedsServer = product.slug === "mobile-legends";
  const lookupKey = `${product.slug}:${destination.trim()}:${server.trim()}`;
  const visibleNickname: NicknameState =
    nickname.key === lookupKey ? nickname : { status: "idle" };
  const isGatewayMethod =
    paymentMethod === "va" ||
    paymentMethod === "ewallet" ||
    paymentMethod === "qris";
  const checkoutGatewayCandidates = useMemo(
    () => eligibleGatewayOptions.filter((gateway) => gateway.channels.length > 0),
    [eligibleGatewayOptions],
  );
  const displayChannels = useMemo(() => {
    const unique = new Map<string, DisplayPaymentChannel>();
    for (const gateway of checkoutGatewayCandidates) {
      for (const channel of gateway.channels) {
        const key = `${channel.method}:${channel.channel}`;
        if (!unique.has(key)) unique.set(key, channel);
      }
    }
    return [...unique.values()];
  }, [checkoutGatewayCandidates]);
  const gatewayPaymentGroups = useMemo(() => {
    const availableGatewayMethods = new Set(
      displayChannels.map((item) => item.method),
    );
    return paymentGroups
      .filter((item) => availableGatewayMethods.has(item.code))
      .sort(
        (left, right) =>
          ["qris", "ewallet", "va"].indexOf(left.code) -
          ["qris", "ewallet", "va"].indexOf(right.code),
      );
  }, [displayChannels]);
  const checkoutGroups = useMemo(() => [
    {
      code: "wallet" as const,
      name: "Koin LFAMILIA",
      description: "Bayar langsung dari saldo akun",
    },
    ...gatewayPaymentGroups,
  ], [gatewayPaymentGroups]);
  const hasExternalPaymentOption = gatewayPaymentGroups.length > 0;
  const isIpaymuMinimumBlocked =
    subtotal > 0 &&
    subtotal < IPAYMU_MIN_CHECKOUT_AMOUNT &&
    gatewayOptions.some((gateway) => gateway.code === "ipaymu") &&
    !hasExternalPaymentOption;
  const unavailablePaymentMessage = isIpaymuMinimumBlocked
    ? `iPaymu tersedia mulai ${formatRupiah(IPAYMU_MIN_CHECKOUT_AMOUNT)}. Pilih nominal lain atau gunakan Koin LFAMILIA.`
    : "Pilih metode pembayaran yang tersedia.";

  const chooseMethod = useCallback((method: CheckoutPaymentMethod) => {
    setPayment(null);
    setPaymentMethod(method);
    if (method === "wallet") {
      setPaymentChannel("lfamilia-balance");
      return;
    }
    setPaymentChannel(
      displayChannels.find((channel) => channel.method === method)?.channel ?? "",
    );
  }, [displayChannels]);

  const notices = (product.notices ?? []).filter(
    (item) => item.isActive !== false,
  );
  const noticeSignature = noticeVersion(notices);

  useEffect(() => {
    void fetch("/api/payment-methods", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) {
          setGatewayOptions([]);
          return;
        }
        const data = (await response.json()) as CheckoutGatewayConfig;
        const fallbackGateways: CheckoutGateway[] = data.gateway
          ? [{
              code: data.gateway,
              label: data.gateway === "midtrans" ? "Midtrans Snap" : "iPaymu",
              midtransMode: data.midtransMode ?? null,
              environment: data.environment ?? null,
              channels: data.channels ?? [],
            }]
          : [];
        const gateways = data.gateways?.length
          ? data.gateways
          : fallbackGateways;
        setGatewayOptions(gateways);
      })
      .catch(() => {
        setGatewayOptions([]);
      })
      .finally(() => {
        setPaymentMethodsLoaded(true);
      });
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
    if (!account?.id || !isGameProduct || isVoucherProduct) return;

    const controller = new AbortController();
    void fetch(
      `/api/account/game-accounts?product=${encodeURIComponent(product.slug)}`,
      { cache: "no-store", signal: controller.signal },
    )
      .then(async (response) => {
        if (!response.ok) return;
        const data = (await response.json()) as {
          accounts?: SavedGameAccount[];
        };
        setSavedGameAccounts(data.accounts ?? []);
      })
      .catch(() => undefined);

    return () => controller.abort();
  }, [account?.id, isGameProduct, isVoucherProduct, product.slug]);

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
      cleanId.length < (nicknameRequired ? 4 : 2) ||
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
              ? publicNicknameMessage(reason.message)
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
    nicknameRequired,
    lookupKey,
    lookupNeedsServer,
    product.slug,
    server,
  ]);

  useEffect(() => {
    if (!paymentMethodsLoaded) return;

    const enabled = checkoutGroups.map((group) => group.code);
    const currentChannelValid =
      paymentMethod === "wallet"
        ? Boolean(account)
        : displayChannels.some(
            (item) =>
              item.method === paymentMethod &&
              item.channel === paymentChannel,
          );
    const methodValid =
      enabled.includes(paymentMethod) &&
      (paymentMethod !== "wallet" || Boolean(account));

    if (methodValid && currentChannelValid) return;

    const timer = window.setTimeout(() => {
      if (
        paymentMethod !== "wallet" &&
        enabled.includes(paymentMethod) &&
        displayChannels.some((item) => item.method === paymentMethod)
      ) {
        chooseMethod(paymentMethod);
        return;
      }
      if (gatewayPaymentGroups.length) {
        chooseMethod(gatewayPaymentGroups[0].code);
        return;
      }
      if (account) {
        chooseMethod("wallet");
        return;
      }
      setPaymentMethod("qris");
      setPaymentChannel("");
    }, 0);

    return () => window.clearTimeout(timer);
  }, [
    account,
    checkoutGroups,
    displayChannels,
    gatewayPaymentGroups,
    paymentChannel,
    paymentMethod,
    paymentMethodsLoaded,
    chooseMethod,
  ]);

  function choosePackage(id: string) {
    setPackageId(id);
    setPayment(null);
    setVoucherCode("");
    setVoucherMessage("");
    setQuote(null);
  }

  function chooseSavedGameAccount(id: string) {
    setSelectedSavedGameAccountId(id);
    const saved = savedGameAccounts.find((item) => item.id === id);
    if (!saved) return;

    const savedById = new Map(
      saved.values.map((item) => [item.id, item.value]),
    );
    setCustomerInputValues(
      Object.fromEntries(
        productInputFields.map((field) => [
          field.id,
          savedById.get(field.id) ?? "",
        ]),
      ),
    );
    setPayment(null);
    setError("");
  }

  function choosePackageGroup(group: string) {
    setPackageGroupChoice(group);
    if (
      selectedPackage &&
      packageGroupName(selectedPackage.group) === group
    ) {
      return;
    }
    const firstPackage = product.packages.find(
      (item) => packageGroupName(item.group) === group,
    );
    if (firstPackage) choosePackage(firstPackage.id);
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
    const missingRequiredAccountData =
      !isVoucherProduct &&
      productInputFields.some(
        (field) =>
          field.required !== false &&
          !(customerInputValues[field.id] ?? "").trim(),
      );
    if (missingRequiredAccountData || !buyerEmail.trim() || !contact.trim() || !packageId) {
      setError("Lengkapi data yang wajib, nominal, email, nomor WhatsApp, dan pembayaran.");
      return;
    }
    if (nicknameRequired && visibleNickname.status !== "success") {
      setError("Tunggu sampai nickname akun berhasil diverifikasi.");
      return;
    }
    if (
      (paymentMethod === "wallet" && !account) ||
      (paymentMethod !== "wallet" && !paymentChannel)
    ) {
      setError(unavailablePaymentMessage);
      return;
    }
    setError("");
    setConfirmationOpen(true);
  }

  async function submitOrder(event?: FormEvent) {
    event?.preventDefault();
    const missingRequiredAccountData =
      !isVoucherProduct &&
      productInputFields.some(
        (field) =>
          field.required !== false &&
          !(customerInputValues[field.id] ?? "").trim(),
      );
    if (missingRequiredAccountData || !buyerEmail.trim() || !contact.trim() || !packageId || !agreed) {
      setError(
        "Lengkapi data yang wajib, nominal, identitas pembeli, pembayaran, dan persetujuan.",
      );
      return;
    }
    if (nicknameRequired && visibleNickname.status !== "success") {
      setError("Tunggu sampai nickname akun berhasil diverifikasi.");
      return;
    }
    if (
      (paymentMethod === "wallet" && !account) ||
      (paymentMethod !== "wallet" && !paymentChannel)
    ) {
      setError(unavailablePaymentMessage);
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
          : "/api/payments/auto/create";
      if (paymentMethod !== "wallet" && !paymentChannel)
        throw new Error("Pilih metode pembayaran yang tersedia.");
      const requestPayload = {
          productSlug: product.slug,
          packageSku: packageId,
          destination: destination.trim(),
          server: server.trim() || undefined,
          customerInputs: productInputFields.map((field, index) => ({
            id: field.id,
            value: isVoucherProduct && index === 0
              ? INTERNAL_VOUCHER_DESTINATION
              : (customerInputValues[field.id] ?? "").trim(),
          })),
          nickname: visibleNickname.nickname,
          buyerName: buyerName.trim() || buyerEmail.trim().split("@")[0] || "Pelanggan",
          buyerEmail: buyerEmail.trim(),
          buyerPhone: contact.replace(/[\s()-]/g, ""),
          customerNotes: undefined,
          paymentMethod,
          paymentChannel,
          voucherCode: voucherCode.trim() || undefined,
      };
      const fingerprint = JSON.stringify(requestPayload);
      if (paymentMethod === "wallet" && walletAttemptRef.current?.fingerprint !== fingerprint) {
        walletAttemptRef.current = { fingerprint, key: crypto.randomUUID() };
      }
      const response = await fetch(endpoint, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          ...requestPayload,
          idempotencyKey: paymentMethod === "wallet" ? walletAttemptRef.current?.key : undefined,
        }),
      });
      const data = (await response.json()) as PaymentResult & {
        error?: string;
        retryable?: boolean;
      };
      if (!response.ok) {
        if (!data.retryable) walletAttemptRef.current = null;
        throw new Error(data.error ?? "Pembayaran gagal dibuat.");
      }
      walletAttemptRef.current = null;
      setPayment(data);
      if (data.balanceAfter != null) {
        setAccount((current) =>
          current ? { ...current, balance: data.balanceAfter! } : current,
        );
        return;
      }

      const invoice = data.publicInvoice || data.referenceId;
      window.location.assign(
        `/payment?invoice=${encodeURIComponent(invoice)}`,
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
      <main className="mx-auto max-w-7xl px-4 pb-[9rem] pt-3 sm:px-6 sm:py-7 lg:px-8">
        <section data-lf-checkout-banner="true" className="relative mt-2 -mx-4 h-48 overflow-hidden bg-[#10131b] sm:-mx-6 sm:h-64 lg:-mx-8 lg:h-72">
          {(product.bannerUrl || product.imageUrl) && (
            <img
              src={product.bannerUrl || product.imageUrl}
              alt={`Banner ${product.name}`}
              data-lf-checkout-banner-image="true"
              className="absolute inset-0 size-full object-cover object-center"
            />
          )}
        </section>

        <section data-lf-product-hero="true" className="relative z-10 -mx-4 min-h-[118px] overflow-visible border-y border-white/[0.10] bg-[#202224] px-4 py-3 shadow-xl sm:-mx-6 sm:px-6 lg:-mx-8">
          <span data-lf-product-art="true" className="absolute -top-12 left-4 block aspect-square size-24 overflow-hidden rounded-[14px] border-[3px] border-[#202224] shadow-xl sm:-top-14 sm:left-6 sm:size-28">
            <ProductArtwork product={product} compact />
          </span>
          <div data-lf-product-info="true" className="pl-28 pt-1 sm:pl-32">
            <h1 className="text-sm font-black uppercase tracking-[0.06em] text-white sm:text-base">
              {product.name}
            </h1>
            <p className="mt-1 text-[10px] font-medium text-white/55 sm:text-xs">
              {product.publisher}
            </p>
          </div>
          <div data-lf-product-features="true" className="absolute inset-x-4 bottom-3 grid grid-cols-3 gap-2 text-center text-[8px] text-white/50 sm:inset-x-6 sm:text-[9px]">
            <span><Zap className="mx-auto mb-0.5 size-3.5 text-[#cfff72]" />Proses cepat</span>
            <span><ShieldCheck className="mx-auto mb-0.5 size-3.5 text-[#cfff72]" />Chat 24/7</span>
            <span><BadgeCheck className="mx-auto mb-0.5 size-3.5 text-[#cfff72]" />Pembayaran aman</span>
          </div>
        </section>

        <div className="mx-auto mt-3 grid max-w-7xl grid-cols-2 rounded-lg border border-white/[0.07] bg-white/[0.04] p-1 text-xs font-bold">
          <button type="button" onClick={() => setActiveTab("transaction")} className={`rounded-md py-2 transition ${activeTab === "transaction" ? "bg-[#bca17d] text-white" : "text-white/50 hover:text-white"}`}>Transaksi</button>
          <button type="button" onClick={() => setActiveTab("details")} className={`rounded-md py-2 transition ${activeTab === "details" ? "bg-[#bca17d] text-white" : "text-white/50 hover:text-white"}`}>Keterangan</button>
        </div>

        {activeTab === "transaction" ? (
          <div className="mt-3 grid items-start gap-3 lg:grid-cols-[1fr_360px]">
            <form id="checkout-form" onSubmit={requestConfirmation} className="space-y-3">
              {!isVoucherProduct && (
              <section className="overflow-hidden rounded-lg border border-white/[0.10] bg-[#2f3338]">
                <div className="flex items-center justify-between gap-3 border-b border-white/[0.08] bg-white/[0.025] px-3 py-2.5 sm:px-4">
                  <StepTitle
                    number="1"
                    title="Masukkan Data Akun"
                    description="Isi ID tujuan dengan benar. Nickname diperiksa otomatis jika didukung."
                  />
                  <div className="hidden items-center gap-2 sm:flex">
                    <span className="block size-9 overflow-hidden rounded-lg aspect-square">
                      <ProductArtwork product={product} compact />
                    </span>
                    <div className="max-w-36">
                      <strong className="block truncate text-[11px]">{product.name}</strong>
                      <Link href="/catalog" className="mt-0.5 block text-[9px] font-semibold text-[#cfff72]">Ganti produk</Link>
                    </div>
                  </div>
                </div>
                <div className="p-3 sm:p-4">
                  <div className="mb-3 flex items-center gap-2 sm:hidden">
                    <span className="block size-9 overflow-hidden rounded-lg aspect-square"><ProductArtwork product={product} compact /></span>
                    <div className="min-w-0 flex-1">
                      <strong className="block truncate text-[11px]">{product.name}</strong>
                      <Link href="/catalog" className="mt-0.5 block text-[9px] font-semibold text-[#cfff72]">Ganti produk</Link>
                    </div>
                  </div>

                  {savedGameAccounts.length > 0 && (
                    <label className="mb-3 block rounded-lg border border-[#b9ff35]/18 bg-[#b9ff35]/[0.045] p-2.5">
                      <span className="block text-[10px] font-bold text-[#d8ff8d]">
                        Akun game tersimpan
                      </span>
                      <select
                        value={selectedSavedGameAccountId}
                        onChange={(event) => chooseSavedGameAccount(event.target.value)}
                        className="mt-1.5 h-9 w-full rounded-lg border border-white/10 bg-[#171c27] px-2.5 text-[11px] text-white outline-none"
                      >
                        <option value="">Isi manual</option>
                        {savedGameAccounts.map((saved) => (
                          <option key={saved.id} value={saved.id}>
                            {saved.label}{saved.nickname ? ` · ${saved.nickname}` : ""}
                          </option>
                        ))}
                      </select>
                    </label>
                  )}

                  {productInputFields.length ? (
                    <div
                      data-lf-account-grid={productInputFields.length > 1 ? "true" : undefined}
                      className={productInputFields.length > 1 ? "grid gap-3 sm:grid-cols-2" : "grid gap-3"}
                    >
                      {productInputFields.map((field) => (
                        <Field key={field.id} label={`${field.label}${field.required === false ? " (opsional)" : ""}`}>
                          <Input
                            value={customerInputValues[field.id] ?? ""}
                            onChange={(event) => {
                              setCustomerInputValues((current) => ({ ...current, [field.id]: event.target.value }));
                              setSelectedSavedGameAccountId("");
                              setPayment(null);
                              setError("");
                            }}
                            placeholder={field.placeholder || `Masukkan ${field.label}`}
                            autoComplete="off"
                            className="checkout-input"
                          />
                        </Field>
                      ))}
                    </div>
                  ) : (
                    <p className="text-[10px] text-white/40">Produk ini tidak memerlukan data akun tambahan.</p>
                  )}

                  {canCheckNickname ? (
                    <NicknameResult state={visibleNickname} blocking={nicknameRequired} />
                  ) : (
                    <p className="mt-3 flex items-start gap-2 rounded-lg border border-white/[0.07] bg-white/[0.025] p-2.5 text-[10px] leading-4 text-white/40">
                      <Info className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />
                      Verifikasi nickname otomatis belum tersedia. Periksa kembali data sebelum membayar.
                    </p>
                  )}

                  {product.manualInstructions && (
                    <div className="mt-3 rounded-lg border border-amber-300/20 bg-amber-300/[0.06] p-2.5 text-[10px] leading-4 text-amber-100/70">
                      <strong className="mb-1 block text-amber-200">Instruksi produk manual</strong>
                      {product.manualInstructions}
                    </div>
                  )}
                </div>
              </section>
              )}

              <section className="rounded-lg border border-white/[0.10] bg-[#2f3338] p-3 sm:p-4">
                <StepTitle
                  number={isVoucherProduct ? "1" : "2"}
                  title="Pilih Nominal"
                  description={
                    isManual
                      ? "Pesanan diproses admin setelah pembayaran."
                      : isVoucherStock
                        ? "Satu kode stok dikirim otomatis setelah pembayaran."
                        : "Pesanan diteruskan otomatis ke provider."
                  }
                />
                {packageGroups.length > 1 && (
                  <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
                    {packageGroups.map((group) => (
                      <button
                        key={group}
                        type="button"
                        onClick={() => choosePackageGroup(group)}
                        className={
                          group === activePackageGroup
                            ? "shrink-0 rounded-lg border border-[#bca17d] bg-[#bca17d] px-3 py-2 text-[10px] font-black text-white"
                            : "shrink-0 rounded-lg border border-white/[0.10] bg-white/[0.035] px-3 py-2 text-[10px] font-bold text-white/55 transition hover:border-white/20 hover:text-white"
                        }
                      >
                        {group}
                      </button>
                    ))}
                  </div>
                )}
                <div className={`${packageGroups.length > 1 ? "mt-2" : "mt-3"} grid grid-cols-2 gap-2 sm:grid-cols-3`}>
                  {visiblePackages.map((item) => {
                    const ready = isManual || Boolean(item.providerCode && item.providerSku);
                    return (
                      <button
                        key={item.id}
                        type="button"
                        onClick={() => choosePackage(item.id)}
                        className={`relative min-h-[72px] rounded-lg border px-3 py-2.5 text-left transition ${packageId === item.id ? "border-[#b9ff35] bg-[#b9ff35]/10 shadow-[inset_0_0_0_1px_rgba(185,255,53,.18)]" : "border-white/[0.09] bg-white/[0.025] hover:border-white/20"}`}
                      >
                        <div className="flex items-start justify-between gap-2">
                          <strong className="min-w-0 flex-1 text-[11px] leading-4 sm:text-xs">{item.label}</strong>
                          {item.note && (
                            <span className="shrink-0 rounded bg-[#b9ff35] px-1.5 py-0.5 text-[7px] font-black uppercase text-[#091006]">{item.note}</span>
                          )}
                        </div>
                        <span className="mt-1.5 block text-[10px] font-black text-[#cfff72] sm:text-[11px]">{formatRupiah(item.price)}</span>
                        {!ready && <span className="mt-1 block text-[8px] font-semibold text-amber-300/70">SKU belum diatur</span>}
                      </button>
                    );
                  })}
                </div>
              </section>

              <section className="rounded-lg border border-white/[0.10] bg-[#2f3338] p-3 sm:p-4">
                <StepTitle
                  number={isVoucherProduct ? "2" : "3"}
                  title="Pilih Pembayaran"
                  description="Pilih metode pembayaran yang ingin digunakan."
                />
                {isIpaymuMinimumBlocked && (
                  <div className="mt-3 rounded-md border border-amber-300/15 bg-amber-300/[0.05] px-2.5 py-2 text-[9px] leading-4 text-amber-100/70">
                    {unavailablePaymentMessage}
                  </div>
                )}
                {!paymentMethodsLoaded && (
                  <div className="mt-3 flex items-center gap-2 rounded-md border border-white/[0.08] bg-white/[0.025] px-3 py-2 text-[10px] text-white/45">
                    <LoaderCircle className="size-3.5 animate-spin" />
                    Memuat metode pembayaran…
                  </div>
                )}
                {paymentMethodsLoaded && !hasExternalPaymentOption && !isIpaymuMinimumBlocked && (
                  <div className="mt-3 rounded-md border border-amber-300/20 bg-amber-300/[0.05] px-3 py-2 text-[10px] leading-4 text-amber-100/75">
                    Pembayaran melalui gateway belum tersedia. Kamu masih bisa memakai Koin LFAMILIA bila saldo mencukupi.
                  </div>
                )}
                <div className="mt-3 space-y-2">
                  {checkoutGroups.map((group) => {
                    const Icon = groupIcons[group.code];
                    const selected = paymentMethod === group.code;
                    const disabled = group.code === "wallet" && !account;
                    return (
                      <div
                        key={group.code}
                        className={`overflow-hidden rounded-lg border transition ${selected ? "border-[#b9ff35]/60 bg-[#b9ff35]/[0.06]" : "border-white/[0.09] bg-white/[0.025]"}`}
                      >
                        <button
                          type="button"
                          disabled={disabled}
                          onClick={() => chooseMethod(group.code)}
                          className="flex w-full items-center gap-2.5 px-3 py-2.5 text-left disabled:cursor-not-allowed disabled:opacity-45"
                        >
                          <span className={`grid size-8 shrink-0 place-items-center rounded-lg ${selected ? "bg-[#b9ff35] text-[#091006]" : "bg-white/[0.06] text-white/55"}`}>
                            <Icon className="size-3.5" />
                          </span>
                          <span className="min-w-0 flex-1">
                            <strong className="block text-[11px] sm:text-xs">{group.name}</strong>
                            <span className="mt-0.5 block truncate text-[9px] text-white/40">
                              {group.code === "wallet" && account
                                ? `Saldo ${formatRupiah(account.balance)}`
                                : group.code === "wallet"
                                  ? "Masuk akun untuk memakai saldo"
                                  : group.description}
                            </span>
                          </span>
                          {selected && <CheckCircle2 className="size-4 shrink-0 text-[#b9ff35]" />}
                        </button>

                        {group.code !== "wallet" && (
                          <div className="flex min-h-8 items-center gap-2 overflow-hidden border-t border-white/[0.08] bg-black/10 px-3 py-1.5">
                            {group.code === "qris" ? (
                              <span className="truncate text-[9px] font-bold text-white/65">QRIS • DANA • GoPay • ShopeePay • OVO</span>
                            ) : (
                              displayChannels
                                .filter((channel) => channel.method === group.code)
                                .slice(0, 7)
                                .map((channel) =>
                                  channel.imageUrl ? (
                                    <img key={channel.channel} src={channel.imageUrl} alt={channel.name} className="h-4 max-w-12 object-contain" />
                                  ) : (
                                    <span key={channel.channel} className="rounded bg-white/[0.08] px-1.5 py-0.5 text-[7px] font-bold text-white/65">{channel.name}</span>
                                  ),
                                )
                            )}
                          </div>
                        )}

                        {selected && isGatewayMethod && group.code !== "qris" && (
                          <div className="grid grid-cols-2 gap-2 border-t border-white/[0.08] bg-black/10 p-2.5 sm:grid-cols-3">
                            {displayChannels
                              .filter((channel) => channel.method === group.code)
                              .map((channel) => (
                                <button
                                  key={channel.channel}
                                  type="button"
                                  onClick={() => {
                                    setPaymentChannel(channel.channel);
                                    setPayment(null);
                                  }}
                                  className={`flex min-h-[44px] items-center gap-2 rounded-lg border px-2.5 py-2 text-left text-[9px] font-bold transition ${paymentChannel === channel.channel ? "border-[#b9ff35]/60 bg-[#b9ff35]/[0.10] text-[#d8ff8d]" : "border-white/[0.08] bg-white/[0.02] text-white/45 hover:text-white"}`}
                                >
                                  {channel.imageUrl ? (
                                    <img src={channel.imageUrl} alt="" className="size-7 rounded-md object-contain" />
                                  ) : (
                                    <span className="grid size-7 place-items-center rounded-md bg-white/[0.07] text-[7px] font-black text-white/80">{channel.name.slice(0, 3)}</span>
                                  )}
                                  <span className="truncate">{channel.name}</span>
                                </button>
                              ))}
                          </div>
                        )}

                      </div>
                    );
                  })}
                </div>
                {!account && (
                  <p className="mt-2.5 text-[9px] text-white/40">
                    Ingin membayar memakai saldo? <Link href="/login" className="font-bold text-[#cfff72]">Masuk atau daftar akun</Link>.
                  </p>
                )}
              </section>

              <section className="rounded-lg border border-white/[0.10] bg-[#2f3338] p-3 sm:p-4">
                <StepTitle
                  number={isVoucherProduct ? "3" : "4"}
                  title="Data Pembeli & Voucher"
                  description="Email dan WhatsApp digunakan untuk invoice serta status transaksi."
                />
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                  <Field label="Email">
                    <Input type="email" value={buyerEmail} onChange={(event) => setBuyerEmail(event.target.value)} placeholder="nama@email.com" className="checkout-input" />
                  </Field>
                  <Field label="Nomor WhatsApp">
                    <Input
                      inputMode="tel"
                      autoComplete="tel"
                      maxLength={17}
                      pattern="\+?[0-9]{8,16}"
                      value={contact}
                      onChange={(event) => setContact(normalizeWhatsapp(event.target.value))}
                      placeholder="081234567890"
                      className="checkout-input"
                    />
                  </Field>
                </div>
                <div className="mt-3 border-t border-white/[0.08] pt-3">
                  <span className="field-label">Kode voucher</span>
                  <div className="flex gap-2">
                    <Input
                      id="voucher-code"
                      value={voucherCode}
                      onChange={(event) => {
                        setVoucherCode(event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ""));
                        setVoucherMessage("");
                      }}
                      placeholder="Masukkan kode promo"
                      className="checkout-input font-mono uppercase"
                    />
                    <Button
                      type="button"
                      onClick={() => void applyVoucher()}
                      disabled={applyingVoucher || !packageId}
                      variant="outline"
                      className="h-9 shrink-0 rounded-lg border-white/10 bg-white/[0.04] px-3 text-[10px] text-white hover:bg-white/[0.08] hover:text-white"
                    >
                      {applyingVoucher ? <LoaderCircle className="size-4 animate-spin" /> : "Gunakan"}
                    </Button>
                  </div>
                  {voucherMessage && <p className={`mt-1.5 text-[9px] ${quote?.voucherCode ? "text-[#cfff72]" : "text-amber-200"}`}>{voucherMessage}</p>}
                </div>
                <p className="mt-2.5 flex items-start gap-1.5 text-[9px] leading-4 text-white/32">
                  <ShieldCheck className="mt-0.5 size-3 shrink-0" /> Kami hanya memakai kontak untuk invoice dan status transaksi.
                </p>
              </section>

              {error && (
                <div className="flex items-start gap-2 rounded-lg border border-red-400/30 bg-red-400/[0.08] p-3 text-[11px] leading-4 text-red-100">
                  <AlertCircle className="mt-0.5 size-4 shrink-0 text-red-300" />
                  <span><strong className="block text-red-200">Periksa kembali checkout</strong><span className="mt-0.5 block text-red-100/70">{error}</span></span>
                </div>
              )}

              <Button disabled={submitting} type="submit" className="hidden h-10 w-full rounded-lg bg-[#bca17d] font-black text-white hover:bg-[#d1b18b]">
                {submitting ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <LockKeyhole className="mr-2 size-4" />}
                Pesan Sekarang
              </Button>
            </form>

            <aside className="hidden panel p-3 lg:sticky lg:top-24 lg:block">
              <div className="flex items-center justify-between gap-3">
                <h2 className="text-sm font-black">Ringkasan Pesanan</h2>
                <span className={`rounded-full px-2 py-0.5 text-[8px] font-black uppercase ${isManual ? "bg-amber-400/10 text-amber-300" : "bg-[#b9ff35]/10 text-[#d8ff8d]"}`}>
                  {isManual ? "Manual" : "Otomatis"}
                </span>
              </div>
              <div className="my-3 h-px bg-white/[0.08]" />
              <dl className="space-y-2.5 text-[11px]">
                <SummaryRow label="Produk" value={product.name} />
                {visibleNickname.nickname && <SummaryRow label="Nickname" value={visibleNickname.nickname} highlight />}
                <SummaryRow label="Nominal" value={selectedPackage?.label ?? "Belum dipilih"} />
                <SummaryRow label="Harga" value={formatRupiah(subtotal)} />
                {quote && quote.sellingPrice < quote.basePrice && <SummaryRow label="Harga promo" value={`-${formatRupiah(quote.basePrice - quote.sellingPrice)}`} highlight />}
                {quote && quote.discountAmount > 0 && <SummaryRow label={`Voucher ${quote.voucherCode ?? ""}`} value={`-${formatRupiah(quote.discountAmount)}`} highlight />}
                <SummaryRow
                  label="Biaya layanan"
                  value={paymentMethod === "wallet" ? formatRupiah(0) : payment ? formatRupiah(payment.fee) : "Dihitung otomatis"}
                />
                <SummaryRow
                  label="Proses"
                  value={isManual ? "Antrean admin" : isVoucherStock ? "Kirim kode otomatis" : selectedPackage?.providerCode || "Provider belum diatur"}
                />
              </dl>
              <div className="my-3 h-px bg-white/[0.08]" />
              <div className="flex items-end justify-between gap-3">
                <span className="text-xs font-bold">Total</span>
                <strong className="text-lg font-black text-[#b9ff35]">{formatRupiah(payment?.total ?? subtotal)}</strong>
              </div>
              <p className="mt-2 rounded-lg bg-white/[0.035] p-2.5 text-[8px] leading-4 text-white/35">Biaya layanan, jika ada, dihitung oleh channel yang dipilih dan ditampilkan sebelum pembayaran.</p>
              <Button form="checkout-form" disabled={submitting} type="submit" className="mt-3 hidden h-10 w-full rounded-lg bg-[#bca17d] font-black text-white hover:bg-[#d1b18b] lg:flex">
                {submitting ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <LockKeyhole className="mr-2 size-4" />}
                Pesan Sekarang
              </Button>
              <div className="mt-2.5 flex items-center justify-center gap-3 text-[8px] text-white/30">
                <span className="inline-flex items-center gap-1"><ShieldCheck className="size-3" /> Data aman</span>
                <span className="inline-flex items-center gap-1"><CreditCard className="size-3" /> Pembayaran terlindungi</span>
              </div>
              {payment && <PaymentBox payment={payment} />}
            </aside>
          </div>
        ) : (
          <section className="mt-3 space-y-3">
            <article className="rounded-lg border border-white/[0.10] bg-[#2f3338] p-3 sm:p-4"><h2 className="text-base font-black">Deskripsi {product.name}</h2><p className="mt-2 whitespace-pre-line text-xs leading-6 text-white/60">{(product as { description?: string }).description || `Top up ${product.name} cepat, aman, dan diproses otomatis setelah pembayaran berhasil.`}</p></article>
            <ProductReviews productSlug={product.slug} />
            <article className="rounded-lg border border-white/[0.10] bg-[#2f3338] p-3 sm:p-4"><h2 className="text-base font-black">Pertanyaan umum</h2><div className="mt-3 space-y-2">{["Bagaimana cara top up?","Metode pembayaran apa saja yang tersedia?","Berapa lama proses pesanan?","Apakah transaksi aman?"].map((question) => <details key={question} className="rounded-lg bg-white/[0.04] p-3"><summary className="cursor-pointer text-xs font-bold">{question}</summary><p className="pt-2 text-xs leading-5 text-white/55">Lengkapi data akun, pilih nominal dan metode pembayaran, lalu konfirmasi pesanan. Status transaksi dapat diperiksa setelah pembayaran dibuat.</p></details>)}</div></article>
          </section>
        )}
      </main>

      {activeTab === "transaction" && (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-white/10 bg-[#101217]/95 px-3 py-2.5 backdrop-blur lg:hidden">
          <div className="mx-auto max-w-xl">
            {summaryOpen ? (
              <div className="mb-2 rounded-lg border border-white/[0.12] bg-[#191b20] shadow-2xl">
                <button
                  type="button"
                  onClick={() => setSummaryOpen(false)}
                  aria-expanded="true"
                  className="flex w-full items-center gap-2.5 px-3 py-2.5 text-left"
                >
                  <span className="block size-9 shrink-0 overflow-hidden rounded-lg"><ProductArtwork product={product} compact /></span>
                  <span className="min-w-0 flex-1">
                    <strong className="block truncate text-[11px]">Ringkasan pesanan</strong>
                    <span className="block truncate text-[9px] text-white/45">{product.name} • {selectedPackage?.label ?? "Pilih nominal"}</span>
                  </span>
                  <strong className="shrink-0 text-xs text-[#cfff72]">{formatRupiah(subtotal)}</strong>
                  <ChevronDown className="size-4 shrink-0 text-white/55" />
                </button>
                <dl className="space-y-2 border-t border-white/10 px-3 py-2.5 text-[10px]">
                  <SummaryRow label="Harga" value={formatRupiah(subtotal)} />
                  <SummaryRow label="Jumlah" value="1" />
                  <SummaryRow label="Biaya" value={payment ? formatRupiah(payment.fee) : "Dihitung otomatis"} />
                  <SummaryRow label="Total Pembayaran" value={formatRupiah(payment?.total ?? subtotal)} highlight />
                </dl>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => setSummaryOpen(true)}
                aria-expanded="false"
                className="mb-2 flex w-full items-center justify-between rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 py-2 text-left"
              >
                <span className="min-w-0">
                  <strong className="block text-[11px]">Ringkasan pesanan</strong>
                  <span className="block text-[9px] text-white/40">Ketuk untuk melihat rincian</span>
                </span>
                <span className="ml-auto flex items-center gap-2 pl-3">
                  <strong className="text-xs text-[#cfff72]">{formatRupiah(subtotal)}</strong>
                  <ChevronUp className="size-4 text-white/60" />
                </span>
              </button>
            )}

            <Button form="checkout-form" type="submit" disabled={submitting} className="h-10 w-full rounded-lg bg-[#bca17d] font-black text-white hover:bg-[#d1b18b]">
              {submitting ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <LockKeyhole className="mr-2 size-4" />}
              Pesan Sekarang
            </Button>
          </div>
        </div>
      )}

      <Dialog open={confirmationOpen} onOpenChange={setConfirmationOpen}>
        <DialogContent className="max-w-md border-white/10 bg-[#191b20] text-white" showCloseButton={false}>
          <DialogHeader><div className="mx-auto grid size-14 place-items-center rounded-full bg-[#b9ff35]/15"><CheckCircle2 className="size-8 text-[#b9ff35]" /></div><DialogTitle className="pt-3 text-center text-lg font-black">Buat Pesanan</DialogTitle><DialogDescription className="text-center text-xs leading-5 text-white/55">{isVoucherProduct ? "Pastikan produk, nominal, dan pembayaran yang kamu pilih sudah sesuai." : "Pastikan data akun dan produk yang kamu pilih sudah valid dan sesuai."}</DialogDescription></DialogHeader>
          <dl className="rounded-xl bg-black/15 p-4 text-xs">{!isVoucherProduct && visibleNickname.nickname && <SummaryRow label="Username" value={visibleNickname.nickname} />}{!isVoucherProduct && productInputFields.map((field) => <SummaryRow key={field.id} label={field.label} value={(customerInputValues[field.id] ?? "").trim() || "-"} />)}<SummaryRow label="Item" value={selectedPackage?.label ?? "-"} /><SummaryRow label="Produk" value={product.name} /><SummaryRow label="Payment" value={checkoutGroups.find((item) => item.code === paymentMethod)?.name ?? "-"} /></dl>
          <label className="flex cursor-pointer items-start gap-3 text-xs leading-5 text-white/60"><input type="checkbox" checked={agreed} onChange={(event) => setAgreed(event.target.checked)} className="mt-0.5 size-4 accent-[#b9ff35]" />Dengan melanjutkan, saya menyetujui syarat & ketentuan yang berlaku.</label>
          <div className="grid grid-cols-2 gap-3"><Button type="button" onClick={() => { setConfirmationOpen(false); void submitOrder(); }} disabled={!agreed || submitting} className="bg-[#bca17d] font-black text-white hover:bg-[#d1b18b]">{submitting ? "Memproses..." : "Pesan Sekarang"}</Button><Button type="button" variant="outline" onClick={() => setConfirmationOpen(false)} className="border-white/10 bg-white/[0.04] text-white hover:bg-white/[0.08] hover:text-white">Batalkan</Button></div>
        </DialogContent>
      </Dialog>

      <Dialog
        open={noticeOpen}
        onOpenChange={(open) => {
          if (!open) closeNotice();
          else setNoticeOpen(true);
        }}
      >
        <DialogContent className="max-w-lg overflow-hidden border-white/10 bg-[#080b14] p-0 text-white" showCloseButton={false}>
          {notices.length > 0 && (
            <>
              <div className="flex items-center justify-between border-b border-white/[0.08] px-6 py-4">
                <span className="font-mono text-xs text-white/40">{noticeIndex + 1}/{notices.length}</span>
                <button type="button" onClick={closeNotice} className="rounded-lg p-1.5 text-white/55 hover:bg-white/[0.06] hover:text-white" aria-label="Tutup informasi"><X className="size-5" /></button>
              </div>
              <div className="px-6 py-6">
                <DialogHeader>
                  <DialogTitle className="text-left text-lg font-black uppercase leading-7">{formatNotice(notices[noticeIndex].title, product)}</DialogTitle>
                  <DialogDescription className="whitespace-pre-line text-left text-sm leading-7 text-white/64">{formatNotice(notices[noticeIndex].body, product)}</DialogDescription>
                </DialogHeader>
                {notices.length > 1 && (
                  <div className="mt-4 flex items-center justify-between gap-3">
                    <Button type="button" variant="outline" disabled={noticeIndex === 0} onClick={() => setNoticeIndex((value) => Math.max(0, value - 1))} className="border-white/10 bg-white/[0.03] text-white">Sebelumnya</Button>
                    <Button type="button" disabled={noticeIndex === notices.length - 1} onClick={() => setNoticeIndex((value) => Math.min(notices.length - 1, value + 1))} className="bg-[#b9ff35] text-[#091006]">Berikutnya</Button>
                  </div>
                )}
              </div>
              <label className="flex cursor-pointer items-center gap-3 border-t border-white/[0.08] px-6 py-5 text-xs text-white/45">
                <input type="checkbox" checked={hideNotice} onChange={(event) => setHideNotice(event.target.checked)} className="size-4 accent-[#b9ff35]" />
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
  number,
  title,
  description,
}: {
  number: string;
  title: string;
  description: string;
}) {
  return (
    <div className="flex items-start gap-2.5">
      <span className="grid size-7 shrink-0 place-items-center rounded-md bg-[#bca17d] text-xs font-black text-white">{number}</span>
      <div className="min-w-0">
        <h2 className="text-[12px] font-black leading-4 sm:text-[13px]">{title}</h2>
        <p className="mt-0.5 text-[9px] leading-4 text-white/45 sm:text-[10px]">{description}</p>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
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
      <dd className={`max-w-52 truncate text-right font-semibold ${highlight ? "text-[#cfff72]" : ""}`}>{value}</dd>
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
  return (
    <div className="mt-4 rounded-lg border border-[#b9ff35]/30 bg-[#b9ff35]/[0.08] p-3">
      <BadgeCheck className="size-5 text-[#b9ff35]" />
      <h3 className="mt-2 text-xs font-black">{payment.paymentStatus === "paid" ? "Pembayaran berhasil" : "Pembayaran dibuat"}</h3>
      <p className="mt-1 break-all text-[9px] text-white/45">{payment.referenceId}</p>
      {payment.paymentNo && (
        <div className="mt-2.5 rounded-lg bg-black/20 p-2.5">
          <span className="text-[8px] uppercase tracking-wider text-white/35">{payment.paymentName || "Nomor pembayaran"}</span>
          <div className="mt-1 flex items-center justify-between gap-2">
            <strong className="break-all text-xs text-[#d8ff8d]">{payment.paymentNo}</strong>
            <button type="button" onClick={() => void navigator.clipboard.writeText(payment.paymentNo!)} className="shrink-0 text-white/45 hover:text-white" aria-label="Salin nomor pembayaran"><Copy className="size-4" /></button>
          </div>
        </div>
      )}
      {payment.balanceAfter != null && <p className="mt-2.5 rounded-lg bg-black/20 p-2.5 text-[9px] text-white/55">Sisa saldo: <strong className="text-[#d8ff8d]">{formatRupiah(payment.balanceAfter)}</strong></p>}
      {payment.expiredAt && <p className="mt-2.5 text-[8px] text-white/35">Berlaku sampai {payment.expiredAt}</p>}
      {payment.paymentUrl && (
        <Button asChild className="mt-3 w-full rounded-lg bg-[#bca17d] font-black text-white hover:bg-[#d1b18b]"><a href={payment.paymentUrl} target="_blank" rel="noreferrer">Lanjut bayar <ExternalLink className="ml-2 size-4" /></a></Button>
      )}
      <p className="mt-2.5 flex items-start gap-2 text-[8px] leading-4 text-white/38">
        {payment.fulfillmentType === "automatic" ? <Zap className="mt-0.5 size-3 shrink-0 text-[#b9ff35]" /> : <Info className="mt-0.5 size-3 shrink-0 text-amber-300" />}
        {fulfillmentMessage}
      </p>
    </div>
  );
}

function NicknameResult({
  state,
  blocking,
}: {
  state: NicknameState;
  blocking: boolean;
}) {
  if (state.status === "loading")
    return (
      <div className="mt-3 flex items-center gap-2.5 rounded-lg border border-[#b9ff35]/15 bg-[#b9ff35]/[0.05] p-2.5 text-[10px] text-white/55">
        <LoaderCircle className="size-4 animate-spin text-[#b9ff35]" />
        Memeriksa ID dan Server…
      </div>
    );
  if (state.status === "success")
    return (
      <div className="mt-3 flex items-start gap-2.5 rounded-lg border border-emerald-400/30 bg-emerald-400/[0.08] p-2.5">
        <BadgeCheck className="mt-0.5 size-4 shrink-0 text-emerald-400" />
        <div>
          <p className="text-[8px] font-bold uppercase tracking-[0.14em] text-emerald-300/70">Akun ditemukan</p>
          <strong className="mt-0.5 block break-words text-xs text-emerald-200">{state.nickname}</strong>
          {state.country && <p className="mt-0.5 text-[9px] text-emerald-100/55">dari {state.country}{state.country.toLowerCase() === "indonesia" ? " 🇮🇩" : ""}</p>}
        </div>
      </div>
    );
  if (state.status === "error")
    return (
      <div className={`mt-3 flex items-start gap-2.5 rounded-lg border p-2.5 ${blocking ? "border-red-400/30 bg-red-400/[0.08]" : "border-amber-300/20 bg-amber-300/[0.05]"}`}>
        <AlertCircle className={`mt-0.5 size-4 shrink-0 ${blocking ? "text-red-300" : "text-amber-300"}`} />
        <div>
          <p className={`text-[10px] font-bold ${blocking ? "text-red-200" : "text-amber-200"}`}>
            {blocking ? "Akun belum terverifikasi" : "Nickname belum terverifikasi"}
          </p>
          <p className={`mt-0.5 text-[9px] leading-4 ${blocking ? "text-red-100/60" : "text-amber-100/60"}`}>
            {state.message}
          </p>
        </div>
      </div>
    );
  return (
    <div className="mt-3 flex items-start gap-2 rounded-lg border border-white/[0.07] bg-white/[0.025] p-2.5 text-[9px] leading-4 text-white/40">
      <Info className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />
      {blocking
        ? "Nickname akan tampil otomatis setelah User ID dan Server yang diperlukan terisi."
        : "Nickname akan diperiksa otomatis jika layanan verifikasi tersedia."}
    </div>
  );
}
