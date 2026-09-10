"use client";

import { useEffect, useState } from "react";
import type { LucideIcon } from "lucide-react";
import {
  ArrowUp,
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

const sales = [
  { day: "17 Apr", revenue: 1000000, orders: 42 },
  { day: "18 Apr", revenue: 1230000, orders: 61 },
  { day: "19 Apr", revenue: 820000, orders: 102 },
  { day: "20 Apr", revenue: 920000, orders: 111 },
  { day: "21 Apr", revenue: 1130000, orders: 71 },
  { day: "22 Apr", revenue: 1410000, orders: 88 },
  { day: "23 Apr", revenue: 1060000, orders: 168 },
];

const activities = [
  { tone: "blue", title: "Pesanan baru", detail: "INV/20250423/0012 - Mobile Legends", time: "2 menit lalu", Icon: ShoppingCart },
  { tone: "green", title: "Pembayaran berhasil", detail: "INV/20250423/0011 - DANA", time: "8 menit lalu", Icon: CheckCircle2 },
  { tone: "blue", title: "Pelanggan baru", detail: "rinaldi123@gmail.com", time: "15 menit lalu", Icon: Users },
  { tone: "purple", title: "Produk diperbarui", detail: "Harga Mobile Legends (Digiflazz)", time: "28 menit lalu", Icon: Boxes },
  { tone: "orange", title: "Tiket pelanggan", detail: "#TK-00045 - Kendala top up", time: "1 jam lalu", Icon: Headphones },
];

const featureCards = [
  { title: "Pesanan", Icon: FileText, target: "orders", items: ["Kelola pesanan & invoice", "Update status pesanan", "Callback log & notifikasi", "Proses manual / refund"] },
  { title: "Produk", Icon: Boxes, target: "products", items: ["Kategori game & layanan", "SKU provider (Digiflazz)", "Atur harga & margin", "Jenis pengiriman", "Kelola produk voucher"] },
  { title: "Banner & Konten", Icon: ImageIcon, target: "content", items: ["Kelola banner utama", "Pop-up informasi", "Berita & pengumuman", "Ulasan pelanggan", "Halaman FAQ"] },
  { title: "Digiflazz", Icon: Link2, target: "digiflazz", items: ["Sinkronisasi pricelist", "Mapping SKU produk", "Cek & kelola saldo", "Monitor gangguan layanan", "Log API & history"] },
  { title: "Pembayaran (DOKU)", Icon: CreditCard, target: "payments", items: ["Terima pembayaran QRIS", "Virtual Account (VA)", "E-Wallet (DANA, OVO, GoPay)", "Callback otomatis", "Settlement & log transaksi"] },
  { title: "Pelanggan", Icon: Users, target: "customers", items: ["Data pelanggan", "Wallet & saldo", "Riwayat transaksi", "Blacklist pelanggan"] },
  { title: "Promo", Icon: Sparkles, target: "promotions", items: ["Kode voucher", "Cashback & diskon", "Promo member", "Event promo khusus"] },
  { title: "Layanan Pelanggan", Icon: Headphones, target: "support", items: ["Sistem tiket", "Live chat pelanggan", "SLA & prioritas", "Lampiran bukti transaksi"] },
  { title: "Laporan", Icon: BarChart3, target: "reports", items: ["Laporan penjualan", "Laporan profit", "Produk terlaris", "Export CSV / PDF", "Filter periode lengkap"] },
  { title: "Staff & Admin Akses", Icon: UserCog, target: "team", items: ["Role Super Admin", "Role Admin & Staff", "Permission matrix", "Log aktivitas admin"] },
  { title: "Pengaturan", Icon: Settings, target: "settings", items: ["Profil toko & kontak", "Logo & favicon", "Integrasi layanan", "Pengaturan keamanan", "Notifikasi sistem"] },
];

const orders = [
  { invoice: "INV/20250423/0012", customer: "Rizky Pratama", product: "Mobile Legends 86 Diamonds", payment: "DANA", total: "Rp 20.000", status: "Berhasil" },
  { invoice: "INV/20250423/0011", customer: "Siti Aulia", product: "Free Fire 140 Diamonds", payment: "QRIS", total: "Rp 33.000", status: "Berhasil" },
  { invoice: "INV/20250423/0010", customer: "Budi Santoso", product: "PUBG Mobile 325 UC", payment: "Virtual Account", total: "Rp 75.000", status: "Diproses" },
  { invoice: "INV/20250423/0009", customer: "Andi Saputra", product: "Valorant 100 VP", payment: "GoPay", total: "Rp 16.000", status: "Berhasil" },
];

const products = [
  ["Mobile Legends", "1.284"],
  ["Free Fire", "982"],
  ["PUBG Mobile", "756"],
  ["Valorant", "521"],
  ["Steam Wallet", "418"],
];

export function AdminOverview({ onNavigate }: { onNavigate?: (value: string) => void }) {
  const [now, setNow] = useState<Date | null>(null);

  useEffect(() => {
    setNow(new Date());
    const timer = window.setInterval(() => setNow(new Date()), 60_000);
    return () => window.clearInterval(timer);
  }, []);

  return (
    <div className="space-y-3.5">
      <section className="flex items-start justify-between">
        <div>
          <h1 className="text-[25px] font-black tracking-[-0.04em] text-[#0c1c3b]">Panel Admin LFAMILIA STORE</h1>
          <p className="mt-0.5 text-[10px] text-[#61708a]">Kelola pesanan, produk, integrasi provider, pembayaran, pelanggan, laporan, dan konten dalam satu panel.</p>
        </div>
        <div className="flex min-w-[176px] items-center gap-3 rounded-lg border border-[#e2e7ee] bg-white px-4 py-2.5 shadow-[0_2px_10px_rgba(15,23,42,0.035)]">
          <CalendarDays className="size-[18px] text-[#275fae]" />
          <div className="text-[9px] leading-4 text-[#53627a]">
            <p className="font-semibold">{now ? formatDate(now) : "Memuat tanggal..."}</p>
            <p>{now ? formatTime(now) : "--:-- WIB"}</p>
          </div>
        </div>
      </section>

      <section className="grid grid-cols-5 gap-3">
        <MetricCard Icon={WalletCards} label="Omzet Hari Ini" value="Rp 2.480.980" delta="+12,5%" note="dari kemarin" />
        <MetricCard Icon={ShoppingCart} label="Pesanan Hari Ini" value="164" delta="+8,1%" note="dari kemarin" />
        <MetricCard Icon={Boxes} label="Produk Aktif" value="256" delta="0%" note="tidak ada perubahan" neutral />
        <MetricCard Icon={CircleDollarSign} label="Saldo Digiflazz" value="Rp 4.570.800" delta="+5,2%" note="dari kemarin" />
        <MetricCard Icon={CheckCircle2} label="Pembayaran Berhasil" value="158" delta="+11,3%" note="dari kemarin" success />
      </section>

      <section className="grid grid-cols-[1.25fr_1fr_0.94fr] gap-3">
        <Panel className="min-h-[282px]">
          <PanelHeader title="Grafik Penjualan">
            <button type="button" className="flex h-7 items-center gap-2 rounded-md border border-[#e0e5ec] bg-white px-2.5 text-[8px] font-semibold text-[#607089]">7 Hari Terakhir <ChevronDown className="size-3" /></button>
          </PanelHeader>
          <div className="px-4 pt-3">
            <div className="flex gap-5 text-[8px] text-[#607089]">
              <span className="flex items-center gap-1.5"><i className="size-2 rounded-full bg-[#1769e8]" />Omzet (Rp)</span>
              <span className="flex items-center gap-1.5"><i className="size-2 rounded-full bg-[#8cc7ff]" />Jumlah Pesanan</span>
            </div>
          </div>
          <SalesBars />
        </Panel>

        <Panel className="min-h-[282px]">
          <PanelHeader title="Aktivitas Terbaru"><button type="button" className="text-[8px] font-bold text-[#1769e8]">Lihat Semua</button></PanelHeader>
          <div className="relative px-4 py-2">
            <span className="absolute bottom-5 left-[27px] top-6 w-px bg-[#dbe3ed]" />
            {activities.map((activity) => <ActivityRow key={activity.title} {...activity} />)}
          </div>
        </Panel>

        <Panel className="min-h-[282px]">
          <PanelHeader title="Status Integrasi"><span className="flex items-center gap-1.5 text-[8px] font-semibold text-emerald-600"><CheckCircle2 className="size-3" />Semua Sistem Normal</span></PanelHeader>
          <div className="space-y-2 px-4 py-3">
            <IntegrationRow letter="D" name="Digiflazz API" />
            <IntegrationRow letter="DO" name="DOKU Direct API" red />
            <dl className="space-y-2 border-t border-[#edf0f4] pt-2 text-[8px]">
              <StatusLine label="Terakhir Sinkronisasi" value="23 Apr 2025 14:28 WIB" />
              <StatusLine label="Status Webhook" value="Aktif" dot />
              <StatusLine label="Respon API" value="Normal" dot />
              <StatusLine label="Uptime" value="99,9%" dot />
            </dl>
          </div>
        </Panel>
      </section>

      <section className="grid grid-cols-5 gap-3">
        {featureCards.slice(0, 5).map((item) => <FeatureCard key={item.title} {...item} onNavigate={onNavigate} />)}
      </section>

      <section className="grid grid-cols-6 gap-3">
        {featureCards.slice(5).map((item) => <FeatureCard key={item.title} {...item} onNavigate={onNavigate} />)}
      </section>

      <section className="grid grid-cols-[minmax(0,2.5fr)_minmax(250px,1fr)] gap-3">
        <Panel>
          <PanelHeader title="Pesanan Terbaru"><button type="button" onClick={() => onNavigate?.("orders")} className="text-[8px] font-bold text-[#1769e8]">Lihat Semua Pesanan</button></PanelHeader>
          <div className="overflow-hidden">
            <table className="w-full table-fixed text-left">
              <thead className="bg-[#fafbfd] text-[7px] font-semibold text-[#75839a]">
                <tr><th className="w-7 px-3 py-2">#</th><th className="w-[18%] px-2 py-2">Invoice</th><th className="w-[18%] px-2 py-2">Pelanggan</th><th className="px-2 py-2">Produk</th><th className="w-[15%] px-2 py-2">Pembayaran</th><th className="w-[11%] px-2 py-2">Total</th><th className="w-[10%] px-2 py-2">Status</th><th className="w-[8%] px-2 py-2">Aksi</th></tr>
              </thead>
              <tbody className="divide-y divide-[#edf0f4] text-[8px] text-[#40506a]">
                {orders.map((order, index) => (
                  <tr key={order.invoice} className="hover:bg-[#fafcff]">
                    <td className="px-3 py-2.5">{index + 1}</td>
                    <td className="truncate px-2 py-2.5 font-semibold text-[#1769e8]">{order.invoice}</td>
                    <td className="truncate px-2 py-2.5">{order.customer}</td>
                    <td className="truncate px-2 py-2.5 font-medium text-[#25344d]">{order.product}</td>
                    <td className="truncate px-2 py-2.5">● {order.payment}</td>
                    <td className="px-2 py-2.5">{order.total}</td>
                    <td className="px-2 py-2.5"><StatusBadge value={order.status} /></td>
                    <td className="px-2 py-2.5"><button type="button" onClick={() => onNavigate?.("orders")} className="rounded bg-[#eaf3ff] px-2 py-1 font-semibold text-[#1769e8]">Lihat</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>

        <Panel>
          <PanelHeader title="Produk Populer"><button type="button" onClick={() => onNavigate?.("products")} className="text-[8px] font-bold text-[#1769e8]">Lihat Semua</button></PanelHeader>
          <div className="divide-y divide-[#edf0f4] px-3">
            {products.map(([name, amount], index) => (
              <div key={name} className="flex items-center gap-2.5 py-2">
                <span className="grid size-4 place-items-center rounded-full bg-[#f0f4f9] text-[7px] font-semibold text-[#65748a]">{index + 1}</span>
                <span className="grid size-7 place-items-center rounded-md bg-gradient-to-br from-[#213954] to-[#0e1727] text-[7px] font-black text-white">{name.slice(0, 2).toUpperCase()}</span>
                <div className="min-w-0 flex-1"><p className="truncate text-[8px] font-bold text-[#2b3951]">{name}</p><p className="mt-0.5 text-[7px] text-[#8996a8]">Paling banyak dibeli</p></div>
                <span className="text-[8px] font-black text-[#26364f]">{amount}</span>
              </div>
            ))}
          </div>
        </Panel>
      </section>
    </div>
  );
}

function MetricCard({ Icon, label, value, delta, note, neutral = false, success = false }: { Icon: LucideIcon; label: string; value: string; delta: string; note: string; neutral?: boolean; success?: boolean }) {
  return (
    <div className="rounded-lg border border-[#e1e6ed] bg-white p-3 shadow-[0_2px_9px_rgba(15,23,42,0.035)]">
      <div className="flex gap-3">
        <span className={"grid size-10 shrink-0 place-items-center rounded-lg " + (success ? "bg-emerald-50 text-emerald-600" : "bg-[#edf4ff] text-[#1769e8]")}><Icon className="size-5" /></span>
        <div className="min-w-0"><p className="truncate text-[8px] font-semibold text-[#64738b]">{label}</p><p className="mt-1 truncate text-[17px] font-black tracking-[-0.03em] text-[#0f1e38]">{value}</p></div>
      </div>
      <div className={"mt-3 flex items-center justify-center gap-2 text-[7px] " + (neutral ? "text-[#7c899c]" : "text-emerald-600")}>
        {!neutral && <ArrowUp className="size-2.5" strokeWidth={3} />}<strong>{delta}</strong><span className="text-[#7c899c]">{note}</span>
      </div>
    </div>
  );
}

function Panel({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return <section className={"overflow-hidden rounded-lg border border-[#e1e6ed] bg-white shadow-[0_2px_9px_rgba(15,23,42,0.03)] " + className}>{children}</section>;
}

function PanelHeader({ title, children }: { title: string; children?: React.ReactNode }) {
  return <header className="flex h-10 items-center justify-between border-b border-[#edf0f4] px-4"><h2 className="text-[11px] font-extrabold text-[#1a2942]">{title}</h2>{children}</header>;
}

function SalesBars() {
  const maxRevenue = 2_000_000;
  const maxOrders = 200;
  return (
    <div className="grid h-[216px] grid-cols-[34px_1fr] gap-2 px-4 pb-3 pt-2">
      <div className="flex flex-col justify-between pb-6 text-right text-[7px] text-[#7b899d]"><span>2M</span><span>1,5M</span><span>1M</span><span>500K</span><span>0</span></div>
      <div className="relative border-b border-l border-[#dfe5ed]">
        {[0, 25, 50, 75].map((top) => <span key={top} className="absolute left-0 right-0 border-t border-[#edf0f4]" style={{ top: `${top}%` }} />)}
        <div className="absolute inset-0 flex items-end justify-around px-2 pb-6">
          {sales.map((point) => (
            <div key={point.day} className="flex h-full flex-1 items-end justify-center gap-1">
              <span className="w-[12px] rounded-t-sm bg-[#1769e8]" style={{ height: `${Math.max(5, point.revenue / maxRevenue * 100)}%` }} />
              <span className="w-[12px] rounded-t-sm bg-[#8cc7ff]" style={{ height: `${Math.max(5, point.orders / maxOrders * 100)}%` }} />
              <span className="absolute bottom-1 text-[7px] text-[#66758b]">{point.day}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function ActivityRow({ tone, title, detail, time, Icon }: { tone: string; title: string; detail: string; time: string; Icon: LucideIcon }) {
  const colors: Record<string, string> = { blue: "bg-blue-50 text-blue-600", green: "bg-emerald-50 text-emerald-600", purple: "bg-violet-50 text-violet-600", orange: "bg-orange-50 text-orange-600" };
  return (
    <div className="relative z-10 flex items-center gap-3 py-2">
      <span className={"grid size-7 shrink-0 place-items-center rounded-full ring-4 ring-white " + colors[tone]}><Icon className="size-3.5" /></span>
      <div className="min-w-0 flex-1"><p className="truncate text-[8px] font-bold text-[#26354e]">{title}</p><p className="mt-0.5 truncate text-[7px] text-[#7b899d]">{detail}</p></div>
      <span className="shrink-0 text-[7px] text-[#8794a6]">{time}</span>
    </div>
  );
}

function IntegrationRow({ letter, name, red = false }: { letter: string; name: string; red?: boolean }) {
  return (
    <div className="flex items-center gap-3 rounded-md border border-[#e7ebf0] bg-[#fbfcfe] px-3 py-2">
      <span className={"grid size-8 place-items-center rounded-md text-[15px] font-black text-white " + (red ? "bg-[#e5232d]" : "bg-[#1769e8]")}>{letter}</span>
      <div className="min-w-0 flex-1"><p className="truncate text-[9px] font-bold text-[#26354e]">{name}</p><p className="mt-0.5 text-[7px] font-semibold text-emerald-600">Terhubung</p></div>
      <span className="flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-1 text-[7px] font-semibold text-emerald-600"><i className="size-1.5 rounded-full bg-emerald-500" />Online</span>
    </div>
  );
}

function StatusLine({ label, value, dot = false }: { label: string; value: string; dot?: boolean }) {
  return <div className="flex items-center justify-between"><dt className="text-[#78869a]">{label}</dt><dd className="flex items-center gap-1.5 font-semibold text-[#4d5c72]">{dot && <i className="size-1.5 rounded-full bg-emerald-500" />}{value}</dd></div>;
}

function FeatureCard({ title, Icon, target, items, onNavigate }: { title: string; Icon: LucideIcon; target: string; items: string[]; onNavigate?: (value: string) => void }) {
  return (
    <button type="button" onClick={() => onNavigate?.(target)} className="min-h-[155px] rounded-lg border border-[#e1e6ed] bg-white p-3 text-left shadow-[0_2px_9px_rgba(15,23,42,0.03)] transition hover:-translate-y-0.5 hover:border-[#cdd9e8] hover:shadow-[0_8px_20px_rgba(15,23,42,0.07)]">
      <div className="mb-2.5 flex items-center gap-2.5"><span className="grid size-7 place-items-center rounded-md bg-[#edf4ff] text-[#1769e8]"><Icon className="size-4" /></span><h3 className="min-w-0 flex-1 truncate text-[9px] font-extrabold text-[#273650]">{title}</h3><ChevronRight className="size-3.5 text-[#64758d]" /></div>
      <ul className="space-y-1.5">
        {items.map((item) => <li key={item} className="flex items-center gap-1.5 text-[7px] leading-3 text-[#607089]"><Check className="size-2.5 shrink-0 text-emerald-500" strokeWidth={3} />{item}</li>)}
      </ul>
    </button>
  );
}

function StatusBadge({ value }: { value: string }) {
  const success = value === "Berhasil";
  return <span className={"rounded-md px-2 py-1 text-[7px] font-semibold " + (success ? "bg-emerald-50 text-emerald-600" : "bg-amber-50 text-amber-600")}>{value}</span>;
}

function formatDate(date: Date) {
  return new Intl.DateTimeFormat("id-ID", { weekday: "long", day: "2-digit", month: "short", year: "numeric", timeZone: "Asia/Jakarta" }).format(date);
}

function formatTime(date: Date) {
  return new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: "Asia/Jakarta" }).format(date) + " WIB";
}
