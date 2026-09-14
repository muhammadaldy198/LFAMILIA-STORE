"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useCallback, useEffect, useState } from "react";
import { CheckCircle2, Route, Save, ShieldCheck } from "lucide-react";
import { Field, Panel, Status, Toggle, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

type Gateway = "doku" | "midtrans";
type Environment = "sandbox" | "production";
type RoutingOverview = {
  dokuMode: "checkout" | "direct";
  midtransMode: "snap" | "bisnap";
  dokuEnvironment: Environment;
  midtransEnvironment: Environment;
  dokuCheckoutConfigured: boolean;
  midtransSnapConfigured: boolean;
  callbacks: {
    dokuNotification: string;
    midtransSnapNotification: string;
    paymentReturn: string;
  };
};
type Channel = {
  id: number | null;
  method: "va" | "ewallet" | "qris";
  channel: string;
  name: string;
  description: string;
  imageUrl?: string;
  isActive: boolean;
  sortOrder: number;
  gateway: Gateway;
  gatewayConfig?: Record<string, string>;
};
type JsonPayload = { error?: string; [key: string]: unknown };

async function jsonRequest(url: string, init?: RequestInit) {
  const response = await fetch(url, { cache: "no-store", ...init });
  const payload = await response.json().catch(() => ({})) as JsonPayload;
  if (!response.ok) throw new Error(payload.error || "Pengaturan pembayaran gagal diproses.");
  return payload;
}

export function AdminGatewayRoutingPanel() {
  const [routing, setRouting] = useState<RoutingOverview | null>(null);
  const [channels, setChannels] = useState<Channel[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    const [routingPayload, methodPayload] = await Promise.all([
      jsonRequest("/api/panel/payment-routing"),
      jsonRequest("/api/panel/payment-methods"),
    ]);
    setRouting(routingPayload as RoutingOverview);
    setChannels((methodPayload.channels || []) as Channel[]);
  }, []);

  useEffect(() => { load().catch((reason) => setError(reason instanceof Error ? reason.message : "Routing pembayaran gagal dimuat.")); }, [load]);

  async function saveAll() {
    if (!routing) return;
    setBusy(true); setError(""); setMessage("");
    try {
      await jsonRequest("/api/panel/payment-routing", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "save_modes",
          dokuMode: routing.dokuMode,
          midtransMode: routing.midtransMode,
          dokuEnvironment: routing.dokuEnvironment,
          midtransEnvironment: routing.midtransEnvironment,
        }),
      });
      for (const channel of channels) {
        await jsonRequest("/api/panel/payment-methods", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            id: channel.id,
            method: channel.method,
            channel: channel.channel,
            name: channel.name,
            description: channel.description,
            imageUrl: channel.imageUrl || "",
            isActive: channel.isActive,
            sortOrder: channel.sortOrder,
            gateway: channel.gateway,
            gatewayConfig: channel.gatewayConfig || {},
          }),
        });
      }
      await load();
      setMessage("Mode gateway dan routing semua channel berhasil disimpan.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Routing pembayaran gagal disimpan.");
    } finally { setBusy(false); }
  }

  if (!routing) return null;
  return <div className="mb-4">
    <Panel title="Routing Gateway" description="Tidak hardcode: pilih mode tiap gateway dan tentukan gateway untuk setiap channel. Top up saldo memakai routing channel yang sama." action={<button type="button" disabled={busy} onClick={saveAll} className={primaryButtonClass}><Save className="size-3.5" />{busy ? "Menyimpan..." : "Simpan Routing"}</button>}>
      {message && <div className="mb-3 flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] font-semibold text-emerald-700"><CheckCircle2 className="size-3.5" />{message}</div>}
      {error && <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[9px] font-semibold text-red-700">{error}</div>}
      <div className="mb-4 grid grid-cols-4 gap-3">
        <Field label="Mode DOKU"><select className={inputClass} value={routing.dokuMode} onChange={(event) => setRouting((current) => current ? { ...current, dokuMode: event.target.value as RoutingOverview["dokuMode"] } : current)}><option value="checkout">Checkout Biasa</option><option value="direct">Direct API</option></select></Field>
        <Field label="Environment DOKU"><select className={inputClass} value={routing.dokuEnvironment} onChange={(event) => setRouting((current) => current ? { ...current, dokuEnvironment: event.target.value as Environment } : current)}><option value="sandbox">Sandbox</option><option value="production">Production</option></select></Field>
        <Field label="Mode Midtrans"><select className={inputClass} value={routing.midtransMode} onChange={(event) => setRouting((current) => current ? { ...current, midtransMode: event.target.value as RoutingOverview["midtransMode"] } : current)}><option value="snap">Snap</option><option value="bisnap">BI-SNAP</option></select></Field>
        <Field label="Environment Midtrans"><select className={inputClass} value={routing.midtransEnvironment} onChange={(event) => setRouting((current) => current ? { ...current, midtransEnvironment: event.target.value as Environment } : current)}><option value="sandbox">Sandbox</option><option value="production">Production</option></select></Field>
      </div>
      <div className="overflow-x-auto rounded-md border border-[#e3e8ef]">
        <table className="w-full min-w-[760px] text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr><th className="px-3 py-2.5">Metode</th><th>Channel</th><th>Gateway</th><th>Aktif</th><th className="pr-3">Catatan</th></tr></thead><tbody className="divide-y divide-[#edf0f4]">{channels.map((channel, index) => <tr key={`${channel.method}:${channel.channel}`} className="text-[9px] text-[#42516a]"><td className="px-3 py-2.5"><Status tone={channel.method === "qris" ? "green" : channel.method === "ewallet" ? "amber" : "blue"}>{channel.method.toUpperCase()}</Status></td><td><strong className="text-[#23334e]">{channel.name}</strong><span className="ml-2 text-[8px] text-[#8a98aa]">{channel.channel}</span></td><td><select className={`${inputClass} h-8 w-32`} value={channel.gateway} onChange={(event) => setChannels((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, gateway: event.target.value as Gateway } : item))}><option value="doku">DOKU</option><option value="midtrans">Midtrans</option></select></td><td><Toggle checked={channel.isActive} onChange={(checked) => setChannels((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, isActive: checked } : item))} /></td><td className="pr-3 text-[8px] text-[#8a98aa]">Checkout produk + top up saldo mengikuti pilihan ini.</td></tr>)}</tbody></table>
      </div>
      <div className="mt-3 flex items-center gap-2 rounded-md bg-blue-50 px-3 py-2 text-[8px] text-blue-800"><Route className="size-3.5" />Contoh bebas: QRIS → DOKU, E-Wallet + VA → Midtrans. Nanti bisa dibalik dari panel tanpa edit repo.</div>
    </Panel>
  </div>;
}

export function AdminHostedGatewayCredentialsPanel() {
  const [routing, setRouting] = useState<RoutingOverview | null>(null);
  const [dokuClientId, setDokuClientId] = useState("");
  const [dokuSecretKey, setDokuSecretKey] = useState("");
  const [midtransServerKey, setMidtransServerKey] = useState("");
  const [midtransClientKey, setMidtransClientKey] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => setRouting(await jsonRequest("/api/panel/payment-routing") as RoutingOverview), []);
  useEffect(() => { load().catch((reason) => setError(reason instanceof Error ? reason.message : "Mode gateway gagal dimuat.")); }, [load]);

  async function saveHosted() {
    if (!routing) return;
    setBusy(true); setError(""); setMessage("");
    try {
      if (dokuClientId.trim() || dokuSecretKey.trim()) {
        await jsonRequest("/api/panel/payment-routing", {
          method: "PUT", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "save_profile", provider: "doku", mode: "checkout", environment: routing.dokuEnvironment, values: { clientId: dokuClientId, secretKey: dokuSecretKey } }),
        });
      }
      if (midtransServerKey.trim() || midtransClientKey.trim()) {
        await jsonRequest("/api/panel/payment-routing", {
          method: "PUT", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "save_profile", provider: "midtrans", mode: "snap", environment: routing.midtransEnvironment, values: { serverKey: midtransServerKey, clientKey: midtransClientKey } }),
        });
      }
      setDokuClientId(""); setDokuSecretKey(""); setMidtransServerKey(""); setMidtransClientKey("");
      await load();
      setMessage("Kredensial DOKU Checkout / Midtrans Snap berhasil disimpan terenkripsi.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Kredensial gagal disimpan."); }
    finally { setBusy(false); }
  }

  if (!routing) return null;
  return <div className="mb-4">
    <Panel title="Gateway Hosted / Sementara" description="DOKU Checkout dan Midtrans Snap. DOKU Direct API serta Midtrans BI-SNAP tetap tersimpan di bagian bawah dan bisa dipakai lagi nanti." action={<button type="button" disabled={busy} onClick={saveHosted} className={primaryButtonClass}><ShieldCheck className="size-3.5" />{busy ? "Menyimpan..." : "Simpan Kredensial"}</button>}>
      {message && <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] font-semibold text-emerald-700">{message}</div>}
      {error && <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[9px] font-semibold text-red-700">{error}</div>}
      <div className="grid grid-cols-2 gap-4">
        <div className="rounded-md border border-[#e3e8ef] p-3"><div className="mb-3 flex items-center justify-between"><strong className="text-[10px] text-[#26364f]">DOKU Checkout · {routing.dokuEnvironment}</strong><Status tone={routing.dokuCheckoutConfigured ? "green" : "amber"}>{routing.dokuCheckoutConfigured ? "Tersimpan" : "Belum diisi"}</Status></div><div className="grid gap-3"><Field label="Client ID"><input className={inputClass} value={dokuClientId} onChange={(event) => setDokuClientId(event.target.value)} autoComplete="off" /></Field><Field label="Secret Key"><input className={inputClass} type="password" value={dokuSecretKey} onChange={(event) => setDokuSecretKey(event.target.value)} autoComplete="new-password" /></Field></div></div>
        <div className="rounded-md border border-[#e3e8ef] p-3"><div className="mb-3 flex items-center justify-between"><strong className="text-[10px] text-[#26364f]">Midtrans Snap · {routing.midtransEnvironment}</strong><Status tone={routing.midtransSnapConfigured ? "green" : "amber"}>{routing.midtransSnapConfigured ? "Tersimpan" : "Belum diisi"}</Status></div><div className="grid gap-3"><Field label="Server Key"><input className={inputClass} type="password" value={midtransServerKey} onChange={(event) => setMidtransServerKey(event.target.value)} autoComplete="new-password" /></Field><Field label="Client Key"><input className={inputClass} value={midtransClientKey} onChange={(event) => setMidtransClientKey(event.target.value)} autoComplete="off" /></Field></div></div>
      </div>
      <div className="mt-4 grid grid-cols-3 gap-3 text-[8px]"><Callback label="DOKU Notification" value={routing.callbacks.dokuNotification} /><Callback label="Midtrans Snap Notification" value={routing.callbacks.midtransSnapNotification} /><Callback label="Return URL" value={routing.callbacks.paymentReturn} /></div>
    </Panel>
  </div>;
}

function Callback({ label, value }: { label: string; value: string }) {
  return <div className="rounded-md border border-[#e3e8ef] p-2.5"><span className="block font-bold text-[#52627a]">{label}</span><div className="mt-1 flex gap-1"><input readOnly value={value} className={`${inputClass} min-w-0 flex-1 font-mono text-[8px]`} /><button type="button" className={buttonClass} onClick={() => navigator.clipboard.writeText(value)}>Copy</button></div></div>;
}
