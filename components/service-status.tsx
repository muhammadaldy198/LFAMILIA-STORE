"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, RefreshCw, TriangleAlert } from "lucide-react";

type Service = { id: string; name: string; state: "operational" | "degraded"; detail: string };
type StatusPayload = { services: Service[]; merchant: { legalName: string; registrationId: string; address: string }; updatedAt: string };

export function ServiceStatus() {
  const [data, setData] = useState<StatusPayload | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  async function load() {
    setLoading(true);
    try {
      const response = await fetch("/api/system-status", { cache: "no-store" });
      if (!response.ok) throw new Error("Status layanan belum dapat diperbarui.");
      setData(await response.json() as StatusPayload);
      setError("");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Status layanan belum dapat diperbarui.");
    } finally { setLoading(false); }
  }
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, []);
  const allOperational = data?.services.every((service) => service.state === "operational");
  return <div className="mt-9 space-y-5"><div className={`flex items-center justify-between rounded-2xl border p-5 ${allOperational ? "border-emerald-400/25 bg-emerald-400/[0.06]" : "border-amber-300/25 bg-amber-300/[0.06]"}`}><div className="flex items-center gap-3">{allOperational ? <CheckCircle2 className="size-6 text-emerald-300" /> : <TriangleAlert className="size-6 text-amber-300" />}<div><p className="font-bold">{allOperational ? "Seluruh layanan beroperasi normal" : "Sebagian layanan sedang ditinjau"}</p><p className="mt-1 text-xs text-white/45">Status diperbarui langsung dari pemeriksaan sistem.</p></div></div><button type="button" onClick={() => void load()} disabled={loading} className="inline-flex items-center gap-2 rounded-lg border border-white/10 px-3 py-2 text-xs font-semibold text-white/70 transition hover:bg-white/[0.08] disabled:opacity-50"><RefreshCw className={`size-3.5 ${loading ? "animate-spin" : ""}`} />Perbarui</button></div>{error ? <p className="rounded-xl border border-rose-400/25 bg-rose-400/[0.06] p-4 text-sm text-rose-100">{error}</p> : <div className="grid gap-3 sm:grid-cols-2">{data?.services.map((service) => <div key={service.id} className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-5"><div className="flex items-center justify-between gap-3"><h2 className="font-bold">{service.name}</h2><span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide ${service.state === "operational" ? "bg-emerald-300/15 text-emerald-200" : "bg-amber-300/15 text-amber-200"}`}>{service.state === "operational" ? "Normal" : "Ditinjau"}</span></div><p className="mt-3 text-sm leading-6 text-white/45">{service.detail}</p></div>)}</div>}{data && (data.merchant.legalName || data.merchant.registrationId || data.merchant.address) && <div className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-5"><h2 className="font-bold">Identitas merchant</h2><div className="mt-3 grid gap-2 text-sm text-white/45 sm:grid-cols-3">{data.merchant.legalName && <p>{data.merchant.legalName}</p>}{data.merchant.registrationId && <p>Nomor usaha: {data.merchant.registrationId}</p>}{data.merchant.address && <p>{data.merchant.address}</p>}</div></div>}<p className="text-center text-xs text-white/30">{data ? `Pembaruan terakhir: ${new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(data.updatedAt))}` : "Memeriksa status layanan..."}</p></div>;
}
