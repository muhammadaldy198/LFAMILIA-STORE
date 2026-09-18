"use client";
import { FormEvent, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { QRCodeSVG } from "qrcode.react";
import {
  ArrowUpRight,
  CheckCircle2,
  ExternalLink,
  Copy,
  LoaderCircle,
  LogOut,
  Menu,
  PackageCheck,
  ReceiptText,
  ShieldCheck,
  UserRound,
  WalletCards,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import type { CustomerSession } from "@/lib/server/customer-auth";
import { formatRupiah } from "@/lib/store-data";
import { CustomerSupport } from "@/components/customer-support";
import { CustomerGameAccounts } from "@/components/customer-game-accounts";
import { CustomerAuthForm } from "@/components/customer-auth-form";

type PublicWalletSettings = {
  enabled: boolean;
  minimumAmount: number;
};

type PublicWalletChannel = {
  method: "qris" | "va" | "ewallet";
  channel: string;
  name: string;
  description: string;
  imageUrl?: string;
};

type AccountData = {
  customer: CustomerSession;
  topups: Array<{
    id: string;
    amount: number;
    sender_name: string;
    payment_method: string;
    proof_url: string;
    status: string;
    admin_notes: string | null;
    created_at: string;
  }>;
  transactions: Array<{
    id: string;
    direction: "credit" | "debit";
    amount: number;
    balance_after: number;
    description: string;
    created_at: string;
  }>;
  orders: Array<{
    id: string;
    reference_id: string;
    product_name: string;
    package_label: string;
    total: number;
    payment_status: string;
    fulfillment_status: string;
    created_at: string;
  }>;
  vouchers: Array<{
    id: number;
    referenceId: string;
    productName: string;
    packageLabel: string;
    code: string;
    deliveredAt: string | null;
  }>;
};

export function CustomerAccount({
  initialMode = "login",
  initialError = "",
}: {
  initialMode?: "login" | "register";
  initialError?: string;
}) {
  const [account, setAccount] = useState<AccountData | null>(null);
  const [settings, setSettings] = useState<PublicWalletSettings | null>(null);
  const [topupChannels, setTopupChannels] = useState<PublicWalletChannel[]>([]);
  const [loading, setLoading] = useState(true);
  const [mode, setMode] = useState<"login" | "register">(initialMode);
  const [error, setError] = useState(initialError);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [accountResponse, walletResponse] = await Promise.all([
        fetch("/api/account", { cache: "no-store" }),
        fetch("/api/wallet", { cache: "no-store" }),
      ]);
      const walletData = await walletResponse.json().catch(() => ({})) as {
        settings?: PublicWalletSettings;
        channels?: PublicWalletChannel[];
      };
      setSettings(walletData.settings ?? null);
      setTopupChannels(walletData.channels ?? []);
      if (accountResponse.ok) {
        const accountData = await accountResponse.json().catch(() => null) as AccountData | null;
        setAccount(accountData);
      } else {
        setAccount(null);
      }
    } catch {
      setAccount(null);
      setSettings(null);
      setTopupChannels([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  if (loading)
    return (
      <div className="flex min-h-[55vh] items-center justify-center text-xs text-white/40">
        <LoaderCircle className="mr-2 size-4 animate-spin" />
        Memuat akun…
      </div>
    );
  if (!account)
    return (
      <AuthPanel
        mode={mode}
        setMode={setMode}
        error={error}
        setError={setError}
        onSuccess={load}
      />
    );
  return (
    <Dashboard
      data={account}
      settings={settings}
      topupChannels={topupChannels}
      reload={load}
      onLogout={async () => {
        await fetch("/api/auth/logout", { method: "POST" });
        setAccount(null);
      }}
    />
  );
}

function AuthPanel({
  mode,
  setMode,
  error,
  setError,
  onSuccess,
}: {
  mode: "login" | "register";
  setMode(value: "login" | "register"): void;
  error: string;
  setError(value: string): void;
  onSuccess(): Promise<void>;
}) {
  return (
    <CustomerAuthForm
      mode={mode}
      setMode={setMode}
      error={error}
      setError={setError}
      onSuccess={onSuccess}
    />
  );
}
function Dashboard({
  data,
  settings,
  topupChannels,
  reload,
  onLogout,
}: {
  data: AccountData;
  settings: PublicWalletSettings | null;
  topupChannels: PublicWalletChannel[];
  reload(): Promise<void>;
  onLogout(): Promise<void>;
}) {
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [tab, setTab] = useState("overview");
  const [menuOpen, setMenuOpen] = useState(false);
  const tabs = [
    ["overview", "Ringkasan"],
    ["wallet", "Saldo"],
    ["orders", "Pesanan"],
    ["vouchers", "Kode"],
    ["game-accounts", "Akun Game"],
    ["notifications", "Notifikasi"],
    ["support", "Bantuan"],
    ["profile", "Profil"],
  ] as const;
  return (
    <div>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="eyebrow">Dashboard pelanggan</p>
          <h1 className="section-title">
            Halo, {data.customer.name.split(" ")[0]}
          </h1>
          <p className="mt-2 text-xs text-white/38">{data.customer.email}</p>
        </div>
        <div className="flex gap-2">
          <Button
            type="button"
            onClick={() => setMenuOpen(true)}
            variant="outline"
            className="border-white/10 bg-white/[0.03] text-white lg:hidden"
          >
            <Menu className="size-4" />
          </Button>
          <Button
            onClick={() => void onLogout()}
            variant="outline"
            className="w-fit border-white/10 bg-white/[0.03] text-white"
          >
            <LogOut className="mr-2 size-4" />
            Keluar
          </Button>
        </div>
      </div>
      <div className="mt-6 grid gap-4 sm:grid-cols-3">
        <Stat
          icon={WalletCards}
          label="Saldo aktif"
          value={formatRupiah(data.customer.balance)}
        />
        <Stat
          icon={ReceiptText}
          label="Total pesanan"
          value={String(data.orders.length)}
        />
        <Stat
          icon={CheckCircle2}
          label="Top up menunggu"
          value={String(
            data.topups.filter((item) => item.status === "pending").length,
          )}
        />
      </div>
      {message && (
        <p className="mt-4 rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">
          {message}
        </p>
      )}
      {error && (
        <p className="mt-4 rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">
          {error}
        </p>
      )}
      <Tabs
        value={tab}
        onValueChange={setTab}
        className="mt-6 grid items-start gap-5 lg:grid-cols-[220px_1fr]"
      >
        {menuOpen && (
          <>
            <button
              type="button"
              onClick={() => setMenuOpen(false)}
              className="fixed inset-0 z-40 bg-black/70 lg:hidden"
              aria-label="Tutup menu"
            />
            <aside className="fixed inset-y-0 left-0 z-50 w-[82vw] max-w-72 overflow-y-auto border-r border-white/[0.1] bg-[#0d1019] p-3 shadow-2xl lg:hidden">
              <div className="mb-4 flex items-center justify-between px-2 pt-1">
                <p className="text-xs font-black uppercase tracking-[0.18em] text-[#c9ff70]">
                  Menu akun
                </p>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  onClick={() => setMenuOpen(false)}
                  className="size-9 text-white/70 hover:bg-white/10 hover:text-white"
                >
                  <X className="size-4" />
                </Button>
              </div>
              <div className="space-y-1">
                {tabs.map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => {
                      setTab(value);
                      setMenuOpen(false);
                    }}
                    className={`flex h-11 w-full items-center rounded-xl px-3 text-left text-sm font-medium transition ${tab === value ? "bg-[#b9ff35] text-[#091006]" : "text-white/65 hover:bg-white/[0.08] hover:text-white"}`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </aside>
          </>
        )}
        <TabsList className="hidden h-auto w-full gap-1 rounded-2xl border border-white/[0.08] bg-[#0d1019] p-2 lg:sticky lg:top-28 lg:!flex lg:!w-full lg:!flex-col lg:!items-stretch">
          <TabsTrigger value="overview" className="rounded-xl px-4 text-xs">
            Ringkasan
          </TabsTrigger>
          <TabsTrigger value="wallet" className="rounded-xl px-4 text-xs">
            Saldo
          </TabsTrigger>
          <TabsTrigger value="orders" className="rounded-xl px-4 text-xs">
            Pesanan
          </TabsTrigger>
          <TabsTrigger value="vouchers" className="rounded-xl px-4 text-xs">
            Kode
          </TabsTrigger>
          <TabsTrigger value="game-accounts" className="rounded-xl px-4 text-xs">
            Akun Game
          </TabsTrigger>
          <TabsTrigger
            value="notifications"
            className="rounded-xl px-4 text-xs"
          >
            Notifikasi
          </TabsTrigger>
          <TabsTrigger value="support" className="rounded-xl px-4 text-xs">
            Bantuan
          </TabsTrigger>
          <TabsTrigger value="profile" className="rounded-xl px-4 text-xs">
            Profil
          </TabsTrigger>
        </TabsList>
        <div className="min-w-0">
          <TabsContent value="overview" className="mt-0">
            <CustomerOverview
              data={data}
              onOpenWallet={() => setTab("wallet")}
            />
          </TabsContent>
          <TabsContent value="wallet" className="mt-0">
            <div className="grid gap-5 lg:grid-cols-[.9fr_1.1fr]">
              <TopupForm
                settings={settings}
                channels={topupChannels}
                onDone={async () => {
                  setMessage(
                    "Pembayaran top up berhasil dibuat. Selesaikan pembayaran agar saldo masuk otomatis.",
                  );
                  await reload();
                }}
                onError={setError}
              />
              <History title="Riwayat saldo" empty="Belum ada mutasi saldo.">
                {data.transactions.map((item) => (
                  <HistoryRow
                    key={item.id}
                    title={item.description}
                    detail={new Date(item.created_at).toLocaleString("id-ID")}
                    value={`${item.direction === "credit" ? "+" : "-"}${formatRupiah(item.amount)}`}
                    tone={item.direction === "credit" ? "good" : "normal"}
                  />
                ))}
              </History>
              <History
                title="Permintaan top up"
                empty="Belum ada permintaan top up."
              >
                {data.topups.map((item) => (
                  <HistoryRow
                    key={item.id}
                    title={`${item.payment_method} • ${item.sender_name}`}
                    detail={`${new Date(item.created_at).toLocaleString("id-ID")} • ${statusLabel(item.status)}`}
                    value={formatRupiah(item.amount)}
                    tone={
                      item.status === "approved"
                        ? "good"
                        : item.status === "rejected"
                          ? "bad"
                          : "warn"
                    }
                  />
                ))}
              </History>
            </div>
          </TabsContent>
          <TabsContent value="orders" className="mt-0">
            <History
              title="Riwayat pesanan"
              empty="Belum ada pesanan yang terhubung dengan akun ini."
            >
              {data.orders.map((item) => (
                <Link
                  key={item.id}
                  href={`/track?invoice=${item.reference_id}`}
                  className="block"
                >
                  <HistoryRow
                    title={`${item.product_name} • ${item.package_label}`}
                    detail={`${item.reference_id} • ${item.fulfillment_status}`}
                    value={formatRupiah(item.total)}
                    tone={item.payment_status === "paid" ? "good" : "warn"}
                  />
                </Link>
              ))}
            </History>
          </TabsContent>
          <TabsContent value="vouchers" className="mt-0">
            <VoucherCodes items={data.vouchers} />
          </TabsContent>
          <TabsContent value="game-accounts" className="mt-0">
            <CustomerGameAccounts />
          </TabsContent>
          <TabsContent value="notifications" className="mt-0">
            <CustomerNotifications data={data} />
          </TabsContent>
          <TabsContent value="support" className="mt-0">
            <CustomerSupport />
          </TabsContent>
          <TabsContent value="profile" className="mt-0">
            <ProfileForm
              customer={data.customer}
              onDone={async () => {
                setMessage("Profil berhasil diperbarui.");
                await reload();
              }}
              onError={setError}
            />
          </TabsContent>
        </div>
      </Tabs>
    </div>
  );
}

function CustomerOverview({
  data,
  onOpenWallet,
}: {
  data: AccountData;
  onOpenWallet(): void;
}) {
  const latest = data.orders.slice(0, 3);
  return (
    <div className="grid gap-5 xl:grid-cols-[1.35fr_.65fr]">
      <section className="rounded-2xl border border-[#b9ff35]/15 bg-gradient-to-br from-[#b9ff35]/10 via-[#10151b] to-[#0d1019] p-6">
        <span className="grid size-10 place-items-center rounded-xl bg-[#b9ff35] text-[#091006]">
          <WalletCards className="size-5" />
        </span>
        <h2 className="mt-5 text-xl font-black">
          Belanja lebih cepat dengan saldo
        </h2>
        <p className="mt-2 max-w-lg text-xs leading-5 text-white/42">
          Isi saldo sekali, lalu gunakan untuk checkout tanpa mengisi pembayaran
          berulang.
        </p>
        <div className="mt-5 flex flex-wrap gap-3">
          <button
            type="button"
            onClick={onOpenWallet}
            className="inline-flex h-10 items-center rounded-xl bg-[#b9ff35] px-4 text-xs font-black text-[#091006]"
          >
            Isi saldo <ArrowUpRight className="ml-2 size-4" />
          </button>
          <Link
            href="/catalog"
            className="inline-flex h-10 items-center rounded-xl border border-white/10 bg-white/[0.04] px-4 text-xs font-bold text-white"
          >
            Belanja sekarang
          </Link>
        </div>
      </section>
      <section className="rounded-2xl border border-white/[0.08] bg-[#0d1019] p-5">
        <div className="flex items-center gap-3">
          <span className="grid size-9 place-items-center rounded-xl bg-blue-400/10 text-blue-300">
            <PackageCheck className="size-4" />
          </span>
          <div>
            <h2 className="text-sm font-bold">Pesanan aktif</h2>
            <p className="mt-1 text-[10px] text-white/35">
              Pantau status pesanan kapan saja.
            </p>
          </div>
        </div>
        <strong className="mt-6 block text-3xl font-black">
          {
            data.orders.filter(
              (item) =>
                !["success", "failed", "expired"].includes(
                  item.fulfillment_status,
                ),
            ).length
          }
        </strong>
        <span className="text-[10px] text-white/35">
          pesanan sedang berjalan
        </span>
      </section>
      <section className="rounded-2xl border border-white/[0.08] bg-[#0d1019] p-5 xl:col-span-2">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="font-bold">Pesanan terbaru</h2>
            <p className="mt-1 text-[10px] text-white/32">
              Klik transaksi untuk melihat detail dan status terkini.
            </p>
          </div>
          <Link href="/track" className="text-xs font-bold text-[#cfff72]">
            Cari pesanan
          </Link>
        </div>
        <div className="mt-4 grid gap-2 md:grid-cols-3">
          {latest.length ? (
            latest.map((item) => (
              <Link key={item.id} href={`/track?invoice=${item.reference_id}`}>
                <HistoryRow
                  title={item.product_name}
                  detail={`${item.package_label} • ${item.reference_id}`}
                  value={formatRupiah(item.total)}
                  tone={item.payment_status === "paid" ? "good" : "warn"}
                />
              </Link>
            ))
          ) : (
            <p className="rounded-xl border border-dashed border-white/10 py-7 text-center text-xs text-white/30 md:col-span-3">
              Belum ada pesanan. Pilih produk untuk mulai belanja.
            </p>
          )}
        </div>
      </section>
    </div>
  );
}

function VoucherCodes({ items }: { items: AccountData["vouchers"] }) {
  const [copied, setCopied] = useState<number | null>(null);
  return (
    <section className="rounded-2xl border border-white/[0.08] bg-[#0d1019] p-5">
      <h2 className="font-bold">Kode digital saya</h2>
      <p className="mt-1 text-[10px] text-white/35">
        Hanya kode dari pesanan lunas yang sudah terkirim ditampilkan di sini.
      </p>
      <div className="mt-4 space-y-3">
        {items.length ? (
          items.map((item) => (
            <div
              key={item.id}
              className="rounded-xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.04] p-4"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <strong className="block text-sm">{item.productName}</strong>
                  <span className="mt-1 block text-[10px] text-white/35">
                    {item.packageLabel} • {item.referenceId}
                  </span>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    void navigator.clipboard.writeText(item.code);
                    setCopied(item.id);
                  }}
                  className="inline-flex items-center rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2 text-xs font-bold text-white"
                >
                  <Copy className="mr-2 size-3.5" />
                  {copied === item.id ? "Tersalin" : "Salin"}
                </button>
              </div>
              <code className="mt-4 block break-all rounded-lg border border-white/[0.08] bg-black/25 p-3 text-xs text-[#d8ff8d]">
                {item.code}
              </code>
            </div>
          ))
        ) : (
          <p className="rounded-xl border border-dashed border-white/10 py-10 text-center text-xs text-white/30">
            Belum ada kode digital yang terkirim.
          </p>
        )}
      </div>
    </section>
  );
}

function CustomerNotifications({ data }: { data: AccountData }) {
  const items = [
    ...data.orders.map((item) => ({
      id: `order-${item.id}`,
      title: `${item.product_name} • ${item.package_label}`,
      detail: `Pesanan ${item.reference_id}: ${item.fulfillment_status}`,
      at: item.created_at,
    })),
    ...data.topups.map((item) => ({
      id: `topup-${item.id}`,
      title: `Top up ${formatRupiah(item.amount)}`,
      detail: `${statusLabel(item.status)} • ${item.payment_method}`,
      at: item.created_at,
    })),
  ].sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime());
  return (
    <section className="rounded-2xl border border-white/[0.08] bg-[#0d1019] p-5">
      <h2 className="font-bold">Notifikasi transaksi</h2>
      <p className="mt-1 text-[10px] text-white/35">
        Status terbaru pesanan dan top-up kamu.
      </p>
      <div className="mt-4 space-y-2">
        {items.length ? (
          items.map((item) => (
            <div
              key={item.id}
              className="rounded-xl border border-white/[0.07] bg-white/[0.02] p-3"
            >
              <strong className="block text-xs">{item.title}</strong>
              <p className="mt-1 text-[10px] text-white/42">{item.detail}</p>
              <p className="mt-2 text-[9px] text-white/25">
                {new Date(item.at).toLocaleString("id-ID")}
              </p>
            </div>
          ))
        ) : (
          <p className="rounded-xl border border-dashed border-white/10 py-10 text-center text-xs text-white/30">
            Belum ada notifikasi.
          </p>
        )}
      </div>
    </section>
  );
}

type TopupPayment = {
  referenceId?: string;
  paymentMethod?: "qris" | "va" | "ewallet";
  paymentNo?: string | null;
  qrContent?: string | null;
  paymentName?: string | null;
  paymentUrl?: string | null;
  expiredAt?: string | null;
};

function TopupForm({
  settings,
  onDone,
  onError,
}: {
  settings: WalletSettings | null;
  onDone(): Promise<void>;
  onError(value: string): void;
}) {
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState<"qris" | "va" | "ewallet">("qris");
  const [saving, setSaving] = useState(false);
  const [payment, setPayment] = useState<TopupPayment | null>(null);
  const [openingPayment, setOpeningPayment] = useState(false);

  const automaticReady = Boolean(settings?.enabled);

  function openTopupPayment() {
    if (!payment?.paymentUrl) return;
    setOpeningPayment(true);
    window.location.assign(payment.paymentUrl);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    onError("");
    setSaving(true);
    try {
      const channel = method === "qris" ? "mpm" : method === "va" ? "bca" : "dana";
      const response = await fetch("/api/account/topups", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ amount: Number(amount), paymentMethod: method, paymentChannel: channel }),
      });
      const data = await response.json() as TopupPayment & { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Pembayaran gagal dibuat.");
      setPayment(data);
      setAmount("");
      await onDone();
    } catch (reason) {
      onError(reason instanceof Error ? reason.message : "Top up gagal dibuat.");
    } finally {
      setSaving(false);
    }
  }

  if (!automaticReady) return <div className="rounded-xl border border-amber-300/20 bg-amber-300/[0.05] p-5"><h2 className="font-bold">Top up saldo belum tersedia</h2><p className="mt-2 text-xs leading-5 text-white/40">Pemilik belum mengaktifkan top up saldo otomatis.</p></div>;

  return <form onSubmit={submit} className="rounded-xl border border-white/[0.08] bg-[#0d1019] p-5">
    <h2 className="font-bold">Top up saldo otomatis</h2>
    <p className="mt-2 text-xs leading-5 text-white/40">Saldo masuk otomatis setelah pembayaran dikonfirmasi.</p>
    <div className="mt-4 rounded-lg border border-white/[0.08] bg-white/[0.02] px-3 py-2 text-[10px] leading-4 text-white/42">
      Pilih metode pembayaran yang ingin digunakan.
    </div>
    <div className="mt-4 grid grid-cols-3 gap-2">{(["qris","va","ewallet"] as const).map((item) => <button key={item} type="button" onClick={() => setMethod(item)} className={`rounded-lg border px-2 py-2 text-[10px] font-bold uppercase ${method === item ? "border-[#b9ff35] bg-[#b9ff35] text-[#091006]" : "border-white/10 text-white/50"}`}>{item === "va" ? "Bank VA" : item}</button>)}</div>
    <div className="mt-4"><Field label={`Nominal (min. ${formatRupiah(settings?.minimumAmount ?? 10_000)})`}><Input required type="number" min={settings?.minimumAmount ?? 10_000} value={amount} onChange={(event) => setAmount(event.target.value)} className="checkout-input" /></Field></div>
    <Button disabled={saving} className="mt-4 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006]">{saving ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <ArrowUpRight className="mr-2 size-4" />}Lanjut bayar</Button>
    {payment && (
      <div className="mt-4 rounded-lg border border-white/[0.08] bg-black/20 p-4">
        <strong className="text-xs">Pembayaran top up dibuat</strong>
        {payment.referenceId && <p className="mt-1 break-all text-[9px] text-white/40">{payment.referenceId}</p>}
        {payment.expiredAt && <p className="mt-2 text-[9px] text-white/35">Berlaku sampai {payment.expiredAt}</p>}
        {payment.qrContent && (
          <div className="mt-3 rounded-xl bg-white p-4 text-center">
            <QRCodeSVG value={payment.qrContent} size={210} level="M" className="mx-auto h-auto w-full max-w-[210px]" />
            <p className="mt-2 text-[9px] font-black text-[#091006]">Scan QRIS untuk top up</p>
          </div>
        )}
        {payment.paymentNo && (
          <div className="mt-3 rounded-lg border border-white/[0.08] bg-white/[0.025] p-3">
            <p className="text-[9px] text-white/35">{payment.paymentName || "Nomor pembayaran"}</p>
            <div className="mt-1 flex items-center justify-between gap-2">
              <code className="break-all text-sm font-black text-white">{payment.paymentNo}</code>
              <button type="button" onClick={() => void navigator.clipboard.writeText(payment.paymentNo!)} className="inline-flex items-center gap-1 text-[9px] font-bold text-[#d8ff8d]"><Copy className="size-3" />Salin</button>
            </div>
          </div>
        )}
        {payment.paymentUrl && (
          <Button
            type="button"
            onClick={openTopupPayment}
            disabled={openingPayment}
            className="mt-3 w-full rounded-lg bg-[#b9ff35] font-black text-[#091006]"
          >
            {openingPayment ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <ExternalLink className="mr-2 size-4" />}
            {openingPayment ? "Membuka pembayaran…" : `Lanjut ke ${payment.paymentName || "e-wallet"}`}
          </Button>
        )}
        <p className="mt-3 text-[9px] leading-4 text-white/35">
          Setelah pembayaran berhasil, saldo akan masuk otomatis.
        </p>
      </div>
    )}
  </form>;
}
function ProfileForm({
  customer,
  onDone,
  onError,
}: {
  customer: CustomerSession;
  onDone(): Promise<void>;
  onError(value: string): void;
}) {
  const [name, setName] = useState(customer.name);
  const [phone, setPhone] = useState(customer.phone);
  const [leaderboard, setLeaderboard] = useState(customer.leaderboardOptIn);
  const [saving, setSaving] = useState(false);
  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    onError("");
    try {
      const response = await fetch("/api/account", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          name,
          phone: phone.replace(/[\s()-]/g, ""),
          leaderboardOptIn: leaderboard,
        }),
      });
      const data = (await response.json()) as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Profil gagal disimpan.");
      await onDone();
    } catch (reason) {
      onError(
        reason instanceof Error ? reason.message : "Profil gagal disimpan.",
      );
    } finally {
      setSaving(false);
    }
  }
  return (
    <form
      onSubmit={submit}
      className="max-w-xl rounded-2xl border border-white/[0.08] bg-[#0d1019] p-5"
    >
      <h2 className="font-bold">Profil pelanggan</h2>
      <div className="mt-4 space-y-4">
        <Field label="Nama">
          <Input
            required
            value={name}
            onChange={(event) => setName(event.target.value)}
            className="checkout-input"
          />
        </Field>
        <Field label="Nomor WhatsApp">
          <Input
            required
            value={phone}
            onChange={(event) => setPhone(event.target.value)}
            className="checkout-input"
          />
        </Field>
        <label className="flex items-center justify-between gap-4 rounded-xl border border-white/[0.08] p-4">
          <span>
            <strong className="block text-xs">Tampil di leaderboard</strong>
            <span className="mt-1 block text-[9px] text-white/35">
              Nama ditampilkan secara ringkas, tanpa email atau nomor telepon.
            </span>
          </span>
          <Switch checked={leaderboard} onCheckedChange={setLeaderboard} />
        </label>
      </div>
      <Button
        disabled={saving}
        className="mt-4 rounded-xl bg-[#b9ff35] font-black text-[#091006]"
      >
        {saving && <LoaderCircle className="mr-2 size-4 animate-spin" />}Simpan
        profil
      </Button>
    </form>
  );
}
function Stat({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof UserRound;
  label: string;
  value: string;
}) {
  return (
    <div className="rounded-2xl border border-white/[0.08] bg-[#0d1019] p-5">
      <span className="grid size-9 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#cfff72]">
        <Icon className="size-4" />
      </span>
      <span className="mt-4 block text-[9px] uppercase tracking-wider text-white/30">
        {label}
      </span>
      <strong className="mt-1 block text-lg font-black">{value}</strong>
    </div>
  );
}
function History({
  title,
  empty,
  children,
}: {
  title: string;
  empty: string;
  children: React.ReactNode[];
}) {
  return (
    <section className="rounded-2xl border border-white/[0.08] bg-[#0d1019] p-5">
      <h2 className="font-bold">{title}</h2>
      <div className="mt-4 space-y-2">
        {children.length ? (
          children
        ) : (
          <p className="rounded-xl border border-dashed border-white/10 py-7 text-center text-xs text-white/30">
            {empty}
          </p>
        )}
      </div>
    </section>
  );
}
function HistoryRow({
  title,
  detail,
  value,
  tone,
}: {
  title: string;
  detail: string;
  value: string;
  tone: "good" | "bad" | "warn" | "normal";
}) {
  return (
    <div className="flex items-start justify-between gap-4 rounded-xl border border-white/[0.07] bg-white/[0.02] p-3">
      <div className="min-w-0">
        <strong className="block truncate text-xs">{title}</strong>
        <span className="mt-1 block text-[9px] text-white/30">{detail}</span>
      </div>
      <span
        className={`shrink-0 text-xs font-bold ${tone === "good" ? "text-[#cfff72]" : tone === "bad" ? "text-red-300" : tone === "warn" ? "text-amber-300" : "text-white/60"}`}
      >
        {value}
      </span>
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
function statusLabel(status: string) {
  return status === "approved"
    ? "Disetujui"
    : status === "rejected"
      ? "Ditolak"
      : "Menunggu";
}
