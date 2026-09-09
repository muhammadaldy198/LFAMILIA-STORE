"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { AlertTriangle, CheckCircle2, LoaderCircle, RefreshCw, Search, XCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { formatRupiah } from "@/lib/store-data";

type Health = "healthy" | "warning" | "critical" | "unknown";
type Item = {
  packageId: number;
  productName: string;
  packageLabel: string;
  providerSku: string;
  sellerName: string | null;
  currentPrice: number | null;
  baselinePrice: number | null;
  buyerProductStatus: boolean;
  sellerProductStatus: boolean;
  unlimitedStock: boolean;
  stock: number;
  multi: boolean;
  startCutOff: string | null;
  endCutOff: string | null;
  health: Health;
  alertReason: string | null;
  lastCheckedAt: string | null;
};
type Payload = {
  items: Item[];
  summary: { total: number; healthy: number; warning: number; critical: number; unknown: number };
  error?: string;
};

export function AdminDigiflazzMonitor() {
  const [data, setData] = useState<Payload | null>(null);
  const [query, setQuery] = useState("");
  const [health, setHealth] = useState<Health | "all">("all");
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(async (refresh = false) => {
    refresh ? setRefreshing(true) : setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/digiflazz-monitor", { method: refresh ? "POST" : "GET", cache: "no-store" });
      const payload = await response.json().catch(() => ({})) as Payload;
      if (!response.ok) throw new Error(payload.error || "Monitor Digiflazz gagal dimuat.");
      setData(payload);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Monitor Digiflazz gagal dimuat.");
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const rows = useMemo(() => {
    const q = query.trim().toLowerCase();
    return (data?.items ?? []).filter((item) => {
      if (health !== "all" && item.health !== health) return false;
      if (!q) return true;
      return [item.productName, item.packageLabel, item.providerSku, item.sellerName ?? ""].some((value) => value.toLowerCase().includes(q));
    });
  }, [data, query, health]);

  if (loading) return <div className="flex min-h-32 items-center justify-center text-xs text-white/40"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat monitoring…</div>;

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <Metric label="Total SKU" value={data?.summary.total ?? 0} />
        <Metric label="Aman" value={data?.summary.healthy ?? 0} tone="good" />
        <Metric label="Perlu cek" value={data?.summary.warning ?? 0} tone="warn" />
        <Metric label="Kritis" value={data?.summary.critical ?? 0} tone="bad" />
        <Metric label="Belum dicek" value={data?.summary.unknown ?? 0} />
      </div>

      <div className="flex flex-col gap-2 sm:flex-row">
        <div className="relative min-w-0 flex-1"><Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-white/28" /><Input value={query} onChange={(event) => setQuery(event.target.value)} className="admin-input pl-8" placeholder="Cari produk, nominal, SKU, atau seller…" /></div>
        <select value={health} onChange={(event) => setHealth(event.target.value as Health | "all")} className="admin-select sm:w-40">
          <option value="all">Semua status</option><option value="critical">Kritis</option><option value="warning">Perlu cek</option><option value="healthy">Aman</option><option value="unknown">Belum dicek</option>
        </select>
        <Button type="button" variant="outline" onClick={() => void load(true)} disabled={refreshing} className="border-white/10 bg-white/[0.03] text-white">
          {refreshing ? <LoaderCircle className="mr-1.5 size-3.5 animate-spin" /> : <RefreshCw className="mr-1.5 size-3.5" />}Cek sekarang
        </Button>
      </div>

      {error && <div className="rounded-lg border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
      <div className="overflow-x-auto rounded-lg border border-white/[0.08]">
        <table className="w-full min-w-[920px] text-left text-[10px]">
          <thead className="bg-white/[0.025] text-white/35"><tr><th className="px-3 py-2">Produk / Nominal</th><th className="px-3 py-2">SKU</th><th className="px-3 py-2">Seller</th><th className="px-3 py-2">Modal</th><th className="px-3 py-2">Stok</th><th className="px-3 py-2">Cut-off</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Terakhir cek</th></tr></thead>
          <tbody>
            {rows.map((item) => <tr key={item.packageId} className="border-t border-white/[0.07]">
              <td className="px-3 py-2"><strong>{item.productName}</strong><p className="text-[9px] text-white/35">{item.packageLabel}</p></td>
              <td className="px-3 py-2 font-mono">{item.providerSku}</td>
              <td className="px-3 py-2">{item.sellerName || "-"}</td>
              <td className="px-3 py-2 font-semibold">{item.currentPrice ? formatRupiah(item.currentPrice) : "-"}</td>
              <td className="px-3 py-2">{item.unlimitedStock ? "∞" : item.stock}</td>
              <td className="px-3 py-2">{item.startCutOff && item.endCutOff ? item.startCutOff + "–" + item.endCutOff : "-"}</td>
              <td className="px-3 py-2"><HealthBadge health={item.health} reason={item.alertReason} /></td>
              <td className="px-3 py-2 text-white/35">{item.lastCheckedAt || "-"}</td>
            </tr>)}
            {!rows.length && <tr><td colSpan={8} className="px-3 py-8 text-center text-white/35">Tidak ada SKU pada filter ini.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Metric({ label, value, tone = "neutral" }: { label: string; value: number; tone?: "neutral" | "good" | "warn" | "bad" }) {
  const cls = tone === "good" ? "text-emerald-300" : tone === "warn" ? "text-amber-300" : tone === "bad" ? "text-red-300" : "text-white";
  return <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] p-3"><p className="text-[9px] text-white/35">{label}</p><strong className={"mt-1 block text-lg " + cls}>{value}</strong></div>;
}

function HealthBadge({ health, reason }: { health: Health; reason: string | null }) {
  if (health === "healthy") return <span title={reason || "Normal"} className="inline-flex items-center gap-1 rounded-md bg-emerald-400/10 px-2 py-1 font-bold text-emerald-300"><CheckCircle2 className="size-3" />Aman</span>;
  if (health === "critical") return <span title={reason || "Kritis"} className="inline-flex items-center gap-1 rounded-md bg-red-400/10 px-2 py-1 font-bold text-red-300"><XCircle className="size-3" />Kritis</span>;
  if (health === "warning") return <span title={reason || "Perlu diperiksa"} className="inline-flex items-center gap-1 rounded-md bg-amber-400/10 px-2 py-1 font-bold text-amber-300"><AlertTriangle className="size-3" />Perlu cek</span>;
  return <span className="rounded-md bg-white/[0.06] px-2 py-1 font-bold text-white/40">Belum dicek</span>;
}
