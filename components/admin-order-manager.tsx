"use client";

import { useMemo, useState, type FormEvent, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  BadgeCheck,
  CalendarDays,
  Check,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  CircleX,
  Clock3,
  Cog,
  Copy,
  Download,
  Eye,
  Landmark,
  MoreVertical,
  Plus,
  QrCode,
  RefreshCw,
  Search,
  Send,
  ShoppingCart,
  TriangleAlert,
  UserRound,
  WalletCards,
  X,
} from "lucide-react";

type OrderStatus = "Berhasil" | "Diproses" | "Pending" | "Gagal" | "Komplain";

type Order = {
  id: string;
  customer: string;
  phone: string;
  product: string;
  packageName: string;
  productCode: string;
  destination: string;
  destinationNote: string;
  payment: string;
  provider: string;
  total: number;
  status: OrderStatus;
};

type Activity = {
  title: string;
  invoice: string;
  detail: string;
  time: string;
  tone: "blue" | "green" | "yellow" | "red" | "pink";
  Icon: LucideIcon;
};

const initialOrders: Order[] = [
  { id: "INV/20250423/0012", customer: "Rizky Pratama", phone: "0857****1123", product: "Mobile Legends", packageName: "86 Diamond", productCode: "ML", destination: "123456789", destinationNote: "ID Server: 9876", payment: "QRIS DOKU", provider: "Digiflazz", total: 20000, status: "Berhasil" },
  { id: "INV/20250423/0011", customer: "Siti Aulia", phone: "0812****7789", product: "Free Fire", packageName: "Membership Mingguan", productCode: "FF", destination: "556677889", destinationNote: "ID Server: 1122", payment: "DOKU VA BCA", provider: "Digiflazz", total: 33000, status: "Diproses" },
  { id: "INV/20250423/0010", customer: "Budi Santoso", phone: "0813****4455", product: "PUBG Mobile", packageName: "325 UC", productCode: "PUBG", destination: "8899001122", destinationNote: "ID: 3344", payment: "DOKU GoPay", provider: "Digiflazz", total: 75000, status: "Berhasil" },
  { id: "INV/20250423/0009", customer: "Andi Saputra", phone: "0821****6677", product: "Valorant", packageName: "100 Valorant Points", productCode: "VAL", destination: "Riot ID: AndiS#INA", destinationNote: "Tag: #1234", payment: "QRIS DOKU", provider: "Digiflazz", total: 16000, status: "Pending" },
  { id: "INV/20250423/0008", customer: "Maya Sari", phone: "0819****3344", product: "Genshin Impact", packageName: "Blessing of the Welkin Moon", productCode: "GI", destination: "UID 812345678", destinationNote: "Server: Asia", payment: "DOKU GoPay", provider: "Digiflazz", total: 79000, status: "Berhasil" },
  { id: "INV/20250423/0007", customer: "Dimas Kurniawan", phone: "0856****9988", product: "Steam Wallet", packageName: "Rp 120.000", productCode: "STEAM", destination: "Steam ID: dimas123", destinationNote: "", payment: "DOKU VA BCA", provider: "Digiflazz", total: 120000, status: "Berhasil" },
  { id: "INV/20250423/0006", customer: "Putri Ananda", phone: "0822****7766", product: "Mobile Legends", packageName: "172 Diamond", productCode: "ML", destination: "987654321", destinationNote: "ID Server: 4321", payment: "QRIS DOKU", provider: "Digiflazz", total: 40000, status: "Gagal" },
  { id: "INV/20250423/0005", customer: "Fahri Maulana", phone: "0811****2233", product: "Free Fire", packageName: "510 Diamond", productCode: "FF", destination: "778899001", destinationNote: "ID Server: 6677", payment: "DOKU GoPay", provider: "Digiflazz", total: 149000, status: "Berhasil" },
  { id: "INV/20250423/0004", customer: "Nabila Putri", phone: "0838****4455", product: "PUBG Mobile", packageName: "660 UC", productCode: "PUBG", destination: "1122334455", destinationNote: "ID: 8899", payment: "QRIS DOKU", provider: "Digiflazz", total: 149000, status: "Komplain" },
  { id: "INV/20250423/0003", customer: "Kevin Wijaya", phone: "0877****9900", product: "Valorant", packageName: "2050 Valorant Points", productCode: "VAL", destination: "Riot ID: KevinW#INA", destinationNote: "Tag: #5678", payment: "DOKU VA BCA", provider: "Digiflazz", total: 299000, status: "Berhasil" },
];

const activities: Activity[] = [
  { title: "Pembayaran diterima", invoice: "INV/20250423/0012", detail: "DOKU - QRIS", time: "2 menit lalu", tone: "green", Icon: CheckCircle2 },
  { title: "Pesanan dikirim Digiflazz", invoice: "INV/20250423/0011", detail: "Mobile Legends 86 Diamond", time: "5 menit lalu", tone: "blue", Icon: Send },
  { title: "Menunggu pembayaran", invoice: "INV/20250423/0009", detail: "DOKU VA BCA", time: "8 menit lalu", tone: "yellow", Icon: Clock3 },
  { title: "Pesanan berhasil", invoice: "INV/20250423/0008", detail: "Genshin Impact", time: "12 menit lalu", tone: "green", Icon: CheckCircle2 },
  { title: "Pembayaran gagal", invoice: "INV/20250423/0006", detail: "Saldo tidak mencukupi", time: "18 menit lalu", tone: "red", Icon: CircleX },
  { title: "Pesanan dikirim Digiflazz", invoice: "INV/20250423/0005", detail: "Free Fire 510 Diamond", time: "25 menit lalu", tone: "blue", Icon: Send },
  { title: "Komplain masuk", invoice: "INV/20250423/0004", detail: "Produk belum diterima", time: "32 menit lalu", tone: "pink", Icon: TriangleAlert },
  { title: "Pesanan berhasil", invoice: "INV/20250423/0003", detail: "Valorant 2050 Points", time: "45 menit lalu", tone: "green", Icon: CheckCircle2 },
  { title: "Menunggu pembayaran", invoice: "INV/20250423/0002", detail: "DOKU GoPay", time: "1 jam lalu", tone: "yellow", Icon: Clock3 },
  { title: "Pembayaran diterima", invoice: "INV/20250423/0001", detail: "DOKU - QRIS", time: "2 jam lalu", tone: "green", Icon: CheckCircle2 },
];

const metrics: Array<{ label: string; value: string; change: string; note: string; trend: "up" | "down"; trendTone: "green" | "red"; tone: string; Icon: LucideIcon }> = [
  { label: "Total Pesanan", value: "12.450", change: "+12.5%", note: "dari bulan lalu", trend: "up", trendTone: "green", tone: "blue", Icon: ShoppingCart },
  { label: "Pending", value: "125", change: "+8.1%", note: "dari bulan lalu", trend: "up", trendTone: "red", tone: "yellow", Icon: Clock3 },
  { label: "Diproses", value: "86", change: "-15.3%", note: "dari bulan lalu", trend: "down", trendTone: "green", tone: "blue", Icon: Cog },
  { label: "Berhasil", value: "11.980", change: "+10.2%", note: "dari bulan lalu", trend: "up", trendTone: "green", tone: "green", Icon: BadgeCheck },
  { label: "Gagal", value: "42", change: "+2.4%", note: "dari bulan lalu", trend: "up", trendTone: "red", tone: "red", Icon: CircleX },
  { label: "Komplain", value: "18", change: "+20.0%", note: "dari bulan lalu", trend: "up", trendTone: "red", tone: "red", Icon: TriangleAlert },
];

export function AdminOrderManager() {
  const [orders, setOrders] = useState(initialOrders);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("Semua Status");
  const [provider, setProvider] = useState("Semua Provider");
  const [payment, setPayment] = useState("Semua Pembayaran");
  const [date, setDate] = useState("");
  const [selected, setSelected] = useState<string[]>([]);
  const [bulkAction, setBulkAction] = useState("Aksi massal");
  const [detailOrder, setDetailOrder] = useState<Order | null>(null);
  const [manualOpen, setManualOpen] = useState(false);
  const [notice, setNotice] = useState("");
  const [lastUpdated, setLastUpdated] = useState("10:24 WIB");
  const [autoRefresh, setAutoRefresh] = useState(true);

  const visibleOrders = useMemo(() => {
    const term = query.trim().toLowerCase();
    return orders.filter((order) => {
      const searchable = `${order.id} ${order.customer} ${order.phone} ${order.product} ${order.destination}`.toLowerCase();
      const paymentGroup = order.payment.includes("QRIS") ? "QRIS" : order.payment.includes("VA") ? "Virtual Account" : "E-Wallet";
      return (!term || searchable.includes(term)) &&
        (status === "Semua Status" || order.status === status) &&
        (provider === "Semua Provider" || order.provider === provider) &&
        (payment === "Semua Pembayaran" || paymentGroup === payment);
    });
  }, [orders, payment, provider, query, status]);

  const allSelected = visibleOrders.length > 0 && visibleOrders.every((order) => selected.includes(order.id));

  function resetFilters() {
    setQuery("");
    setStatus("Semua Status");
    setProvider("Semua Provider");
    setPayment("Semua Pembayaran");
    setDate("");
  }

  function refresh() {
    const now = new Date();
    setLastUpdated(`${now.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" }).replace(".", ":")} WIB`);
    setNotice("Data tampilan berhasil diperbarui.");
  }

  function exportCsv() {
    const headings = ["ID Pesanan", "Pelanggan", "Produk", "Tujuan", "Pembayaran", "Provider", "Total", "Status"];
    const lines = visibleOrders.map((order) => [order.id, order.customer, `${order.product} ${order.packageName}`, order.destination, order.payment, order.provider, order.total, order.status]);
    const csv = [headings, ...lines].map((line) => line.map(csvCell).join(",")).join("\n");
    const url = URL.createObjectURL(new Blob(["\uFEFF" + csv], { type: "text/csv;charset=utf-8" }));
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "pesanan-lfamilia.csv";
    anchor.click();
    URL.revokeObjectURL(url);
  }

  function toggleAll() {
    if (allSelected) setSelected((current) => current.filter((id) => !visibleOrders.some((order) => order.id === id)));
    else setSelected((current) => Array.from(new Set([...current, ...visibleOrders.map((order) => order.id)])));
  }

  function applyBulkAction() {
    if (!selected.length || bulkAction === "Aksi massal") {
      setNotice("Pilih pesanan dan aksi massal terlebih dahulu.");
      return;
    }
    if (bulkAction === "Tandai diproses") {
      setOrders((current) => current.map((order) => selected.includes(order.id) ? { ...order, status: "Diproses" as OrderStatus } : order));
    }
    setNotice(`${bulkAction} diterapkan ke ${selected.length} pesanan.`);
    setSelected([]);
  }

  function addManualOrder(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const customer = String(data.get("customer") || "Pelanggan Manual");
    const product = String(data.get("product") || "Mobile Legends");
    const destination = String(data.get("destination") || "-");
    const newOrder: Order = {
      id: `INV/MANUAL/${String(orders.length + 1).padStart(4, "0")}`,
      customer,
      phone: String(data.get("phone") || "-"),
      product,
      packageName: String(data.get("packageName") || "Pesanan manual"),
      productCode: product.slice(0, 3).toUpperCase(),
      destination,
      destinationNote: "Dibuat oleh Admin",
      payment: String(data.get("payment") || "QRIS DOKU"),
      provider: "Manual",
      total: Number(data.get("total") || 0),
      status: "Pending",
    };
    setOrders((current) => [newOrder, ...current]);
    setManualOpen(false);
    setNotice(`Pesanan manual ${newOrder.id} berhasil ditambahkan.`);
  }

  return (
    <div className="admin-orders-reference min-w-0 text-[#14213a]">
      <header className="flex items-start justify-between gap-[16px]">
        <div>
          <h1 className="text-[23px] font-black leading-[1.15] tracking-[-0.04em] text-[#0b1834]">Pesanan</h1>
          <p className="mt-[4px] text-[10px] leading-[1.4] text-[#677892]">Kelola seluruh transaksi top up game, monitor status pembayaran dan proses pengiriman.</p>
        </div>
        <div className="flex shrink-0 items-center gap-[8px]">
          <div className="mr-[3px] flex items-center gap-[8px] text-[8px] text-[#61728b]">
            <span>Terakhir update {lastUpdated}</span>
            <button type="button" onClick={() => setAutoRefresh((value) => !value)} className={`inline-flex h-[26px] items-center gap-[5px] rounded-[5px] px-[9px] font-bold ${autoRefresh ? "bg-[#e8f9ef] text-[#139657]" : "bg-slate-100 text-slate-500"}`}>
              <Check className="size-[11px]" /> {autoRefresh ? "Auto Refresh Aktif" : "Auto Refresh Nonaktif"}
            </button>
          </div>
          <ToolbarButton onClick={refresh}><RefreshCw className="size-[13px]" />Refresh</ToolbarButton>
          <ToolbarButton onClick={exportCsv}><Download className="size-[13px]" />Export</ToolbarButton>
          <button type="button" onClick={() => setManualOpen(true)} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] bg-[#0875ed] px-[14px] text-[9px] font-bold text-white shadow-[0_5px_14px_rgba(8,117,237,0.2)] hover:bg-[#0667d3]">
            <Plus className="size-[15px]" /> Pesanan Manual
          </button>
        </div>
      </header>

      {notice && (
        <button type="button" onClick={() => setNotice("")} className="mt-[10px] flex w-full items-center justify-between rounded-[6px] border border-[#bfe8d1] bg-[#edf9f2] px-[12px] py-[8px] text-left text-[9px] font-medium text-[#168553]">
          <span>{notice}</span><X className="size-[12px]" />
        </button>
      )}

      <section className="mt-[12px] grid grid-cols-6 gap-[10px]">
        {metrics.map((metric) => <MetricCard key={metric.label} {...metric} />)}
      </section>

      <div className="mt-[12px] grid grid-cols-[minmax(0,1fr)_270px] gap-[12px]">
        <section className="min-w-0 overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,0.04)]">
          <div className="grid grid-cols-[1.55fr_.82fr_.86fr_1fr_1.05fr_.54fr] gap-[8px] border-b border-[#e5eaf0] bg-[#fbfcfe] p-[10px]">
            <label className="relative">
              <span className="sr-only">Cari pesanan</span>
              <Search className="absolute left-[10px] top-1/2 size-[13px] -translate-y-1/2 text-[#71829a]" />
              <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari ID / Customer / UID" className="h-[32px] w-full rounded-[5px] border border-[#dfe5ed] bg-white pl-[30px] pr-[9px] text-[9px] outline-none placeholder:text-[#7b899c] focus:border-[#2b82ef]" />
            </label>
            <FilterSelect value={status} onChange={setStatus} options={["Semua Status", "Pending", "Diproses", "Berhasil", "Gagal", "Komplain"]} />
            <FilterSelect value={provider} onChange={setProvider} options={["Semua Provider", "Digiflazz", "Manual"]} />
            <FilterSelect value={payment} onChange={setPayment} options={["Semua Pembayaran", "QRIS", "Virtual Account", "E-Wallet"]} />
            <label className="relative">
              <span className="sr-only">Pilih tanggal</span>
              <CalendarDays className="absolute left-[10px] top-1/2 size-[13px] -translate-y-1/2 text-[#58708d]" />
              <input value={date} onChange={(event) => setDate(event.target.value)} placeholder="Pilih Tanggal" className="h-[32px] w-full rounded-[5px] border border-[#dfe5ed] bg-white pl-[30px] pr-[7px] text-[8px] text-[#33445d] outline-none placeholder:text-[#7b899c]" />
            </label>
            <button type="button" onClick={resetFilters} className="h-[32px] rounded-[5px] border border-[#dfe5ed] bg-white text-[9px] font-semibold text-[#43536b] hover:bg-[#f6f8fb]">Reset</button>
          </div>

          <div className="flex h-[43px] items-center justify-between px-[12px]">
            <h2 className="text-[12px] font-extrabold text-[#101c34]">Daftar Pesanan</h2>
            <span className="text-[8px] text-[#657690]">Menampilkan 1–{visibleOrders.length} dari 12.450 pesanan</span>
          </div>

          <DesktopOrderTable orders={visibleOrders} selected={selected} onSelect={setSelected} onOpen={setDetailOrder} />

          <footer className="flex min-h-[46px] items-center justify-between border-t border-[#e5eaf0] px-[11px] py-[7px]">
            <div className="flex items-center gap-[8px]">
              <label className="flex items-center gap-[7px] text-[8px] text-[#4f6078]"><Checkbox checked={allSelected} onChange={toggleAll} />Pilih semua</label>
              <select value={bulkAction} onChange={(event) => setBulkAction(event.target.value)} className="h-[30px] w-[142px] rounded-[5px] border border-[#dce3eb] bg-white px-[9px] text-[8px] text-[#42536a] outline-none">
                <option>Aksi massal</option><option>Tandai diproses</option><option>Cetak invoice</option><option>Export terpilih</option>
              </select>
              <button type="button" onClick={applyBulkAction} className="h-[30px] rounded-[5px] bg-[#e8eef6] px-[13px] text-[8px] font-semibold text-[#61718a] hover:bg-[#dce6f1]">Terapkan</button>
            </div>
            <div className="flex items-center gap-[8px] text-[8px] text-[#52627a]">
              <span>Baris per halaman</span>
              <select className="h-[29px] rounded-[5px] border border-[#dce3eb] bg-white px-[8px] outline-none"><option>10</option><option>25</option><option>50</option></select>
              <Pagination />
            </div>
          </footer>
        </section>

        <ActivityPanel />
      </div>

      {detailOrder && <OrderDetailModal order={detailOrder} onClose={() => setDetailOrder(null)} onNotice={setNotice} />}
      {manualOpen && <ManualOrderModal onClose={() => setManualOpen(false)} onSubmit={addManualOrder} />}
    </div>
  );
}

function ToolbarButton({ children, onClick }: { children: ReactNode; onClick(): void }) {
  return <button type="button" onClick={onClick} className="inline-flex h-[34px] items-center gap-[7px] rounded-[5px] border border-[#dbe3ed] bg-white px-[13px] text-[9px] font-bold text-[#34445d] shadow-[0_1px_2px_rgba(15,23,42,0.02)] hover:bg-[#f8fafc]">{children}</button>;
}

function MetricCard({ label, value, change, note, trend, trendTone, tone, Icon }: (typeof metrics)[number]) {
  const iconTone = tone === "green" ? "bg-[#dcf8ea] text-[#08ad65]" : tone === "yellow" ? "bg-[#fff5d9] text-[#f4a700]" : tone === "red" ? "bg-[#ffe8e9] text-[#ed2639]" : "bg-[#e8f2ff] text-[#1675ee]";
  const trendColor = trendTone === "green" ? "text-[#0ba75b]" : "text-[#e9273d]";
  return (
    <article className="flex min-h-[98px] min-w-0 items-start gap-[10px] rounded-[8px] border border-[#dfe6ef] bg-white p-[12px] shadow-[0_1px_4px_rgba(20,33,58,0.04)]">
      <span className={`grid size-[42px] shrink-0 place-items-center rounded-[8px] ${iconTone}`}><Icon className="size-[21px]" strokeWidth={2.4} /></span>
      <div className="min-w-0 pt-[1px]">
        <p className="truncate text-[9px] font-semibold text-[#53657d]">{label}</p>
        <strong className="mt-[3px] block text-[18px] font-black leading-none tracking-[-0.03em] text-[#0d1933]">{value}</strong>
        <div className="mt-[12px] flex items-center gap-[6px] whitespace-nowrap text-[7px]">
          <span className={`font-extrabold ${trendColor}`}>{trend === "up" ? "↑" : "↓"} {change}</span>
          <span className="text-[#77869a]">{note}</span>
        </div>
      </div>
    </article>
  );
}

function FilterSelect({ value, onChange, options }: { value: string; onChange(value: string): void; options: string[] }) {
  return <select aria-label={options[0]} value={value} onChange={(event) => onChange(event.target.value)} className="h-[32px] min-w-0 rounded-[5px] border border-[#dfe5ed] bg-white px-[9px] text-[8px] font-medium text-[#40516a] outline-none focus:border-[#2b82ef]">{options.map((option) => <option key={option}>{option}</option>)}</select>;
}

function DesktopOrderTable({ orders, selected, onSelect, onOpen }: { orders: Order[]; selected: string[]; onSelect(ids: string[]): void; onOpen(order: Order): void }) {
  function toggle(id: string) {
    onSelect(selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id]);
  }
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[910px] table-fixed text-left">
        <thead className="border-y border-[#e3e8ef] bg-[#f4f7fa] text-[7px] font-bold text-[#52647e]">
          <tr>
            <th className="w-[32px] px-[10px] py-[8px]"></th><th className="w-[116px] py-[8px]">ID Pesanan</th><th className="w-[118px] py-[8px]">Pelanggan</th><th className="w-[135px] py-[8px]">Produk</th><th className="w-[114px] py-[8px]">Tujuan</th><th className="w-[97px] py-[8px]">Pembayaran</th><th className="w-[69px] py-[8px]">Provider</th><th className="w-[72px] py-[8px]">Total</th><th className="w-[69px] py-[8px]">Status</th><th className="w-[82px] py-[8px] text-center">Aksi</th>
          </tr>
        </thead>
        <tbody>
          {orders.length ? orders.map((order) => (
            <tr key={order.id} className="border-b border-[#e7ebf0] text-[7.5px] text-[#34465f] hover:bg-[#f9fbfd]">
              <td className="px-[10px] py-[6px]"><Checkbox checked={selected.includes(order.id)} onChange={() => toggle(order.id)} /></td>
              <td className="py-[6px] pr-[7px]"><button type="button" onClick={() => onOpen(order)} className="truncate font-bold text-[#0873dd] hover:underline">{order.id}</button></td>
              <td className="py-[6px] pr-[7px]"><div className="flex items-center gap-[6px]"><span className="grid size-[22px] shrink-0 place-items-center rounded-full bg-[#eef3f9] text-[#8090a5]"><UserRound className="size-[12px]" /></span><span className="min-w-0"><strong className="block truncate text-[7.5px] text-[#253750]">{order.customer}</strong><span className="block truncate text-[6.5px] text-[#6f8096]">{order.phone}</span></span></div></td>
              <td className="py-[6px] pr-[7px]"><div className="flex items-center gap-[7px]"><ProductThumb code={order.productCode} /><span className="min-w-0"><strong className="block truncate text-[#24364f]">{order.product}</strong><span className="block truncate text-[6.5px] text-[#6f8096]">{order.packageName}</span></span></div></td>
              <td className="py-[6px] pr-[7px]"><strong className="block truncate font-semibold">{order.destination}</strong>{order.destinationNote && <span className="block truncate text-[6.5px] text-[#72839a]">({order.destinationNote})</span>}</td>
              <td className="py-[6px] pr-[7px]"><span className="flex items-center gap-[5px]"><PaymentIcon payment={order.payment} /><span className="truncate font-semibold">{order.payment}</span></span></td>
              <td className="truncate py-[6px] pr-[7px]">{order.provider}</td>
              <td className="whitespace-nowrap py-[6px] pr-[7px] font-semibold">{formatRupiah(order.total)}</td>
              <td className="py-[6px] pr-[7px]"><StatusBadge status={order.status} /></td>
              <td className="py-[6px]"><div className="flex items-center justify-center gap-[6px]"><button type="button" onClick={() => onOpen(order)} className="h-[26px] rounded-[4px] bg-[#e8f2ff] px-[12px] font-bold text-[#0873dd] hover:bg-[#d9eaff]">Detail</button><button type="button" aria-label={`Menu ${order.id}`} className="grid size-[26px] place-items-center rounded-[4px] border border-[#dce3eb] text-[#475b74] hover:bg-[#f4f7fa]"><MoreVertical className="size-[12px]" /></button></div></td>
            </tr>
          )) : (
            <tr><td colSpan={10} className="py-[42px] text-center text-[9px] text-[#7a899c]">Tidak ada pesanan yang cocok dengan filter.</td></tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function Checkbox({ checked, onChange }: { checked: boolean; onChange(): void }) {
  return <button type="button" aria-pressed={checked} onClick={onChange} className={`grid size-[14px] shrink-0 place-items-center rounded-[3px] border ${checked ? "border-[#1475e8] bg-[#1475e8] text-white" : "border-[#cfd8e3] bg-white text-transparent"}`}><Check className="size-[9px]" strokeWidth={3} /></button>;
}

function ProductThumb({ code }: { code: string }) {
  const tones: Record<string, string> = { ML: "from-[#235ab9] to-[#f0b44a]", FF: "from-[#f59e0b] to-[#402311]", PUBG: "from-[#2a2019] to-[#c47a1a]", VAL: "from-[#111827] to-[#e9485f]", GI: "from-[#8ca4df] to-[#efe3cf]", STEAM: "from-[#183550] to-[#1671a7]" };
  return <span className={`grid size-[28px] shrink-0 place-items-center overflow-hidden rounded-[5px] bg-gradient-to-br ${tones[code] || "from-blue-500 to-indigo-700"} text-[5px] font-black text-white shadow-sm`}>{code}</span>;
}

function PaymentIcon({ payment }: { payment: string }) {
  if (payment.includes("QRIS")) return <QrCode className="size-[12px] shrink-0 text-[#17243a]" />;
  if (payment.includes("VA")) return <Landmark className="size-[12px] shrink-0 text-[#0877db]" />;
  return <WalletCards className="size-[12px] shrink-0 text-[#159be0]" />;
}

function StatusBadge({ status }: { status: OrderStatus }) {
  const styles: Record<OrderStatus, string> = {
    Berhasil: "bg-[#dff8ea] text-[#11975b]",
    Diproses: "bg-[#fff0d4] text-[#e08a00]",
    Pending: "bg-[#fff3ce] text-[#d58a00]",
    Gagal: "bg-[#ffe5e7] text-[#dd3347]",
    Komplain: "bg-[#ffe1ef] text-[#d62972]",
  };
  return <span className={`inline-flex rounded-[4px] px-[7px] py-[4px] text-[6.5px] font-bold ${styles[status]}`}>{status}</span>;
}

function Pagination() {
  return (
    <nav aria-label="Pagination" className="flex items-center gap-[4px]">
      <button type="button" className="grid size-[26px] place-items-center rounded-[4px] border border-[#e0e6ee] bg-[#f7f9fb] text-[#9aa7b7]"><ChevronLeft className="size-[12px]" /></button>
      {[1, 2, 3, 4, 5].map((page) => <button type="button" key={page} className={`grid size-[26px] place-items-center rounded-[4px] border text-[8px] font-bold ${page === 1 ? "border-[#0875ed] bg-[#0875ed] text-white" : "border-[#dfe5ed] bg-white text-[#52637b]"}`}>{page}</button>)}
      <span className="px-[3px]">...</span><button type="button" className="h-[26px] rounded-[4px] border border-[#dfe5ed] bg-white px-[9px] font-semibold">1.245</button><button type="button" className="grid size-[26px] place-items-center rounded-[4px] border border-[#dfe5ed] bg-white"><ChevronRight className="size-[12px]" /></button>
    </nav>
  );
}

function ActivityPanel() {
  return (
    <aside className="overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,0.04)]">
      <div className="flex h-[45px] items-center justify-between px-[12px]"><h2 className="text-[12px] font-extrabold text-[#101c34]">Aktivitas Terbaru</h2><button type="button" className="text-[8px] font-semibold text-[#0875e3] hover:underline">Lihat Semua</button></div>
      <div className="relative px-[12px] pb-[8px]">
        <span className="absolute bottom-[22px] left-[18px] top-[15px] w-px bg-[#dbe4ee]" />
        {activities.map((activity, index) => <ActivityItem key={`${activity.invoice}-${index}`} activity={activity} />)}
      </div>
    </aside>
  );
}

function ActivityItem({ activity }: { activity: Activity }) {
  const tones = { blue: "bg-[#e7f2ff] text-[#0874e4]", green: "bg-[#e1f8eb] text-[#11a660]", yellow: "bg-[#fff2d3] text-[#f1a400]", red: "bg-[#ffe4e6] text-[#e62b3d]", pink: "bg-[#ffe3f0] text-[#e82b7c]" };
  const dot = { blue: "bg-[#0874e4]", green: "bg-[#11a660]", yellow: "bg-[#f1a400]", red: "bg-[#e62b3d]", pink: "bg-[#e82b7c]" };
  const Icon = activity.Icon;
  return (
    <div className="relative flex min-h-[49px] gap-[9px] pl-[11px]">
      <span className={`absolute left-0 top-[14px] size-[5px] rounded-full ring-[3px] ring-white ${dot[activity.tone]}`} />
      <span className={`mt-[5px] grid size-[27px] shrink-0 place-items-center rounded-full ${tones[activity.tone]}`}><Icon className="size-[13px]" strokeWidth={2.5} /></span>
      <div className="min-w-0 flex-1 pt-[4px]"><strong className="block truncate text-[7.5px] text-[#25364e]">{activity.title}</strong><span className="block truncate text-[6.5px] text-[#63758d]">{activity.invoice}</span><span className="block truncate text-[6.5px] text-[#63758d]">{activity.detail}</span></div>
      <time className="shrink-0 pt-[6px] text-[6px] text-[#75869a]">{activity.time}</time>
    </div>
  );
}

function OrderDetailModal({ order, onClose, onNotice }: { order: Order; onClose(): void; onNotice(message: string): void }) {
  async function copyInvoice() {
    try { await navigator.clipboard.writeText(order.id); onNotice("Nomor invoice berhasil disalin."); } catch { onNotice(`Invoice: ${order.id}`); }
    onClose();
  }
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-[#071426]/55 p-[24px]" role="dialog" aria-modal="true" aria-label="Detail Pesanan">
      <div className="w-full max-w-[620px] overflow-hidden rounded-[10px] bg-white shadow-2xl">
        <div className="flex items-start justify-between border-b border-[#e4e9ef] px-[20px] py-[16px]"><div><h2 className="text-[16px] font-black text-[#101c34]">Detail Pesanan</h2><p className="mt-[3px] text-[9px] text-[#718198]">{order.id}</p></div><button type="button" onClick={onClose} className="grid size-[30px] place-items-center rounded-[5px] text-[#63758b] hover:bg-[#f2f5f8]"><X className="size-[16px]" /></button></div>
        <div className="grid grid-cols-2 gap-[20px] p-[20px] text-[9px]">
          <DetailSection title="Informasi Pelanggan"><DetailLine label="Nama" value={order.customer} /><DetailLine label="Telepon" value={order.phone} /><DetailLine label="Tujuan" value={`${order.destination} ${order.destinationNote}`} /></DetailSection>
          <DetailSection title="Informasi Produk"><DetailLine label="Produk" value={order.product} /><DetailLine label="Nominal" value={order.packageName} /><DetailLine label="Provider" value={order.provider} /></DetailSection>
          <DetailSection title="Pembayaran"><DetailLine label="Metode" value={order.payment} /><DetailLine label="Total" value={formatRupiah(order.total)} /><div className="mt-[8px]"><StatusBadge status={order.status} /></div></DetailSection>
          <DetailSection title="Timeline"><p className="flex items-center gap-[7px] text-[#52647b]"><CheckCircle2 className="size-[13px] text-[#12a45f]" />Pesanan dibuat oleh sistem</p><p className="mt-[8px] flex items-center gap-[7px] text-[#52647b]"><Clock3 className="size-[13px] text-[#f0a400]" />Menunggu pembaruan berikutnya</p></DetailSection>
        </div>
        <div className="flex items-center justify-between border-t border-[#e4e9ef] bg-[#fafbfd] px-[20px] py-[12px]"><span className="text-[9px] font-bold text-[#2e4058]">Aksi Admin</span><div className="flex gap-[8px]"><button type="button" onClick={copyInvoice} className="inline-flex h-[32px] items-center gap-[6px] rounded-[5px] border border-[#dce3eb] bg-white px-[12px] text-[8px] font-bold text-[#40516a]"><Copy className="size-[12px]" />Copy Invoice</button><button type="button" onClick={onClose} className="inline-flex h-[32px] items-center gap-[6px] rounded-[5px] bg-[#0875ed] px-[13px] text-[8px] font-bold text-white"><Eye className="size-[12px]" />Selesai</button></div></div>
      </div>
    </div>
  );
}

function DetailSection({ title, children }: { title: string; children: ReactNode }) {
  return <section className="rounded-[7px] border border-[#e2e8ef] p-[12px]"><h3 className="mb-[9px] text-[10px] font-extrabold text-[#17253d]">{title}</h3>{children}</section>;
}

function DetailLine({ label, value }: { label: string; value: string }) {
  return <div className="mt-[6px] flex justify-between gap-[12px]"><span className="text-[#718198]">{label}</span><strong className="text-right text-[#304158]">{value}</strong></div>;
}

function ManualOrderModal({ onClose, onSubmit }: { onClose(): void; onSubmit(event: FormEvent<HTMLFormElement>): void }) {
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-[#071426]/55 p-[24px]" role="dialog" aria-modal="true" aria-label="Pesanan Manual">
      <form onSubmit={onSubmit} className="w-full max-w-[560px] overflow-hidden rounded-[10px] bg-white shadow-2xl">
        <div className="flex items-start justify-between border-b border-[#e4e9ef] px-[20px] py-[16px]"><div><h2 className="text-[16px] font-black text-[#101c34]">Pesanan Manual</h2><p className="mt-[3px] text-[9px] text-[#718198]">Tambahkan transaksi baru langsung dari panel admin.</p></div><button type="button" onClick={onClose} className="grid size-[30px] place-items-center rounded-[5px] text-[#63758b] hover:bg-[#f2f5f8]"><X className="size-[16px]" /></button></div>
        <div className="grid grid-cols-2 gap-[12px] p-[20px]">
          <FormField label="Nama pelanggan" name="customer" placeholder="Contoh: Aldy" required />
          <FormField label="Nomor telepon" name="phone" placeholder="08xxxxxxxxxx" required />
          <FormField label="Produk" name="product" placeholder="Mobile Legends" required />
          <FormField label="Paket / nominal" name="packageName" placeholder="86 Diamond" required />
          <FormField label="Tujuan / User ID" name="destination" placeholder="123456789" required />
          <FormField label="Total pembayaran" name="total" placeholder="20000" type="number" required />
          <label className="col-span-2 text-[8px] font-bold text-[#42536b]">Metode pembayaran<select name="payment" className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] bg-white px-[10px] text-[9px] font-medium outline-none focus:border-[#2380ec]"><option>QRIS DOKU</option><option>DOKU VA BCA</option><option>DOKU GoPay</option></select></label>
        </div>
        <div className="flex justify-end gap-[8px] border-t border-[#e4e9ef] bg-[#fafbfd] px-[20px] py-[12px]"><button type="button" onClick={onClose} className="h-[34px] rounded-[5px] border border-[#dce3eb] bg-white px-[14px] text-[9px] font-bold text-[#40516a]">Batal</button><button type="submit" className="h-[34px] rounded-[5px] bg-[#0875ed] px-[16px] text-[9px] font-bold text-white">Simpan Pesanan</button></div>
      </form>
    </div>
  );
}

function FormField({ label, ...props }: { label: string; name: string; placeholder: string; type?: string; required?: boolean }) {
  return <label className="text-[8px] font-bold text-[#42536b]">{label}<input {...props} className="mt-[5px] h-[36px] w-full rounded-[5px] border border-[#dce3eb] px-[10px] text-[9px] font-medium outline-none placeholder:text-[#9aa6b5] focus:border-[#2380ec]" /></label>;
}

function formatRupiah(value: number) {
  return `Rp ${new Intl.NumberFormat("id-ID").format(value)}`;
}

function csvCell(value: string | number) {
  return `"${String(value).replaceAll('"', '""')}"`;
}
