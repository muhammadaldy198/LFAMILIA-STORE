"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Copy, Eye, KeyRound, LoaderCircle, Mail, MessageCircle, PackagePlus, RefreshCw, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

type Stock = {
  stock_key: string;
  available: number;
  reserved: number;
  delivered: number;
  voided: number;
  total: number;
  labels: string[];
};

type Delivery = {
  code_id: number;
  stock_key: string;
  code_status: string;
  order_id: string;
  reference_id: string;
  product_name: string;
  package_label: string;
  buyer_name: string;
  buyer_email: string;
  buyer_phone: string;
  email_status: string | null;
  whatsapp_status: string | null;
  reserved_at: string | null;
  delivered_at: string | null;
};

type VoucherConfig = {
  encryptionReady: boolean;
  emailReady: boolean;
  whatsappReady: boolean;
  deliveryChannel: string;
};

type DashboardResponse = {
  stocks?: Stock[];
  deliveries?: Delivery[];
  config?: VoucherConfig;
  error?: string;
};

const emptyConfig: VoucherConfig = { encryptionReady: false, emailReady: false, whatsappReady: false, deliveryChannel: "both" };

export function AdminVoucherManager() {
  const [stocks, setStocks] = useState<Stock[]>([]);
  const [deliveries, setDeliveries] = useState<Delivery[]>([]);
  const [config, setConfig] = useState<VoucherConfig>(emptyConfig);
  const [stockKey, setStockKey] = useState("");
  const [codes, setCodes] = useState("");
  const [loading, setLoading] = useState(true);
  const [importing, setImporting] = useState(false);
  const [workingOrder, setWorkingOrder] = useState<string | null>(null);
  const [revealed, setRevealed] = useState<{ orderId: string; code: string; stockKey: string } | null>(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/admin/vouchers", { cache: "no-store" });
      const data = await response.json() as DashboardResponse;
      if (!response.ok) throw new Error(data.error || "Stok kode gagal dimuat.");
      const nextStocks = data.stocks ?? [];
      setStocks(nextStocks);
      setDeliveries(data.deliveries ?? []);
      setConfig(data.config ?? emptyConfig);
      setStockKey((current) => current || nextStocks[0]?.stock_key || "");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Stok kode gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let active = true;
    void fetch("/api/admin/vouchers", { cache: "no-store" }).then(async (response) => {
      const data = await response.json() as DashboardResponse;
      if (!response.ok) throw new Error(data.error || "Stok kode gagal dimuat.");
      if (!active) return;
      const nextStocks = data.stocks ?? [];
      setStocks(nextStocks);
      setDeliveries(data.deliveries ?? []);
      setConfig(data.config ?? emptyConfig);
      setStockKey(nextStocks[0]?.stock_key || "");
    }).catch((reason) => {
      if (active) setError(reason instanceof Error ? reason.message : "Stok kode gagal dimuat.");
    }).finally(() => {
      if (active) setLoading(false);
    });
    return () => { active = false; };
  }, []);

  const totals = useMemo(() => stocks.reduce((result, stock) => ({
    available: result.available + Number(stock.available),
    reserved: result.reserved + Number(stock.reserved),
    delivered: result.delivered + Number(stock.delivered),
  }), { available: 0, reserved: 0, delivered: 0 }), [stocks]);

  async function importCodes(event: FormEvent) {
    event.preventDefault();
    const values = codes.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
    if (!stockKey.trim() || values.length < 1) {
      setError("Isi kunci stok dan minimal satu kode.");
      return;
    }
    setImporting(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/admin/vouchers", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "import", stockKey, codes: values }),
      });
      const data = await response.json() as { imported?: number; duplicates?: number; error?: string };
      if (!response.ok) throw new Error(data.error || "Impor kode gagal.");
      setMessage(`${data.imported ?? 0} kode berhasil ditambahkan${data.duplicates ? `, ${data.duplicates} duplikat dilewati` : ""}.`);
      setCodes("");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Impor kode gagal.");
    } finally {
      setImporting(false);
    }
  }

  async function reveal(orderId: string) {
    setWorkingOrder(orderId);
    setError("");
    try {
      const response = await fetch("/api/admin/vouchers", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "reveal", orderId }),
      });
      const data = await response.json() as { code?: string; stockKey?: string; error?: string };
      if (!response.ok || !data.code || !data.stockKey) throw new Error(data.error || "Kode tidak dapat dibuka.");
      setRevealed({ orderId, code: data.code, stockKey: data.stockKey });
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Kode tidak dapat dibuka.");
    } finally {
      setWorkingOrder(null);
    }
  }

  async function retry(orderId: string) {
    setWorkingOrder(orderId);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/admin/vouchers", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "retry", orderId }),
      });
      const data = await response.json() as { ok?: boolean; message?: string; error?: string };
      if (!response.ok || !data.ok) throw new Error(data.error || data.message || "Pengiriman ulang belum berhasil.");
      setMessage(data.message || "Kode berhasil dikirim ulang.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengiriman ulang gagal.");
    } finally {
      setWorkingOrder(null);
    }
  }

  if (loading) return <div className="flex min-h-48 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat stok kode…</div>;

  return <div className="space-y-5">
    <div className="grid grid-cols-3 gap-3">
      <Metric label="Siap dijual" value={totals.available} tone="text-[#b9ff35]" />
      <Metric label="Direservasi" value={totals.reserved} tone="text-amber-300" />
      <Metric label="Terkirim" value={totals.delivered} tone="text-sky-300" />
    </div>

    <div className="grid gap-2 sm:grid-cols-3">
      <ConfigBadge ready={config.encryptionReady} icon={KeyRound} label="Enkripsi stok" />
      <ConfigBadge ready={config.emailReady} icon={Mail} label="Email Resend" />
      <ConfigBadge ready={config.whatsappReady} icon={MessageCircle} label="WhatsApp Cloud" />
    </div>

    {!config.encryptionReady && <div role="alert" className="rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3 text-xs leading-5 text-amber-100/70">Isi secret <strong>VOUCHER_ENCRYPTION_KEY</strong> minimal 32 karakter sebelum mengimpor kode. Secret ini tidak boleh diganti setelah stok masuk.</div>}
    {message && <div className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">{message}</div>}
    {error && <div role="alert" className="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
    {revealed && <div className="rounded-xl border border-[#b9ff35]/25 bg-[#b9ff35]/[0.07] p-4"><div className="flex items-center justify-between gap-3"><div><p className="text-[9px] uppercase tracking-wider text-white/35">Kode {revealed.stockKey}</p><strong className="mt-1 block break-all font-mono text-sm text-[#d8ff8d]">{revealed.code}</strong></div><Button type="button" variant="outline" size="sm" onClick={() => void navigator.clipboard.writeText(revealed.code)} className="shrink-0 rounded-lg border-white/10 bg-white/[0.04] text-white hover:bg-white/[0.08] hover:text-white"><Copy className="mr-1 size-3" />Salin</Button></div><p className="mt-2 text-[9px] text-white/30">Tutup panel admin setelah selesai agar kode tidak terlihat orang lain.</p></div>}

    <form onSubmit={importCodes} className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3"><div><h3 className="text-sm font-bold">Impor stok kode</h3><p className="mt-1 text-[10px] leading-5 text-white/32">Satu baris untuk satu kode. Duplikat otomatis dilewati.</p></div><PackagePlus className="size-5 text-[#b9ff35]" /></div>
      <div className="mt-4 grid gap-3 sm:grid-cols-[220px_1fr]">
        <label><span className="field-label">Kunci stok</span><Input list="voucher-stock-keys" required value={stockKey} onChange={(event) => setStockKey(event.target.value.toLowerCase().replace(/[^a-z0-9._:-]/g, "-"))} placeholder="redfinger-30-hari" className="admin-input" /><datalist id="voucher-stock-keys">{stocks.map((stock) => <option key={stock.stock_key} value={stock.stock_key} />)}</datalist><p className="mt-2 text-[9px] leading-4 text-white/25">Harus sama dengan SKU Stok Kode Internal pada produk.</p></label>
        <label><span className="field-label">Daftar kode</span><Textarea required rows={7} value={codes} onChange={(event) => setCodes(event.target.value)} placeholder={"RF-XXXX-XXXX\nRF-YYYY-YYYY\nRF-ZZZZ-ZZZZ"} className="min-h-40 rounded-xl border-white/10 bg-[#171c27] font-mono text-xs text-white placeholder:text-white/20" /></label>
      </div>
      <Button disabled={importing || !config.encryptionReady} className="mt-4 rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006] hover:bg-[#d0ff75]">{importing ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <PackagePlus className="mr-2 size-4" />}Impor kode</Button>
    </form>

    <section>
      <div className="mb-3 flex items-center justify-between"><div><h3 className="text-sm font-bold">Stok per produk</h3><p className="mt-1 text-[10px] text-white/30">Mode pengiriman: {config.deliveryChannel}</p></div><Button type="button" onClick={() => void load()} variant="outline" size="sm" className="rounded-lg border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white"><RefreshCw className="mr-1 size-3.5" />Muat ulang</Button></div>
      {stocks.length === 0 ? <div className="rounded-xl border border-dashed border-white/10 p-8 text-center text-xs text-white/30">Belum ada kunci stok. Atur produk dengan provider Stok Kode Internal atau isi kunci baru di formulir impor.</div> : <div className="overflow-x-auto rounded-xl border border-white/[0.08]"><Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Kunci stok</TableHead><TableHead className="text-[10px] text-white/35">Dipakai produk</TableHead><TableHead className="text-[10px] text-white/35">Tersedia</TableHead><TableHead className="text-[10px] text-white/35">Reservasi</TableHead><TableHead className="text-[10px] text-white/35">Terkirim</TableHead></TableRow></TableHeader><TableBody>{stocks.map((stock) => <TableRow key={stock.stock_key} className="border-white/[0.07] hover:bg-white/[0.025]"><TableCell className="font-mono text-[10px] text-[#d8ff8d]">{stock.stock_key}</TableCell><TableCell><p className="max-w-72 text-[9px] leading-4 text-white/38">{stock.labels.join(", ") || "Belum dihubungkan"}</p></TableCell><TableCell className="text-xs font-bold text-[#b9ff35]">{stock.available}</TableCell><TableCell className="text-xs text-amber-300">{stock.reserved}</TableCell><TableCell className="text-xs text-sky-300">{stock.delivered}</TableCell></TableRow>)}</TableBody></Table></div>}
    </section>

    <section>
      <div className="mb-3"><h3 className="text-sm font-bold">Pengiriman terbaru</h3><p className="mt-1 text-[10px] text-white/30">Kode asli hanya dibuka saat tombol Lihat kode ditekan.</p></div>
      {deliveries.length === 0 ? <div className="rounded-xl border border-dashed border-white/10 p-8 text-center text-xs text-white/30">Belum ada kode yang direservasi.</div> : <div className="overflow-x-auto rounded-xl border border-white/[0.08]"><Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Invoice</TableHead><TableHead className="text-[10px] text-white/35">Produk</TableHead><TableHead className="text-[10px] text-white/35">Pelanggan</TableHead><TableHead className="text-[10px] text-white/35">Email</TableHead><TableHead className="text-[10px] text-white/35">WhatsApp</TableHead><TableHead className="text-right text-[10px] text-white/35">Aksi</TableHead></TableRow></TableHeader><TableBody>{deliveries.map((delivery) => {
        const needsRetry = delivery.email_status === "failed" || delivery.whatsapp_status === "failed" || delivery.code_status === "reserved";
        return <TableRow key={delivery.code_id} className="border-white/[0.07] hover:bg-white/[0.025]"><TableCell><strong className="font-mono text-[9px]">{delivery.reference_id}</strong><p className="mt-1 text-[8px] text-white/25">{delivery.stock_key}</p></TableCell><TableCell><strong className="text-xs">{delivery.product_name}</strong><p className="mt-1 text-[9px] text-white/32">{delivery.package_label}</p></TableCell><TableCell><span className="text-xs text-white/55">{delivery.buyer_name}</span><p className="mt-1 text-[8px] text-white/25">{delivery.buyer_email}</p></TableCell><TableCell><DeliveryBadge value={delivery.email_status} /></TableCell><TableCell><DeliveryBadge value={delivery.whatsapp_status} /></TableCell><TableCell><div className="flex justify-end gap-1"><Button type="button" disabled={workingOrder === delivery.order_id} onClick={() => void reveal(delivery.order_id)} variant="ghost" size="sm" className="text-[9px] text-white/55 hover:bg-white/[0.08] hover:text-white">{workingOrder === delivery.order_id ? <LoaderCircle className="size-3 animate-spin" /> : <><Eye className="mr-1 size-3" />Lihat</>}</Button>{needsRetry && <Button type="button" disabled={workingOrder === delivery.order_id} onClick={() => void retry(delivery.order_id)} variant="ghost" size="sm" className="text-[9px] text-amber-200/70 hover:bg-amber-300/[0.08] hover:text-amber-100"><RotateCcw className="mr-1 size-3" />Kirim ulang</Button>}</div></TableCell></TableRow>;
      })}</TableBody></Table></div>}
    </section>
  </div>;
}

function Metric({ label, value, tone }: { label: string; value: number; tone: string }) {
  return <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4"><span className="text-[9px] uppercase tracking-wider text-white/30">{label}</span><strong className={`mt-2 block text-xl font-black ${tone}`}>{value}</strong></div>;
}

function ConfigBadge({ ready, icon: Icon, label }: { ready: boolean; icon: typeof KeyRound; label: string }) {
  return <div className={`flex items-center gap-2 rounded-xl border p-3 text-[10px] font-semibold ${ready ? "border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] text-[#d8ff8d]" : "border-amber-300/15 bg-amber-300/[0.04] text-amber-200/60"}`}><Icon className="size-4" />{label}<span className="ml-auto text-[8px] uppercase">{ready ? "Siap" : "Belum"}</span></div>;
}

function DeliveryBadge({ value }: { value: string | null }) {
  if (!value) return <span className="text-[9px] text-white/25">Belum</span>;
  const style = value === "sent" ? "bg-[#b9ff35]/10 text-[#d8ff8d]" : value === "failed" ? "bg-red-400/10 text-red-200" : "bg-amber-300/10 text-amber-200";
  const label = value === "sent" ? "Terkirim" : value === "failed" ? "Gagal" : "Diproses";
  return <span className={`rounded-full px-2 py-1 text-[9px] font-bold ${style}`}>{label}</span>;
}
