"use client";

import { useMemo, useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  AlertTriangle,
  Box,
  ChevronLeft,
  ChevronRight,
  FileText,
  Link2,
  MoreVertical,
  RefreshCw,
  Search,
  Store,
  Wallet,
  Wifi,
  X,
} from "lucide-react";

type MonitorItem = {
  id: number;
  product: string;
  nominal: string;
  sku: string;
  cost: number;
  seller: "Normal" | "Seller Off";
  sync: "Auto" | "Manual";
  code: string;
};

const monitorItems: MonitorItem[] = [
  { id: 1, product: "Mobile Legends", nominal: "50 Diamonds", sku: "ML5", cost: 1050, seller: "Normal", sync: "Auto", code: "ML" },
  { id: 2, product: "Free Fire", nominal: "70 Diamonds", sku: "FF70", cost: 9900, seller: "Normal", sync: "Auto", code: "FF" },
  { id: 3, product: "PUBG Mobile", nominal: "325 UC", sku: "PUBGM325", cost: 75000, seller: "Normal", sync: "Auto", code: "PUBG" },
  { id: 4, product: "Valorant", nominal: "100 VP", sku: "VAL100", cost: 16000, seller: "Normal", sync: "Auto", code: "VAL" },
  { id: 5, product: "Genshin Impact", nominal: "60 Genesis", sku: "GI60", cost: 15200, seller: "Normal", sync: "Auto", code: "GI" },
  { id: 6, product: "Steam Wallet", nominal: "50.000 Wallet", sku: "STEAM50", cost: 52000, seller: "Seller Off", sync: "Manual", code: "ST" },
  { id: 7, product: "Google Play", nominal: "100.000 IDR", sku: "GP100", cost: 98500, seller: "Normal", sync: "Auto", code: "GP" },
  { id: 8, product: "Roblox", nominal: "400 Robux", sku: "RBX400", cost: 62000, seller: "Normal", sync: "Auto", code: "RBX" },
];

const transactions = [
  ["INV/20250424/0012", "ML5", "Mobile Legends", "50 Diamonds", "Berhasil", "24 Apr 2025 10:24", "Transaksi berhasil"],
  ["INV/20250424/0011", "FF70", "Free Fire", "70 Diamonds", "Berhasil", "24 Apr 2025 09:18", "Sukses"],
  ["INV/20250424/0010", "PUBGM325", "PUBG Mobile", "325 UC", "Pending", "24 Apr 2025 08:55", "Sedang diproses"],
  ["INV/20250423/0099", "GP100", "Google Play", "100.000 IDR", "Berhasil", "23 Apr 2025 22:14", "Transaksi berhasil"],
  ["INV/20250423/0098", "RBX400", "Roblox", "400 Robux", "Gagal", "23 Apr 2025 21:07", "Limit transaksi"],
];

const syncHistory = [
  ["Sync berhasil", "24 Apr 2025 10:24", "2 menit lalu", "green"],
  ["Update harga produk", "24 Apr 2025 09:18", "1 jam lalu", "green"],
  ["Mapping SKU selesai", "24 Apr 2025 08:55", "3 jam lalu", "green"],
  ["Sync produk gagal", "24 Apr 2025 06:21", "6 jam lalu", "red"],
  ["Cek harga selesai", "23 Apr 2025 21:07", "1 hari lalu", "green"],
];

export function AdminDigiflazzWorkspace() {
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("Semua Kategori");
  const [seller, setSeller] = useState("Semua Status Seller");
  const [sync, setSync] = useState("Semua Sync");
  const [notice, setNotice] = useState("");
  const [dialog, setDialog] = useState<{ title: string; item?: MonitorItem } | null>(null);
  const [lastSync, setLastSync] = useState("24 Apr 2025 10:24");

  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return monitorItems.filter((item) =>
      (!term || `${item.product} ${item.nominal} ${item.sku}`.toLowerCase().includes(term)) &&
      (category === "Semua Kategori" || (category === "Game" ? !["Steam Wallet", "Google Play"].includes(item.product) : ["Steam Wallet", "Google Play"].includes(item.product))) &&
      (seller === "Semua Status Seller" || item.seller === seller) &&
      (sync === "Semua Sync" || item.sync === sync),
    );
  }, [category, query, seller, sync]);

  function syncPricelist() {
    setLastSync("Baru saja");
    setNotice("Simulasi sync pricelist selesai. Backend Digiflazz belum dihubungkan.");
  }

  function refresh() {
    setNotice("Tampilan monitoring berhasil diperbarui.");
  }

  return (
    <div className="admin-digiflazz-reference min-w-0 text-[#14213a]">
      <header className="flex items-start justify-between gap-[16px]">
        <div><h1 className="text-[24px] font-black tracking-[-0.04em] text-[#0c1933]">Digiflazz</h1><p className="mt-[3px] text-[10px] text-[#62748c]">Pantau operasional provider Digiflazz dan sinkronisasi data untuk LFAMILIA.</p></div>
        <div className="flex gap-[9px]"><button type="button" onClick={syncPricelist} className="inline-flex h-[36px] items-center gap-[7px] rounded-[5px] bg-[#0875ed] px-[16px] text-[9px] font-bold text-white shadow-[0_5px_14px_rgba(8,117,237,.2)]"><RefreshCw className="size-[13px]" />Sync Pricelist</button><button type="button" onClick={refresh} className="inline-flex h-[36px] items-center gap-[7px] rounded-[5px] border border-[#dce3eb] bg-white px-[15px] text-[9px] font-bold text-[#35475f]"><RefreshCw className="size-[13px]" />Refresh</button></div>
      </header>

      {notice && <button type="button" onClick={() => setNotice("")} className="mt-[9px] flex w-full items-center justify-between rounded-[5px] border border-[#bce3ce] bg-[#eef9f3] px-[11px] py-[7px] text-left text-[8px] font-semibold text-[#158755]"><span>{notice}</span><X className="size-[11px]" /></button>}

      <section className="mt-[13px] grid grid-cols-5 gap-[10px]">
        <Metric label="Status API" value="Online" note="Terhubung normal" tone="green" Icon={Wifi} />
        <Metric label="Saldo Digiflazz" value="Rp 4.570.800" note="↑  +5,2% dari kemarin" tone="blue" Icon={Wallet} />
        <Metric label="SKU Aktif" value="1.248" note="↑  +2,1% dari bulan lalu" tone="blue" Icon={Box} />
        <Metric label="Sync Terakhir" value={lastSync} note="●  Berhasil" tone="blue" Icon={FileText} compact />
        <Metric label="Produk Bermasalah" value="12" note="↓  -33,3% dari kemarin" tone="red" Icon={AlertTriangle} />
      </section>

      <div className="mt-[12px] grid grid-cols-[minmax(0,1fr)_280px] gap-[12px]">
        <section className="min-w-0 overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]">
          <div className="flex items-start justify-between px-[14px] pt-[12px]"><div><h2 className="text-[14px] font-extrabold text-[#101d35]">Monitoring Produk Digiflazz</h2><p className="mt-[2px] text-[8px] text-[#687a91]">Pantau daftar produk, harga, dan status seller secara real-time.</p></div><button type="button" onClick={() => setDialog({ title: "Semua Produk Digiflazz" })} className="h-[29px] rounded-[4px] border border-[#dce3eb] bg-white px-[10px] text-[7.5px] font-bold text-[#0875df]">Lihat Semua</button></div>
          <div className="grid grid-cols-[1.55fr_.8fr_.95fr_.75fr] gap-[7px] px-[14px] py-[10px]"><label className="relative"><Search className="absolute left-[9px] top-1/2 size-[12px] -translate-y-1/2 text-[#74849a]" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari nama produk, SKU, atau nominal..." className="h-[32px] w-full rounded-[4px] border border-[#dce3eb] pl-[28px] pr-[8px] text-[8px] outline-none placeholder:text-[#8290a2] focus:border-[#2680eb]" /></label><FilterSelect value={category} onChange={setCategory} options={["Semua Kategori", "Game", "Voucher"]} /><FilterSelect value={seller} onChange={setSeller} options={["Semua Status Seller", "Normal", "Seller Off"]} /><FilterSelect value={sync} onChange={setSync} options={["Semua Sync", "Auto", "Manual"]} /></div>
          <MonitorTable items={visible} onOpen={(item) => setDialog({ title: "Detail Produk Digiflazz", item })} />
          <footer className="flex h-[52px] items-center justify-between px-[14px] text-[7.5px] text-[#586a81]"><span>Menampilkan 1–{visible.length} dari 1.248 produk</span><Pagination /><FilterSelect value="8 per halaman" onChange={() => {}} options={["8 per halaman", "16 per halaman", "32 per halaman"]} /></footer>
        </section>

        <aside className="space-y-[10px]">
          <section className="rounded-[8px] border border-[#dfe6ef] bg-white p-[13px]"><h2 className="text-[13px] font-extrabold">Aksi Cepat</h2><p className="mt-[2px] text-[8px] text-[#687a91]">Fitur penting untuk operasional Digiflazz.</p><div className="mt-[10px] space-y-[7px]"><QuickAction primary Icon={RefreshCw} onClick={syncPricelist}>Sync Pricelist</QuickAction><QuickAction Icon={Link2} onClick={() => setDialog({ title: "Mapping SKU" })}>Mapping SKU</QuickAction><QuickAction Icon={Store} onClick={() => setDialog({ title: "Monitor Seller" })}>Monitor Seller</QuickAction><QuickAction Icon={FileText} onClick={() => setDialog({ title: "Log Digiflazz" })}>Lihat Log</QuickAction></div></section>
          <section className="rounded-[8px] border border-[#dfe6ef] bg-white p-[13px]"><div className="flex items-center justify-between"><h2 className="text-[13px] font-extrabold">Status Sinkronisasi</h2><button type="button" onClick={() => setDialog({ title: "Riwayat Sinkronisasi" })} className="text-[7.5px] font-bold text-[#0875df]">Lihat Semua</button></div><div className="mt-[8px]">{syncHistory.map(([title, date, time, tone]) => <div key={title} className="grid min-h-[47px] grid-cols-[9px_1fr_auto] gap-[7px] border-t border-[#edf0f4] pt-[8px] first:border-0"><span className={`mt-[4px] size-[7px] rounded-full ${tone === "red" ? "bg-red-500" : "bg-emerald-500"}`} /><span><strong className="block text-[7.5px]">{title}</strong><small className="mt-[2px] block text-[6.5px] text-[#708198]">{date}</small></span><small className="text-[6.5px] text-[#708198]">{time}</small></div>)}</div></section>
        </aside>
      </div>

      <TransactionTable />
      {dialog && <OperationDialog title={dialog.title} item={dialog.item} onClose={() => setDialog(null)} />}
    </div>
  );
}

function Metric({ label, value, note, tone, Icon, compact }: { label: string; value: string; note: string; tone: "green" | "blue" | "red"; Icon: LucideIcon; compact?: boolean }) {
  const iconTone = tone === "green" ? "bg-[#dcf8e9] text-[#0eac64]" : tone === "red" ? "bg-[#ffe7e8] text-[#ef263c]" : "bg-[#e7f2ff] text-[#0875ed]";
  const valueTone = tone === "green" ? "text-[#10a75f]" : tone === "red" ? "text-[#e72d40]" : "text-[#101c35]";
  return <article className="flex min-h-[92px] items-start gap-[10px] rounded-[8px] border border-[#dfe6ef] bg-white p-[12px] shadow-[0_1px_4px_rgba(20,33,58,.04)]"><span className={`grid size-[42px] shrink-0 place-items-center rounded-[8px] ${iconTone}`}><Icon className="size-[21px]" strokeWidth={2.3} /></span><div className="min-w-0"><p className="truncate text-[8.5px] font-semibold text-[#53657d]">{label}</p><strong className={`mt-[4px] block truncate font-black tracking-[-0.03em] ${compact ? "text-[13px]" : "text-[17px]"} ${valueTone}`}>{value}</strong><p className={`mt-[10px] truncate text-[7px] font-semibold ${tone === "red" ? "text-red-500" : "text-emerald-600"}`}>{note}</p></div></article>;
}

function MonitorTable({ items, onOpen }: { items: MonitorItem[]; onOpen(item: MonitorItem): void }) {
  return <div className="overflow-x-auto"><table className="w-full min-w-[760px] table-fixed text-left"><thead className="bg-[#f2f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[30px] px-[12px] py-[8px]">#</th><th className="w-[145px]">Produk LFAMILIA</th><th className="w-[105px]">Nominal</th><th className="w-[95px]">SKU Digiflazz</th><th className="w-[100px]">Harga Modal</th><th className="w-[90px]">Status Seller</th><th className="w-[70px]">Sync</th><th className="w-[95px]">Aksi</th></tr></thead><tbody>{items.map((item, index) => <tr key={item.id} className="border-t border-[#e5eaf0] text-[7px] text-[#35475f]"><td className="px-[12px] py-[6px]">{index + 1}</td><td><div className="flex items-center gap-[7px]"><ProductMark code={item.code} /><strong className="truncate">{item.product}</strong></div></td><td>{item.nominal}</td><td className="font-semibold">{item.sku}</td><td>{formatRupiah(item.cost)}</td><td><span className={`rounded-[4px] px-[8px] py-[4px] font-bold ${item.seller === "Normal" ? "bg-[#ddf8e8] text-[#15955a]" : "bg-[#fff0d8] text-[#e18a00]"}`}>{item.seller}</span></td><td><span className={`rounded-[4px] px-[8px] py-[4px] font-bold ${item.sync === "Auto" ? "bg-[#ddf8e8] text-[#15955a]" : "bg-[#e9f1ff] text-[#0875df]"}`}>{item.sync}</span></td><td><div className="flex items-center gap-[7px]"><button type="button" onClick={() => onOpen(item)} className="h-[27px] rounded-[4px] border border-[#dce3eb] bg-white px-[11px] font-bold text-[#0875df]">Lihat</button><MoreVertical className="size-[12px]" /></div></td></tr>)}{!items.length && <tr><td colSpan={8} className="py-[35px] text-center text-[8px] text-[#718198]">Tidak ada produk yang cocok.</td></tr>}</tbody></table></div>;
}

function TransactionTable() {
  return <section className="mt-[12px] overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]"><div className="flex items-start justify-between px-[14px] py-[11px]"><div><h2 className="text-[13px] font-extrabold">Transaksi Provider Terbaru</h2><p className="mt-[2px] text-[8px] text-[#687a91]">Riwayat transaksi terbaru melalui Digiflazz.</p></div><button type="button" className="text-[7.5px] font-bold text-[#0875df]">Lihat Semua</button></div><div className="overflow-x-auto border-t border-[#e5eaf0]"><table className="w-full min-w-[850px] table-fixed text-left"><thead className="bg-[#f2f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[30px] px-[12px] py-[8px]">#</th><th className="w-[145px]">Invoice LFAMILIA</th><th className="w-[105px]">SKU Digiflazz</th><th className="w-[130px]">Produk</th><th className="w-[120px]">Nominal</th><th className="w-[95px]">Status</th><th className="w-[145px]">Waktu</th><th>Response</th></tr></thead><tbody>{transactions.map((row, index) => <tr key={row[0]} className="border-t border-[#e5eaf0] text-[7px] text-[#35475f]"><td className="px-[12px] py-[7px]">{index + 1}</td><td className="font-bold text-[#0875df]">{row[0]}</td><td>{row[1]}</td><td>{row[2]}</td><td>{row[3]}</td><td><TransactionStatus value={row[4]} /></td><td>{row[5]}</td><td>{row[6]}</td></tr>)}</tbody></table></div></section>;
}

function OperationDialog({ title, item, onClose }: { title: string; item?: MonitorItem; onClose(): void }) {
  return <div className="fixed inset-0 z-50 grid place-items-center bg-[#071426]/55 p-[24px]" role="dialog" aria-modal="true" aria-label={title}><section className="w-full max-w-[510px] overflow-hidden rounded-[9px] bg-white shadow-2xl"><header className="flex items-start justify-between border-b border-[#e4e9ef] px-[16px] py-[13px]"><div><h2 className="text-[14px] font-black">{title}</h2><p className="mt-[2px] text-[8px] text-[#6d7d92]">Tampilan operasional Digiflazz. Backend akan dihubungkan setelah seluruh UI admin selesai.</p></div><button type="button" onClick={onClose} className="grid size-[27px] place-items-center rounded-[4px] hover:bg-[#f2f5f8]"><X className="size-[14px]" /></button></header><div className="p-[16px]">{item ? <div className="grid grid-cols-2 gap-[9px]"><Detail label="Produk" value={item.product} /><Detail label="Nominal" value={item.nominal} /><Detail label="SKU Digiflazz" value={item.sku} /><Detail label="Harga Modal" value={formatRupiah(item.cost)} /><Detail label="Status Seller" value={item.seller} /><Detail label="Mode Sync" value={item.sync} /></div> : <div className="rounded-[6px] border border-dashed border-[#cbd6e2] bg-[#f8fafc] px-[14px] py-[35px] text-center text-[9px] text-[#687a91]">Panel {title} sudah disiapkan sebagai rancangan frontend.</div>}</div><footer className="flex justify-end border-t border-[#e4e9ef] bg-[#fafbfd] px-[16px] py-[11px]"><button type="button" onClick={onClose} className="h-[32px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white">Selesai</button></footer></section></div>;
}

function QuickAction({ children, Icon, onClick, primary }: { children: ReactNode; Icon: LucideIcon; onClick(): void; primary?: boolean }) { return <button type="button" onClick={onClick} className={`inline-flex h-[37px] w-full items-center justify-center gap-[8px] rounded-[5px] border text-[9px] font-bold ${primary ? "border-[#0875ed] bg-[#0875ed] text-white" : "border-[#dce3eb] bg-white text-[#1f3550]"}`}><Icon className={`size-[15px] ${primary ? "" : "text-[#0875ed]"}`} />{children}</button>; }
function FilterSelect({ value, onChange, options }: { value: string; onChange(value: string): void; options: string[] }) { return <select value={value} onChange={(event) => onChange(event.target.value)} className="h-[32px] min-w-0 rounded-[4px] border border-[#dce3eb] bg-white px-[8px] text-[7.5px] font-semibold text-[#40516a] outline-none">{options.map((option) => <option key={option}>{option}</option>)}</select>; }
function Pagination() { return <nav className="flex items-center gap-[4px]"><Page><ChevronLeft /></Page>{[1,2,3,4,5].map((value) => <Page key={value} active={value === 1}>{value}</Page>)}<span className="px-[3px]">...</span><Page>156</Page><Page><ChevronRight /></Page></nav>; }
function Page({ children, active }: { children: ReactNode; active?: boolean }) { return <button type="button" className={`grid size-[27px] place-items-center rounded-[4px] border text-[7.5px] font-bold [&_svg]:size-[11px] ${active ? "border-[#0875ed] bg-[#0875ed] text-white" : "border-[#dde4ec] bg-white text-[#52647b]"}`}>{children}</button>; }
function ProductMark({ code }: { code: string }) { const tones: Record<string,string> = { ML:"from-blue-600 to-amber-400", FF:"from-amber-500 to-orange-900", PUBG:"from-stone-800 to-amber-600", VAL:"from-slate-900 to-rose-500", GI:"from-blue-400 to-indigo-700", ST:"from-slate-800 to-sky-600", GP:"from-green-500 to-blue-500", RBX:"from-slate-700 to-slate-950" }; return <span className={`grid size-[25px] shrink-0 place-items-center rounded-[5px] bg-gradient-to-br ${tones[code] || "from-blue-500 to-indigo-700"} text-[5px] font-black text-white`}>{code}</span>; }
function TransactionStatus({ value }: { value: string }) { const style = value === "Berhasil" ? "bg-[#ddf8e8] text-[#15955a]" : value === "Pending" ? "bg-[#fff0d8] text-[#df8900]" : "bg-[#ffe5e7] text-[#dd3347]"; return <span className={`inline-flex min-w-[61px] justify-center rounded-[4px] px-[7px] py-[4px] font-bold ${style}`}>{value}</span>; }
function Detail({ label, value }: { label: string; value: string }) { return <div className="rounded-[5px] border border-[#e0e6ed] p-[10px]"><span className="block text-[7px] text-[#718198]">{label}</span><strong className="mt-[3px] block text-[9px]">{value}</strong></div>; }
function formatRupiah(value: number) { return `Rp ${new Intl.NumberFormat("id-ID").format(value)}`; }
