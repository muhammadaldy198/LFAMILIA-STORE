"use client";

import { useEffect, useMemo, useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  AlertTriangle,
  Box,
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
  seller: "Normal" | "Peringatan" | "Seller Off" | "Belum Dicek";
  sync: "Auto" | "Manual";
  code: string;
  alertReason?: string;
  lastCheckedAt?: string;
};

type MonitorResponse = {
  items: Array<{ packageId: number; productName: string; packageLabel: string; providerSku: string; currentPrice: number | null; health: "healthy" | "warning" | "critical" | "unknown"; alertReason: string | null; lastCheckedAt: string | null }>;
  summary: { total: number; healthy: number; warning: number; critical: number; unknown: number };
  api?: { ready: boolean; balance: number | null; environment: string | null; reason: string | null };
};

type ProviderOrder = { reference_id: string; package_sku: string; product_name: string; package_label: string; fulfillment_status: string; provider_status: string | null; provider_message: string | null; created_at: string; provider_code: string | null };

export function AdminDigiflazzWorkspace() {
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("Semua Kategori");
  const [seller, setSeller] = useState("Semua Status Seller");
  const [sync, setSync] = useState("Semua Sync");
  const [notice, setNotice] = useState("");
  const [dialog, setDialog] = useState<{ title: string; item?: MonitorItem } | null>(null);
  const [lastSync, setLastSync] = useState("Belum pernah");
  const [monitorItems, setMonitorItems] = useState<MonitorItem[]>([]);
  const [transactions, setTransactions] = useState<string[][]>([]);
  const [syncHistory, setSyncHistory] = useState<string[][]>([]);
  const [summary, setSummary] = useState({ total: 0, healthy: 0, warning: 0, critical: 0, unknown: 0 });
  const [api, setApi] = useState({ ready: false, balance: null as number | null, environment: null as string | null, reason: null as string | null });
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [error, setError] = useState("");

  async function loadOperationalData() {
    setLoading(true); setError("");
    try {
      const [monitorResponse, ordersResponse] = await Promise.all([
        fetch("/api/panel/digiflazz-monitor", { cache: "no-store" }),
        fetch("/api/panel/orders", { cache: "no-store" }),
      ]);
      const monitor = await monitorResponse.json().catch(() => ({})) as MonitorResponse & { error?: string };
      const orderPayload = await ordersResponse.json().catch(() => ({})) as { orders?: ProviderOrder[]; error?: string };
      if (!monitorResponse.ok) throw new Error(monitor.error || "Monitor Digiflazz gagal dimuat.");
      if (!ordersResponse.ok) throw new Error(orderPayload.error || "Transaksi provider gagal dimuat.");
      const items = monitor.items.map((item) => ({
        id: item.packageId,
        product: item.productName,
        nominal: item.packageLabel,
        sku: item.providerSku,
        cost: item.currentPrice ?? 0,
        seller: item.health === "healthy" ? "Normal" as const : item.health === "warning" ? "Peringatan" as const : item.health === "critical" ? "Seller Off" as const : "Belum Dicek" as const,
        sync: "Auto" as const,
        code: item.productName.split(/\s+/).map((part) => part[0]).join("").slice(0, 4).toUpperCase(),
        alertReason: item.alertReason || undefined,
        lastCheckedAt: item.lastCheckedAt || undefined,
      }));
      setMonitorItems(items);
      setSummary(monitor.summary);
      setApi(monitor.api || { ready: true, balance: null, environment: null, reason: null });
      const providerOrders = (orderPayload.orders || []).filter((order) => order.provider_code === "digiflazz").slice(0, 10);
      setTransactions(providerOrders.map((order) => [order.reference_id, order.package_sku, order.product_name, order.package_label, displayProviderStatus(order.fulfillment_status), formatDate(order.created_at), order.provider_message || order.provider_status || "-"]));
      const checked = items.map((item) => item.lastCheckedAt).filter((value): value is string => Boolean(value)).sort().at(-1);
      if (checked) setLastSync(formatDate(checked));
      setSyncHistory(items.slice(0, 5).map((item) => [item.seller === "Normal" ? "SKU normal" : item.alertReason || item.seller, item.lastCheckedAt ? formatDate(item.lastCheckedAt) : "Belum diperiksa", item.product, item.seller === "Seller Off" ? "red" : "green"]));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Operasional Digiflazz gagal dimuat.");
    } finally { setLoading(false); }
  }

  useEffect(() => { void loadOperationalData(); }, []);

  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return monitorItems.filter((item) =>
      (!term || `${item.product} ${item.nominal} ${item.sku}`.toLowerCase().includes(term)) &&
      (category === "Semua Kategori" || (category === "Game" ? !["Steam Wallet", "Google Play"].includes(item.product) : ["Steam Wallet", "Google Play"].includes(item.product))) &&
      (seller === "Semua Status Seller" || item.seller === seller) &&
      (sync === "Semua Sync" || item.sync === sync),
    );
  }, [category, query, seller, sync]);

  async function syncPricelist() {
    setSyncing(true); setError(""); setNotice("");
    try {
      const response = await fetch("/api/panel/digiflazz-monitor", { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" });
      const payload = await response.json().catch(() => ({})) as MonitorResponse & { error?: string; result?: { updated?: number } };
      if (!response.ok) throw new Error(payload.error || "Sync pricelist gagal.");
      setNotice(`${payload.result?.updated ?? 0} nominal berhasil disinkronkan.`);
      setLastSync("Baru saja");
      await loadOperationalData();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Sync pricelist gagal."); }
    finally { setSyncing(false); }
  }

  async function refresh() {
    await loadOperationalData();
    setNotice("Data monitoring terbaru berhasil dimuat.");
  }

  return (
    <div className="admin-digiflazz-reference min-w-0 text-[#14213a]">
      <header className="flex items-start justify-between gap-[16px]">
        <div><h1 className="text-[24px] font-black tracking-[-0.04em] text-[#0c1933]">Digiflazz</h1><p className="mt-[3px] text-[10px] text-[#62748c]">Pantau operasional provider Digiflazz dan sinkronisasi data untuk LFAMILIA.</p></div>
        <div className="flex gap-[9px]"><button type="button" disabled={syncing} onClick={() => void syncPricelist()} className="inline-flex h-[36px] items-center gap-[7px] rounded-[5px] bg-[#0875ed] px-[16px] text-[9px] font-bold text-white shadow-[0_5px_14px_rgba(8,117,237,.2)] disabled:opacity-50"><RefreshCw className={`size-[13px] ${syncing ? "animate-spin" : ""}`} />{syncing ? "Menyinkron..." : "Sync Pricelist"}</button><button type="button" disabled={loading} onClick={() => void refresh()} className="inline-flex h-[36px] items-center gap-[7px] rounded-[5px] border border-[#dce3eb] bg-white px-[15px] text-[9px] font-bold text-[#35475f] disabled:opacity-50"><RefreshCw className={`size-[13px] ${loading ? "animate-spin" : ""}`} />Refresh</button></div>
      </header>

      {notice && <button type="button" onClick={() => setNotice("")} className="mt-[9px] flex w-full items-center justify-between rounded-[5px] border border-[#bce3ce] bg-[#eef9f3] px-[11px] py-[7px] text-left text-[8px] font-semibold text-[#158755]"><span>{notice}</span><X className="size-[11px]" /></button>}
      {error && <button type="button" onClick={() => setError("")} className="mt-[9px] w-full rounded-[5px] border border-red-200 bg-red-50 px-[11px] py-[7px] text-left text-[8px] text-red-700">{error}</button>}

      <section className="mt-[13px] grid grid-cols-5 gap-[10px]">
        <Metric label="Status API" value={api.ready ? "Online" : "Belum Siap"} note={api.ready ? `Terhubung ${api.environment || ""}` : api.reason || "Periksa Integrasi"} tone={api.ready ? "green" : "red"} Icon={Wifi} />
        <Metric label="Saldo Digiflazz" value={api.balance === null ? "Belum terbaca" : formatRupiah(api.balance)} note={api.balance === null ? "Tes koneksi untuk membaca saldo" : "Saldo akun saat ini"} tone="blue" Icon={Wallet} compact />
        <Metric label="SKU Aktif" value={String(summary.total)} note={`${summary.healthy} normal`} tone="blue" Icon={Box} />
        <Metric label="Sync Terakhir" value={lastSync} note={summary.unknown ? `●  ${summary.unknown} belum dicek` : "●  Data terbaru"} tone="blue" Icon={FileText} compact />
        <Metric label="Produk Bermasalah" value={String(summary.critical + summary.warning)} note={`${summary.critical} kritis · ${summary.warning} peringatan`} tone="red" Icon={AlertTriangle} />
      </section>

      <div className="mt-[12px] grid grid-cols-[minmax(0,1fr)_280px] gap-[12px]">
        <section className="min-w-0 overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]">
          <div className="flex items-start justify-between px-[14px] pt-[12px]"><div><h2 className="text-[14px] font-extrabold text-[#101d35]">Monitoring Produk Digiflazz</h2><p className="mt-[2px] text-[8px] text-[#687a91]">Pantau daftar produk, harga, dan status seller secara real-time.</p></div><button type="button" onClick={() => setDialog({ title: "Semua Produk Digiflazz" })} className="h-[29px] rounded-[4px] border border-[#dce3eb] bg-white px-[10px] text-[7.5px] font-bold text-[#0875df]">Lihat Semua</button></div>
          <div className="grid grid-cols-[1.55fr_.8fr_.95fr_.75fr] gap-[7px] px-[14px] py-[10px]"><label className="relative"><Search className="absolute left-[9px] top-1/2 size-[12px] -translate-y-1/2 text-[#74849a]" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari nama produk, SKU, atau nominal..." className="h-[32px] w-full rounded-[4px] border border-[#dce3eb] pl-[28px] pr-[8px] text-[8px] outline-none placeholder:text-[#8290a2] focus:border-[#2680eb]" /></label><FilterSelect value={category} onChange={setCategory} options={["Semua Kategori", "Game", "Voucher"]} /><FilterSelect value={seller} onChange={setSeller} options={["Semua Status Seller", "Normal", "Peringatan", "Seller Off", "Belum Dicek"]} /><FilterSelect value={sync} onChange={setSync} options={["Semua Sync", "Auto", "Manual"]} /></div>
          <MonitorTable items={visible} onOpen={(item) => setDialog({ title: "Detail Produk Digiflazz", item })} />
          <footer className="flex h-[52px] items-center justify-between px-[14px] text-[7.5px] text-[#586a81]"><span>Menampilkan 1–{visible.length} dari {summary.total} produk</span><Pagination /><FilterSelect value="32 per halaman" onChange={() => {}} options={["32 per halaman"]} /></footer>
        </section>

        <aside className="space-y-[10px]">
          <section className="rounded-[8px] border border-[#dfe6ef] bg-white p-[13px]"><h2 className="text-[13px] font-extrabold">Aksi Cepat</h2><p className="mt-[2px] text-[8px] text-[#687a91]">Fitur penting untuk operasional Digiflazz.</p><div className="mt-[10px] space-y-[7px]"><QuickAction primary Icon={RefreshCw} onClick={() => void syncPricelist()}>Sync Pricelist</QuickAction><QuickAction Icon={Link2} onClick={() => setDialog({ title: "Mapping SKU" })}>Mapping SKU</QuickAction><QuickAction Icon={Store} onClick={() => setDialog({ title: "Monitor Seller" })}>Monitor Seller</QuickAction><QuickAction Icon={FileText} onClick={() => setDialog({ title: "Log Digiflazz" })}>Lihat Log</QuickAction></div></section>
          <section className="rounded-[8px] border border-[#dfe6ef] bg-white p-[13px]"><div className="flex items-center justify-between"><h2 className="text-[13px] font-extrabold">Status Sinkronisasi</h2><button type="button" onClick={() => setDialog({ title: "Riwayat Sinkronisasi" })} className="text-[7.5px] font-bold text-[#0875df]">Lihat Semua</button></div><div className="mt-[8px]">{syncHistory.map(([title, date, time, tone]) => <div key={title} className="grid min-h-[47px] grid-cols-[9px_1fr_auto] gap-[7px] border-t border-[#edf0f4] pt-[8px] first:border-0"><span className={`mt-[4px] size-[7px] rounded-full ${tone === "red" ? "bg-red-500" : "bg-emerald-500"}`} /><span><strong className="block text-[7.5px]">{title}</strong><small className="mt-[2px] block text-[6.5px] text-[#708198]">{date}</small></span><small className="text-[6.5px] text-[#708198]">{time}</small></div>)}</div></section>
        </aside>
      </div>

      <TransactionTable transactions={transactions} onOpen={() => setDialog({ title: "Semua Transaksi Digiflazz" })} />
      {dialog && <OperationDialog title={dialog.title} item={dialog.item} items={monitorItems} history={syncHistory} transactions={transactions} onClose={() => setDialog(null)} />}
    </div>
  );
}

function Metric({ label, value, note, tone, Icon, compact }: { label: string; value: string; note: string; tone: "green" | "blue" | "red"; Icon: LucideIcon; compact?: boolean }) {
  const iconTone = tone === "green" ? "bg-[#dcf8e9] text-[#0eac64]" : tone === "red" ? "bg-[#ffe7e8] text-[#ef263c]" : "bg-[#e7f2ff] text-[#0875ed]";
  const valueTone = tone === "green" ? "text-[#10a75f]" : tone === "red" ? "text-[#e72d40]" : "text-[#101c35]";
  return <article className="flex min-h-[92px] items-start gap-[10px] rounded-[8px] border border-[#dfe6ef] bg-white p-[12px] shadow-[0_1px_4px_rgba(20,33,58,.04)]"><span className={`grid size-[42px] shrink-0 place-items-center rounded-[8px] ${iconTone}`}><Icon className="size-[21px]" strokeWidth={2.3} /></span><div className="min-w-0"><p className="truncate text-[8.5px] font-semibold text-[#53657d]">{label}</p><strong className={`mt-[4px] block truncate font-black tracking-[-0.03em] ${compact ? "text-[13px]" : "text-[17px]"} ${valueTone}`}>{value}</strong><p className={`mt-[10px] truncate text-[7px] font-semibold ${tone === "red" ? "text-red-500" : "text-emerald-600"}`}>{note}</p></div></article>;
}

function MonitorTable({ items, onOpen }: { items: MonitorItem[]; onOpen(item: MonitorItem): void }) {
  return <div className="overflow-x-auto"><table className="w-full min-w-[760px] table-fixed text-left"><thead className="bg-[#f2f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[30px] px-[12px] py-[8px]">#</th><th className="w-[145px]">Produk LFAMILIA</th><th className="w-[105px]">Nominal</th><th className="w-[95px]">SKU Digiflazz</th><th className="w-[100px]">Harga Modal</th><th className="w-[90px]">Status Seller</th><th className="w-[70px]">Sync</th><th className="w-[95px]">Aksi</th></tr></thead><tbody>{items.map((item, index) => <tr key={item.id} className="border-t border-[#e5eaf0] text-[7px] text-[#35475f]"><td className="px-[12px] py-[6px]">{index + 1}</td><td><div className="flex items-center gap-[7px]"><ProductMark code={item.code} /><strong className="truncate">{item.product}</strong></div></td><td>{item.nominal}</td><td className="font-semibold">{item.sku}</td><td>{item.cost ? formatRupiah(item.cost) : "Belum dicek"}</td><td><span className={`rounded-[4px] px-[8px] py-[4px] font-bold ${item.seller === "Normal" ? "bg-[#ddf8e8] text-[#15955a]" : item.seller === "Seller Off" ? "bg-[#ffe5e7] text-[#dd3347]" : "bg-[#fff0d8] text-[#e18a00]"}`}>{item.seller}</span></td><td><span className={`rounded-[4px] px-[8px] py-[4px] font-bold ${item.sync === "Auto" ? "bg-[#ddf8e8] text-[#15955a]" : "bg-[#e9f1ff] text-[#0875df]"}`}>{item.sync}</span></td><td><div className="flex items-center gap-[7px]"><button type="button" onClick={() => onOpen(item)} className="h-[27px] rounded-[4px] border border-[#dce3eb] bg-white px-[11px] font-bold text-[#0875df]">Lihat</button><MoreVertical className="size-[12px]" /></div></td></tr>)}{!items.length && <tr><td colSpan={8} className="py-[35px] text-center text-[8px] text-[#718198]">Tidak ada produk yang cocok.</td></tr>}</tbody></table></div>;
}

function TransactionTable({ transactions, onOpen }: { transactions: string[][]; onOpen(): void }) {
  return <section className="mt-[12px] overflow-hidden rounded-[8px] border border-[#dfe6ef] bg-white shadow-[0_1px_4px_rgba(20,33,58,.04)]"><div className="flex items-start justify-between px-[14px] py-[11px]"><div><h2 className="text-[13px] font-extrabold">Transaksi Provider Terbaru</h2><p className="mt-[2px] text-[8px] text-[#687a91]">Riwayat transaksi terbaru melalui Digiflazz.</p></div><button type="button" onClick={onOpen} className="text-[7.5px] font-bold text-[#0875df]">Lihat Semua</button></div><div className="overflow-x-auto border-t border-[#e5eaf0]"><table className="w-full min-w-[850px] table-fixed text-left"><thead className="bg-[#f2f6fa] text-[6.5px] font-bold text-[#52647c]"><tr><th className="w-[30px] px-[12px] py-[8px]">#</th><th className="w-[145px]">Invoice LFAMILIA</th><th className="w-[105px]">SKU Digiflazz</th><th className="w-[130px]">Produk</th><th className="w-[120px]">Nominal</th><th className="w-[95px]">Status</th><th className="w-[145px]">Waktu</th><th>Response</th></tr></thead><tbody>{transactions.map((row, index) => <tr key={row[0]} className="border-t border-[#e5eaf0] text-[7px] text-[#35475f]"><td className="px-[12px] py-[7px]">{index + 1}</td><td className="font-bold text-[#0875df]">{row[0]}</td><td>{row[1]}</td><td>{row[2]}</td><td>{row[3]}</td><td><TransactionStatus value={row[4]} /></td><td>{row[5]}</td><td>{row[6]}</td></tr>)}{!transactions.length && <tr><td colSpan={8} className="py-[26px] text-center text-[8px] text-[#718198]">Belum ada transaksi Digiflazz.</td></tr>}</tbody></table></div></section>;
}

function OperationDialog({ title, item, items, history, transactions, onClose }: { title: string; item?: MonitorItem; items: MonitorItem[]; history: string[][]; transactions: string[][]; onClose(): void }) {
  const rows = title.includes("Transaksi") ? transactions.map((row) => `${row[0]} · ${row[2]} · ${row[4]}`) : title.includes("Riwayat") || title.includes("Log") ? history.map((row) => `${row[0]} · ${row[1]}`) : items.map((entry) => `${entry.product} · ${entry.nominal} · ${entry.sku} · ${entry.seller}`);
  return <div className="fixed inset-0 z-50 grid place-items-center bg-[#071426]/55 p-[24px]" role="dialog" aria-modal="true" aria-label={title}><section className="w-full max-w-[510px] overflow-hidden rounded-[9px] bg-white shadow-2xl"><header className="flex items-start justify-between border-b border-[#e4e9ef] px-[16px] py-[13px]"><div><h2 className="text-[14px] font-black">{title}</h2><p className="mt-[2px] text-[8px] text-[#6d7d92]">Data operasional terbaru dari backend LFAMILIA.</p></div><button type="button" onClick={onClose} className="grid size-[27px] place-items-center rounded-[4px] hover:bg-[#f2f5f8]"><X className="size-[14px]" /></button></header><div className="max-h-[420px] overflow-auto p-[16px]">{item ? <div className="grid grid-cols-2 gap-[9px]"><Detail label="Produk" value={item.product} /><Detail label="Nominal" value={item.nominal} /><Detail label="SKU Digiflazz" value={item.sku} /><Detail label="Harga Modal" value={item.cost ? formatRupiah(item.cost) : "Belum dicek"} /><Detail label="Status Seller" value={item.seller} /><Detail label="Keterangan" value={item.alertReason || "Normal"} /></div> : rows.length ? <div className="space-y-[6px]">{rows.map((row, index) => <div key={`${row}-${index}`} className="rounded-[5px] border border-[#e0e6ed] px-[10px] py-[8px] text-[8px] text-[#40516a]">{row}</div>)}</div> : <div className="rounded-[6px] border border-dashed border-[#cbd6e2] bg-[#f8fafc] px-[14px] py-[35px] text-center text-[9px] text-[#687a91]">Belum ada data untuk {title}.</div>}</div><footer className="flex justify-end border-t border-[#e4e9ef] bg-[#fafbfd] px-[16px] py-[11px]"><button type="button" onClick={onClose} className="h-[32px] rounded-[4px] bg-[#0875ed] px-[14px] text-[8px] font-bold text-white">Selesai</button></footer></section></div>;
}

function QuickAction({ children, Icon, onClick, primary }: { children: ReactNode; Icon: LucideIcon; onClick(): void; primary?: boolean }) { return <button type="button" onClick={onClick} className={`inline-flex h-[37px] w-full items-center justify-center gap-[8px] rounded-[5px] border text-[9px] font-bold ${primary ? "border-[#0875ed] bg-[#0875ed] text-white" : "border-[#dce3eb] bg-white text-[#1f3550]"}`}><Icon className={`size-[15px] ${primary ? "" : "text-[#0875ed]"}`} />{children}</button>; }
function FilterSelect({ value, onChange, options }: { value: string; onChange(value: string): void; options: string[] }) { return <select value={value} onChange={(event) => onChange(event.target.value)} className="h-[32px] min-w-0 rounded-[4px] border border-[#dce3eb] bg-white px-[8px] text-[7.5px] font-semibold text-[#40516a] outline-none">{options.map((option) => <option key={option}>{option}</option>)}</select>; }
function Pagination() { return <nav className="flex items-center gap-[4px]"><Page active>1</Page></nav>; }
function Page({ children, active }: { children: ReactNode; active?: boolean }) { return <span className={`grid size-[27px] place-items-center rounded-[4px] border text-[7.5px] font-bold [&_svg]:size-[11px] ${active ? "border-[#0875ed] bg-[#0875ed] text-white" : "border-[#dde4ec] bg-white text-[#52647b]"}`}>{children}</span>; }
function ProductMark({ code }: { code: string }) { const tones: Record<string,string> = { ML:"from-blue-600 to-amber-400", FF:"from-amber-500 to-orange-900", PUBG:"from-stone-800 to-amber-600", VAL:"from-slate-900 to-rose-500", GI:"from-blue-400 to-indigo-700", ST:"from-slate-800 to-sky-600", GP:"from-green-500 to-blue-500", RBX:"from-slate-700 to-slate-950" }; return <span className={`grid size-[25px] shrink-0 place-items-center rounded-[5px] bg-gradient-to-br ${tones[code] || "from-blue-500 to-indigo-700"} text-[5px] font-black text-white`}>{code}</span>; }
function TransactionStatus({ value }: { value: string }) { const style = value === "Berhasil" ? "bg-[#ddf8e8] text-[#15955a]" : value === "Pending" ? "bg-[#fff0d8] text-[#df8900]" : "bg-[#ffe5e7] text-[#dd3347]"; return <span className={`inline-flex min-w-[61px] justify-center rounded-[4px] px-[7px] py-[4px] font-bold ${style}`}>{value}</span>; }
function Detail({ label, value }: { label: string; value: string }) { return <div className="rounded-[5px] border border-[#e0e6ed] p-[10px]"><span className="block text-[7px] text-[#718198]">{label}</span><strong className="mt-[3px] block text-[9px]">{value}</strong></div>; }
function displayProviderStatus(value: string) { return value === "success" ? "Berhasil" : value === "failed" ? "Gagal" : "Pending"; }
function formatDate(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(date); }
function formatRupiah(value: number) { return `Rp ${new Intl.NumberFormat("id-ID").format(value)}`; }
