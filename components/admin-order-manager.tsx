"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { CheckCircle2, ExternalLink, LoaderCircle, RefreshCw, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatRupiah } from "@/lib/store-data";

type Order = {
  id: string;
  reference_id: string;
  product_name: string;
  package_label: string;
  destination: string;
  server: string | null;
  buyer_name: string;
  buyer_phone: string;
  total: number;
  payment_status: string;
  fulfillment_type: "automatic" | "manual";
  fulfillment_status: string;
  provider_code: string | null;
  provider_message: string | null;
  created_at: string;
};

export function AdminOrderManager() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("all");
  const [loading, setLoading] = useState(true);
  const [workingId, setWorkingId] = useState<string | null>(null);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/admin/orders", { cache: "no-store" });
      const data = await response.json() as { orders?: Order[]; error?: string };
      if (!response.ok) throw new Error(data.error ?? "Pesanan gagal dimuat.");
      setOrders(data.orders ?? []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pesanan gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let active = true;
    void fetch("/api/admin/orders", { cache: "no-store" }).then(async (response) => {
      const data = await response.json() as { orders?: Order[]; error?: string };
      if (!response.ok) throw new Error(data.error ?? "Pesanan gagal dimuat.");
      if (active) setOrders(data.orders ?? []);
    }).catch((reason) => {
      if (active) setError(reason instanceof Error ? reason.message : "Pesanan gagal dimuat.");
    }).finally(() => {
      if (active) setLoading(false);
    });
    return () => { active = false; };
  }, []);

  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return orders.filter((order) => (status === "all" || order.payment_status === status || order.fulfillment_status === status)
      && (!term || `${order.reference_id} ${order.product_name} ${order.buyer_name} ${order.destination}`.toLowerCase().includes(term)));
  }, [orders, query, status]);

  async function completeManual(id: string) {
    setWorkingId(id);
    setError("");
    try {
      const response = await fetch("/api/admin/orders", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ id, action: "complete_manual" }),
      });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Pesanan gagal diperbarui.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pesanan gagal diperbarui.");
    } finally {
      setWorkingId(null);
    }
  }

  async function retryVoucher(id: string) {
    setWorkingId(id);
    setError("");
    try {
      const response = await fetch("/api/admin/vouchers", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "retry", orderId: id }),
      });
      const data = await response.json() as { ok?: boolean; message?: string; error?: string };
      if (!response.ok || !data.ok) throw new Error(data.error || data.message || "Pengiriman kode belum berhasil.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengiriman kode gagal.");
    } finally {
      setWorkingId(null);
    }
  }

  return <div>
    <div className="mb-4 flex flex-col gap-3 sm:flex-row">
      <Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari invoice, produk, atau pelanggan..." className="h-10 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" />
      <select value={status} onChange={(event) => setStatus(event.target.value)} className="h-10 rounded-xl border border-white/10 bg-[#151924] px-3 text-xs text-white"><option value="all">Semua status</option><option value="pending">Menunggu bayar</option><option value="paid">Sudah dibayar</option><option value="manual_pending">Manual tertunda</option><option value="processing">Diproses provider</option><option value="success">Selesai</option><option value="needs_review">Perlu ditinjau</option></select>
      <Button type="button" onClick={() => void load()} variant="outline" className="shrink-0 rounded-xl border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white"><RefreshCw className="mr-2 size-4" />Muat ulang</Button>
    </div>
    {error && <div className="mb-4 rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
    {loading ? <div className="flex min-h-40 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat pesanan…</div> : visible.length === 0 ? <div className="rounded-xl border border-dashed border-white/10 p-10 text-center text-xs text-white/30">Belum ada pesanan yang cocok.</div> : <div className="overflow-x-auto rounded-xl border border-white/[0.08]"><Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Invoice</TableHead><TableHead className="text-[10px] text-white/35">Produk</TableHead><TableHead className="text-[10px] text-white/35">Pelanggan</TableHead><TableHead className="text-[10px] text-white/35">Total</TableHead><TableHead className="text-[10px] text-white/35">Pembayaran</TableHead><TableHead className="text-[10px] text-white/35">Pemenuhan</TableHead><TableHead className="text-right text-[10px] text-white/35">Aksi</TableHead></TableRow></TableHeader><TableBody>{visible.map((order) => {
      const manualReady = order.fulfillment_type === "manual" && order.payment_status === "paid" && order.fulfillment_status === "manual_pending";
      const voucherRetry = order.provider_code === "voucher-stock" && order.payment_status === "paid" && order.fulfillment_status !== "success";
      const wa = order.buyer_phone.replace(/\D/g, "").replace(/^0/, "62");
      return <TableRow key={order.id} className="border-white/[0.07] hover:bg-white/[0.025]"><TableCell><strong className="text-[10px] text-white">{order.reference_id}</strong><p className="mt-1 text-[8px] text-white/25">{order.created_at}</p></TableCell><TableCell><strong className="text-xs">{order.product_name}</strong><p className="mt-1 text-[9px] text-white/35">{order.package_label}</p><p className="mt-1 text-[9px] text-[#cfff72]">{order.destination}{order.server ? ` (${order.server})` : ""}</p></TableCell><TableCell><span className="text-xs text-white/55">{order.buyer_name}</span><a href={`https://wa.me/${wa}`} target="_blank" rel="noreferrer" className="mt-1 flex items-center gap-1 text-[9px] text-[#cfff72]">WhatsApp <ExternalLink className="size-2.5" /></a></TableCell><TableCell className="text-xs text-[#d8ff8d]">{formatRupiah(order.total)}</TableCell><TableCell><StatusBadge value={order.payment_status} /></TableCell><TableCell><StatusBadge value={order.fulfillment_status} /><p className="mt-1 max-w-40 truncate text-[8px] text-white/25">{order.provider_code || "manual"}{order.provider_message ? ` • ${order.provider_message}` : ""}</p></TableCell><TableCell className="text-right"><div className="flex justify-end gap-1">{manualReady && <Button type="button" disabled={workingId === order.id} onClick={() => void completeManual(order.id)} size="sm" className="rounded-lg bg-[#b9ff35] text-[9px] font-black text-[#091006] hover:bg-[#d0ff75]">{workingId === order.id ? <LoaderCircle className="size-3 animate-spin" /> : <><CheckCircle2 className="mr-1 size-3" />Selesai</>}</Button>}{voucherRetry && <Button type="button" disabled={workingId === order.id} onClick={() => void retryVoucher(order.id)} size="sm" className="rounded-lg bg-amber-300 text-[9px] font-black text-[#171006] hover:bg-amber-200">{workingId === order.id ? <LoaderCircle className="size-3 animate-spin" /> : <><RotateCcw className="mr-1 size-3" />Kirim kode</>}</Button>}</div></TableCell></TableRow>;
    })}</TableBody></Table></div>}
  </div>;
}

function StatusBadge({ value }: { value: string }) {
  const labels: Record<string, string> = { pending: "Menunggu", paid: "Lunas", expired: "Kedaluwarsa", failed: "Gagal", waiting_payment: "Menunggu bayar", manual_pending: "Antrean manual", processing: "Diproses", success: "Selesai", needs_review: "Perlu ditinjau" };
  const success = value === "paid" || value === "success";
  const warning = value === "pending" || value === "waiting_payment" || value === "manual_pending" || value === "processing";
  return <span className={`rounded-full px-2 py-1 text-[9px] font-bold ${success ? "bg-[#b9ff35]/10 text-[#d8ff8d]" : warning ? "bg-amber-400/10 text-amber-200" : "bg-red-400/10 text-red-200"}`}>{labels[value] ?? value}</span>;
}
