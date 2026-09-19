"use client";

import { useEffect, useState } from "react";
import type { LucideIcon } from "lucide-react";
import {
  BarChart3,
  Boxes,
  CalendarDays,
  Check,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  CircleDollarSign,
  CreditCard,
  FileText,
  Headphones,
  ImageIcon,
  Link2,
  Settings,
  ShoppingCart,
  Sparkles,
  UserCog,
  Users,
  WalletCards,
} from "lucide-react";

type Summary = {
  canViewFinance: boolean;
  todayMetrics: {
    paidRevenue: number | null;
    totalOrders: number;
    pendingPayments: number;
    failedOrders: number;
    activeProducts: number;
  };
  metrics: { customers: number; fulfilledOrders: number };
  chart: Array<{ day: string; orders: number; revenue: number | null }>;
  recentActivities: Array<{ id: string; action: string; target: string; createdAt: string }>;
  recentOrders: Array<{
    id: string;
    referenceId: string;
    buyerName: string;
    productName: string;
    packageLabel: string;
    paymentChannel: string;
    paymentStatus: string;
    fulfillmentStatus: string;
    total: number | null;
    createdAt: string;
  }>;
  topProducts: Array<{ slug: string; name: string; fulfilledOrders: number }>;
  integrations: {
    doku: { ready: boolean };
    digiflazz: { ready: boolean; balance: number | null; lastSyncAt: string | null };
    webhook: { ready: boolean };
  };
};

type DashboardIntegration = {
  id: string;
  name: string;
  ready: boolean;
  active: boolean;
  environment: string | null;
  status: string;
};

type AdminRole = "super_admin" | "admin" | "staff";
const roleRank = { staff: 0, admin: 1, super_admin: 2 } as const;

const featureCards = [
  { title: "Pesanan", Icon: FileText, target: "orders", minimumRole: "staff", items: ["Kelola pesanan & invoice", "Update status pesanan", "Callback log & notifikasi", "Proses manual / refund"] },
  { title: "Produk", Icon: Boxes, target: "products", minimumRole: "admin", items: ["Kategori game & layanan", "SKU provider", "Struktur nominal", "Jenis pengiriman", "Kelola produk voucher"] },
  { title: "Banner & Konten", Icon: ImageIcon, target: "content", minimumRole: "staff", items: ["Kelola banner utama", "Pop-up informasi", "Berita & pengumuman", "Ulasan pelanggan", "Halaman FAQ"] },
  { title: "Digiflazz", Icon: Link2, target: "digiflazz", minimumRole: "admin", items: ["Sinkronisasi pricelist", "Price Control", "Mapping SKU produk", "Monitor layanan", "Log API & history"] },
  { title: "Pembayaran", Icon: CreditCard, target: "payments", minimumRole: "admin", items: ["DOKU Direct API", "Midtrans Snap", "QRIS / VA / E-Wallet", "Callback otomatis", "Routing metode aktif"] },
  { title: "Pelanggan", Icon: Users, target: "customers", minimumRole: "admin", items: ["Data pelanggan", "Wallet & saldo", "Riwayat transaksi", "Status akun pelanggan"] },
  { title: "Promo", Icon: Sparkles, target: "promotions", minimumRole: "admin", items: ["Kode voucher", "Diskon checkout", "Promo member", "Event promo khusus"] },
  { title: "Layanan Pelanggan", Icon: Headphones, target: "support", minimumRole: "staff", items: ["Sistem tiket", "Status tiket pelanggan", "Prioritas penanganan", "Lampiran bukti transaksi"] },
  { title: "Laporan", Icon: BarChart3, target: "reports", minimumRole: "admin", items: ["Laporan penjualan", "Laporan profit", "Produk terlaris", "Riwayat transaksi", "Filter periode lengkap"] },
  { title: "Staff & Admin Akses", Icon: UserCog, target: "team", minimumRole: "super_admin", items: ["Role Super Admin", "Role Admin & Staff", "Akses sesuai role", "Log aktivitas admin"] },
  { title: "Pengaturan", Icon: Settings, target: "settings", minimumRole: "super_admin", items: ["Profil toko & kontak", "Logo & favicon", "Pengaturan keamanan", "Notifikasi sistem"] },
];

export function AdminOverview({ role, onNavigate }: { role: AdminRole; onNavigate?: (value: string) => void }) {
  const isStaff = role === "staff";
  const canExpectFinance = role === "super_admin";
  const visibleFeatureCards = featureCards.filter((item) => roleRank[role] >= roleRank[item.minimumRole as AdminRole]);
  const [now, setNow] = useState<Date | null>(null);
  const [range, setRange] = useState("7d");
  const [summary, setSummary] = useState<Summary | null>(null);
  const [integrationItems, setIntegrationItems] = useState<DashboardIntegration[]>([]);
  const [error, setError] = useState("");

  useEffect(() => {
    const initial = window.setTimeout(() => setNow(new Date()), 0);
    const timer = window.setInterval(() => setNow(new Date()), 60_000);
    return () => {
      window.clearTimeout(initial);
      window.clearInterval(timer);
    };
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    fetch(`/api/panel/summary?range=${range}`, { cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        const payload = await response.json() as Summary & { error?: string };
        if (!response.ok) throw new Error(payload.error || "Dashboard gagal dimuat.");
        setSummary(payload);
        setError("");
      })
      .catch((reason) => {
        if (!(reason instanceof DOMException && reason.name === "AbortError")) {
          setError(reason instanceof Error ? reason.message : "Dashboard gagal dimuat.");
        }
      });
    return () => controller.abort();
  }, [range]);

  useEffect(() => {
    if (isStaff) return;
    const controller = new AbortController();
    fetch("/api/panel/dashboard-integrations", { cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        const payload = await response.json() as { items?: DashboardIntegration[]; error?: string };
        if (!response.ok) throw new Error(payload.error || "Status integrasi gagal dimuat.");
        setIntegrationItems(payload.items ?? []);
      })
      .catch((reason) => {
        if (!(reason instanceof DOMException && reason.name === "AbortError")) {
          setIntegrationItems([]);
        }
      });
    return () => controller.abort();
  }, [isStaff]);

  const canViewFinance = summary?.canViewFinance === true;
  const sales = summary?.chart.map((point) => ({
    day: new Intl.DateTimeFormat("id-ID", { day: "2-digit", month: "short" }).format(new Date(point.day)),
    revenue: Number(point.revenue || 0),
    orders: point.orders,
  })) || [];
  const activities = (summary?.recentActivities.length
    ? summary.recentActivities.map((item) => ({ title: item.action, detail: item.target, time: relativeTime(item.createdAt) }))
    : summary?.recentOrders.map((item) => ({
        title: item.paymentStatus === "paid" ? "Pembayaran berhasil" : "Pesanan diperbarui",
        detail: `${item.referenceId} - ${item.productName}`,
        time: relativeTime(item.createdAt),
      })) || []).slice(0, 5);
  const orders = summary?.recentOrders.slice(0, 4) || [];
  const products = summary?.topProducts || [];
  const allIntegrationReady = integrationItems.length > 0 && integrationItems.filter((item) => item.active).every((item) => item.ready);

  return (
    <div className="admin-dashboard-reference space-y-3.5">
      <section className="flex items-start justify-between">
        <div>
          <h1 className="text-[25px] font-black tracking-[-0.04em] text-[#0c1c3b]">{role === "super_admin" ? "Panel Super Admin LFAMILIA STORE" : isStaff ? "Panel Staff LFAMILIA STORE" : "Panel Admin LFAMILIA STORE"}</h1>
          <p className="mt-0.5 text-[10px] text-[#61708a]">{isStaff ? "Tangani pesanan, tiket, dan konten pelanggan sesuai akses Staff." : role === "super_admin" ? "Kelola seluruh sistem dan data finansial toko." : "Kelola operasional toko sesuai akses akun."}</p>
        </div>
        <div className="flex min-w-[176px] items-center gap-3 rounded-lg border border-[#e2e7ee] bg-white px-4 py-2.5 shadow-[0_2px_10px_rgba(15,23,42,0.035)]">
          <CalendarDays className="size-[18px] text-[#275fae]" />
          <div className="text-[9px] leading-4 text-[#53627a]">
            <p className="font-semibold">{now ? formatDate(now) : "Memuat tanggal..."}</p>
            <p>{now ? formatTime(now) : "--:-- WIB"}</p>
          </div>
        </div>
      </section>

      <section className={`grid grid-cols-1 gap-3 sm:grid-cols-2 ${isStaff ? "xl:grid-cols-2" : canExpectFinance ? "xl:grid-cols-5" : "xl:grid-cols-3"}`}>
        {canViewFinance && <MetricCard Icon={WalletCards} label="Omzet Hari Ini" value={money(summary?.todayMetrics.paidRevenue)} note="transaksi dibayar" />}
        <MetricCard Icon={ShoppingCart} label="Pesanan Hari Ini" value={String(summary?.todayMetrics.totalOrders || 0)} note="pesanan tercatat" />
        {!isStaff && <MetricCard Icon={Boxes} label="Produk Aktif" value={String(summary?.todayMetrics.activeProducts || 0)} note="tersedia di toko" />}
        {canViewFinance && <MetricCard Icon={CircleDollarSign} label="Saldo Digiflazz" value={money(summary?.integrations.digiflazz.balance)} note={summary?.integrations.digiflazz.ready ? "provider online" : "perlu diperiksa"} />}
        <MetricCard Icon={CheckCircle2} label="Pembayaran Berhasil" value={String(summary?.metrics.fulfilledOrders || 0)} note="periode dipilih" />
      </section>

      {error && <button type="button" onClick={() => setError("")} className="w-full rounded-md border border-red-200 bg-red-50 px-3 py-2 text-left text-[9px] text-red-700">{error}</button>}

      <section className={`grid gap-3 ${canViewFinance ? "grid-cols-[1.25fr_1fr_1fr]" : "grid-cols-[1fr_1fr]"}`}>
        {canViewFinance && <Panel>
          <PanelHeader title="Grafik Penjualan">
            <label className="relative">
              <select value={range} onChange={(event) => setRange(event.target.value)} className="h-7 appearance-none rounded-md border border-[#e0e5ec] bg-white pl-2.5 pr-7 text-[8px] font-semibold text-[#607089]">
                <option value="today">Hari Ini</option><option value="7d">7 Hari Terakhir</option><option value="30d">30 Hari Terakhir</option><option value="90d">90 Hari Terakhir</option>
              </select>
              <ChevronDown className="pointer-events-none absolute right-2 top-2 size-3" />
            </label>
          </PanelHeader>
          <SalesBars sales={sales} />
        </Panel>}

        <Panel>
          <PanelHeader title="Aktivitas Terbaru"><button type="button" onClick={() => onNavigate?.(role === "super_admin" ? "team" : "orders")} className="text-[8px] font-bold text-[#1769e8]">Lihat Semua</button></PanelHeader>
          <div className="divide-y divide-[#edf0f4] px-4 py-2">
            {activities.length ? activities.map((activity, index) => <div key={`${activity.title}-${index}`} className="flex items-center gap-3 py-2.5"><span className="grid size-7 place-items-center rounded-full bg-violet-50 text-violet-600"><UserCog className="size-3.5" /></span><div className="min-w-0 flex-1"><p className="truncate text-[8px] font-bold text-[#26354e]">{activity.title}</p><p className="mt-0.5 truncate text-[7px] text-[#7b899d]">{activity.detail}</p></div><span className="shrink-0 text-[7px] text-[#8794a6]">{activity.time}</span></div>) : <EmptyState text="Belum ada aktivitas." />}
          </div>
        </Panel>

        {!isStaff && <Panel>
          <PanelHeader title="Status Integrasi">{role === "super_admin" && <button type="button" onClick={() => onNavigate?.("integrations")} className="flex items-center gap-1.5 text-[8px] font-semibold text-[#1769e8]"><CheckCircle2 className="size-3" />Lihat Integrasi</button>}</PanelHeader>
          <div className="max-h-[360px] space-y-1.5 overflow-y-auto px-3 py-3">
            {integrationItems.length ? integrationItems.map((item) => <IntegrationRow key={item.id} item={item} />) : <EmptyState text="Status integrasi belum dapat dimuat." />}
            <dl className="mt-2 space-y-2 border-t border-[#edf0f4] pt-2 text-[8px]">
              <StatusLine label="Terakhir Sinkronisasi" value={summary?.integrations.digiflazz.lastSyncAt ? relativeTime(summary.integrations.digiflazz.lastSyncAt) : "Belum ada"} />
              <StatusLine label="Webhook" value={summary?.integrations.webhook.ready ? "Aktif" : "Belum siap"} good={Boolean(summary?.integrations.webhook.ready)} />
              <StatusLine label="Integrasi Aktif" value={allIntegrationReady ? "Normal" : "Periksa konfigurasi"} good={allIntegrationReady} />
            </dl>
          </div>
        </Panel>}
      </section>

      <section className={`grid grid-cols-1 gap-3 sm:grid-cols-2 ${isStaff ? "xl:grid-cols-2" : "xl:grid-cols-5"}`}>
        {visibleFeatureCards.slice(0, 5).map((item) => <FeatureCard key={item.title} {...item} onNavigate={onNavigate} />)}
      </section>
      <section className="grid grid-cols-2 gap-3 lg:grid-cols-6">
        {visibleFeatureCards.slice(5).map((item) => <FeatureCard key={item.title} {...item} onNavigate={onNavigate} />)}
      </section>

      <section className={`grid gap-3 ${isStaff ? "grid-cols-1" : "grid-cols-[minmax(0,2.5fr)_minmax(250px,1fr)]"}`}>
        <Panel>
          <PanelHeader title="Pesanan Terbaru"><button type="button" onClick={() => onNavigate?.("orders")} className="text-[8px] font-bold text-[#1769e8]">Lihat Semua Pesanan</button></PanelHeader>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] table-fixed text-left">
              <thead className="bg-[#fafbfd] text-[7px] font-semibold text-[#75839a]"><tr><th className="w-7 px-3 py-2">#</th><th className="w-[18%] px-2 py-2">Invoice</th><th className="w-[18%] px-2 py-2">Pelanggan</th><th className="px-2 py-2">Produk</th><th className="w-[15%] px-2 py-2">Pembayaran</th>{canViewFinance && <th className="w-[13%] px-2 py-2">Total</th>}<th className="w-[10%] px-2 py-2">Status</th></tr></thead>
              <tbody className="divide-y divide-[#edf0f4] text-[8px] text-[#40506a]">
                {orders.map((order, index) => <tr key={order.id || order.referenceId}><td className="px-3 py-2.5">{index + 1}</td><td className="truncate px-2 py-2.5 font-semibold text-[#1769e8]">{order.referenceId}</td><td className="truncate px-2 py-2.5">{order.buyerName}</td><td className="truncate px-2 py-2.5">{order.productName} {order.packageLabel}</td><td className="truncate px-2 py-2.5">{order.paymentChannel}</td>{canViewFinance && <td className="px-2 py-2.5">{money(order.total)}</td>}<td className="px-2 py-2.5"><StatusBadge value={order.fulfillmentStatus === "success" ? "Berhasil" : order.paymentStatus === "failed" || order.fulfillmentStatus === "failed" ? "Gagal" : order.paymentStatus === "paid" ? "Diproses" : "Pending"} /></td></tr>)}
              </tbody>
            </table>
          </div>
        </Panel>

        {!isStaff && <Panel>
          <PanelHeader title="Produk Populer"><button type="button" onClick={() => onNavigate?.("products")} className="text-[8px] font-bold text-[#1769e8]">Lihat Semua</button></PanelHeader>
          <div className="divide-y divide-[#edf0f4] px-3">
            {products.length ? products.map((item, index) => <div key={item.slug || item.name} className="flex items-center gap-2.5 py-2.5"><span className="grid size-5 place-items-center rounded-full bg-[#f0f4f9] text-[7px] font-semibold text-[#65748a]">{index + 1}</span><div className="min-w-0 flex-1"><p className="truncate text-[8px] font-bold text-[#2b3951]">{item.name}</p><p className="text-[7px] text-[#8996a8]">Paling banyak dibeli</p></div><strong className="text-[8px] text-[#26364f]">{item.fulfilledOrders}</strong></div>) : <EmptyState text="Belum ada data produk." />}
          </div>
        </Panel>}
      </section>
    </div>
  );
}

function MetricCard({ Icon, label, value, note }: { Icon: LucideIcon; label: string; value: string; note: string }) {
  return <div className="rounded-lg border border-[#e1e6ed] bg-white p-3 shadow-[0_2px_9px_rgba(15,23,42,0.035)]"><div className="flex gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-lg bg-[#edf4ff] text-[#1769e8]"><Icon className="size-5" /></span><div className="min-w-0"><p className="truncate text-[8px] font-semibold text-[#64738b]">{label}</p><p className="mt-1 truncate text-[17px] font-black tracking-[-0.03em] text-[#0f1e38]">{value}</p></div></div><p className="mt-3 text-center text-[7px] text-[#7c899c]">{note}</p></div>;
}

function Panel({ children }: { children: React.ReactNode }) {
  return <section className="overflow-hidden rounded-lg border border-[#e1e6ed] bg-white shadow-[0_2px_9px_rgba(15,23,42,0.03)]">{children}</section>;
}

function PanelHeader({ title, children }: { title: string; children?: React.ReactNode }) {
  return <header className="flex h-10 items-center justify-between border-b border-[#edf0f4] px-4"><h2 className="text-[11px] font-extrabold text-[#1a2942]">{title}</h2>{children}</header>;
}

function SalesBars({ sales }: { sales: Array<{ day: string; revenue: number; orders: number }> }) {
  const maxRevenue = Math.max(1, ...sales.map((point) => point.revenue));
  const maxOrders = Math.max(1, ...sales.map((point) => point.orders));
  return <div className="flex h-[240px] items-end gap-2 px-4 pb-4 pt-8">{sales.map((point) => <div key={point.day} className="flex min-w-0 flex-1 flex-col items-center justify-end gap-1"><div className="flex h-[180px] w-full items-end justify-center gap-1"><span className="w-[12px] rounded-t-sm bg-[#1769e8]" style={{ height: `${Math.max(4, point.revenue / maxRevenue * 100)}%` }} /><span className="w-[12px] rounded-t-sm bg-[#8cc7ff]" style={{ height: `${Math.max(4, point.orders / maxOrders * 100)}%` }} /></div><span className="truncate text-[7px] text-[#66758b]">{point.day}</span></div>)}</div>;
}

function IntegrationRow({ item }: { item: DashboardIntegration }) {
  const initials = item.id === "digiflazz" ? "D" : item.id === "kokinpay" ? "K" : item.id.startsWith("doku") ? "DO" : "M";
  const tone = item.id.startsWith("doku") ? "bg-[#e5232d]" : item.id.startsWith("midtrans") ? "bg-[#1f7ae0]" : item.id === "kokinpay" ? "bg-violet-600" : "bg-[#1769e8]";
  return <div className="flex items-center gap-3 rounded-md border border-[#e7ebf0] bg-[#fbfcfe] px-3 py-2"><span className={`grid size-8 shrink-0 place-items-center rounded-md text-[12px] font-black text-white ${tone}`}>{initials}</span><div className="min-w-0 flex-1"><p className="truncate text-[9px] font-bold text-[#26354e]">{item.name}</p><p className={`mt-0.5 truncate text-[7px] font-semibold ${item.ready ? item.active ? "text-emerald-600" : "text-slate-500" : "text-amber-600"}`}>{item.status}</p></div><span className={`shrink-0 rounded-md px-2 py-1 text-[7px] font-semibold ${item.ready ? item.active ? "bg-emerald-50 text-emerald-600" : "bg-slate-100 text-slate-600" : "bg-amber-50 text-amber-600"}`}>{item.ready ? item.active ? "Online" : "Standby" : "Periksa"}</span></div>;
}

function StatusLine({ label, value, good = false }: { label: string; value: string; good?: boolean }) {
  return <div className="flex items-center justify-between"><dt className="text-[#78869a]">{label}</dt><dd className="flex items-center gap-1.5 font-semibold text-[#4d5c72]">{good && <i className="size-1.5 rounded-full bg-emerald-500" />}{value}</dd></div>;
}

function FeatureCard({ title, Icon, target, items, onNavigate }: { title: string; Icon: LucideIcon; target: string; items: string[]; onNavigate?: (value: string) => void }) {
  return <button type="button" onClick={() => onNavigate?.(target)} className="min-h-[155px] rounded-lg border border-[#e1e6ed] bg-white p-3 text-left shadow-[0_2px_9px_rgba(15,23,42,0.03)] transition hover:border-[#cdd9e8]"><div className="mb-2.5 flex items-center gap-2.5"><span className="grid size-7 place-items-center rounded-md bg-[#edf4ff] text-[#1769e8]"><Icon className="size-4" /></span><h3 className="min-w-0 flex-1 truncate text-[9px] font-extrabold text-[#273650]">{title}</h3><ChevronRight className="size-3.5 text-[#64758d]" /></div><ul className="space-y-1.5">{items.map((item) => <li key={item} className="flex items-center gap-1.5 text-[7px] leading-3 text-[#607089]"><Check className="size-2.5 shrink-0 text-emerald-500" strokeWidth={3} />{item}</li>)}</ul></button>;
}

function EmptyState({ text }: { text: string }) {
  return <div className="px-3 py-6 text-center text-[8px] text-[#8794a6]">{text}</div>;
}

function StatusBadge({ value }: { value: string }) {
  const success = value === "Berhasil";
  const failed = value === "Gagal";
  return <span className={`rounded-md px-2 py-1 text-[7px] font-semibold ${success ? "bg-emerald-50 text-emerald-600" : failed ? "bg-red-50 text-red-600" : "bg-amber-50 text-amber-600"}`}>{value}</span>;
}

function money(value: number | null | undefined) {
  return value == null ? "-" : new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value);
}

function relativeTime(value: string) {
  const time = new Date(value).getTime();
  if (!Number.isFinite(time)) return value || "-";
  const minutes = Math.max(0, Math.floor((Date.now() - time) / 60_000));
  return minutes < 1 ? "baru saja" : minutes < 60 ? `${minutes} menit lalu` : minutes < 1_440 ? `${Math.floor(minutes / 60)} jam lalu` : `${Math.floor(minutes / 1_440)} hari lalu`;
}

function formatDate(date: Date) {
  return new Intl.DateTimeFormat("id-ID", { weekday: "long", day: "2-digit", month: "short", year: "numeric", timeZone: "Asia/Jakarta" }).format(date);
}

function formatTime(date: Date) {
  return new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: "Asia/Jakarta" }).format(date) + " WIB";
}
