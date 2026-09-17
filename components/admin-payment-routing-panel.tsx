"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useCallback, useEffect, useState } from "react";
import { CheckCircle2, Route, Save, ShieldCheck } from "lucide-react";
import { Field, Panel, Status, Toggle, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

type Gateway = "doku" | "midtrans";
type Environment = "sandbox" | "production";
type RoutingOverview = {
  dokuMode: "direct";
  midtransMode: "snap";
  dokuEnvironment: Environment;
  midtransEnvironment: Environment;
  dokuDirectConfigured: boolean;
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
        body: JSON.stringify({ action: "save_modes", dokuEnvironment: routing.dokuEnvironment, midtransEnvironment: routing.midtransEnvironment }),
      });
      for (const channel of channels) {
        await jsonRequest("/api/panel/payment-methods", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            id: channel.id, method: channel.method, channel: channel.channel, name: channel.name,
            description: channel.description, imageUrl: channel.imageUrl || "", isActive: channel.isActive,
            sortOrder: channel.sortOrder, gateway: channel.gateway, gatewayConfig: channel.gatewayConfig || {},
          }),
        });
      }
      await load();
      setMessage("Environment dan routing semua channel berhasil disimpan.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Routing pembayaran gagal disimpan."); }
    finally { setBusy(false); }
  }

  if (!routing) return null;
  return <div className="mb-4">
    <Panel title="Routing Gateway" description="DOKU menggunakan Direct API. Midtrans tetap menggunakan Snap. Top up saldo mengikuti routing channel yang sama." action={<button type="button" disabled={busy} onClick={saveAll} className={primaryButtonClass}><Save className="size-3.5" />{busy ? "Menyimpan..." : "Simpan Routing"}</button>}>
      {message && <div className="mb-3 flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] font-semibold text-emerald-700"><CheckCircle2 className="size-3.5" />{message}</div>}
      {error && <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[9px] font-semibold text-red-700">{error}</div>}
      <div className="mb-4 grid grid-cols-2 gap-3">
        <Field label="Environment DOKU Direct API"><select className={inputClass} value={routing.dokuEnvironment} onChange={(event) => setRouting((current) => current ? { ...current, dokuEnvironment: event.target.value as Environment } : current)}><option value="sandbox">Sandbox</option><option value="production">Production</option></select></Field>
        <Field label="Environment Midtrans"><select className={inputClass} value={routing.midtransEnvironment} onChange={(event) => setRouting((current) => current ? { ...current, midtransEnvironment: event.target.value as Environment } : current)}><option value="sandbox">Sandbox</option><option value="production">Production</option></select></Field>
      </div>
      <div className="overflow-x-auto rounded-md border border-[#e3e8ef]">
        <table className="w-full min-w-[760px] text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr><th className="px-3 py-2.5">Metode</th><th>Channel</th><th>Gateway</th><th>Aktif</th><th className="pr-3">Catatan</th></tr></thead><tbody className="divide-y divide-[#edf0f4]">{channels.map((channel, index) => <tr key={`${channel.method}:${channel.channel}`} className="text-[9px] text-[#42516a]"><td className="px-3 py-2.5"><Status tone={channel.method === "qris" ? "green" : channel.method === "ewallet" ? "amber" : "blue"}>{channel.method.toUpperCase()}</Status></td><td><strong className="text-[#23334e]">{channel.name}</strong><span className="ml-2 text-[8px] text-[#8a98aa]">{channel.channel}</span></td><td><select className={`${inputClass} h-8 w-32`} value={channel.gateway} onChange={(event) => setChannels((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, gateway: event.target.value as Gateway } : item))}><option value="doku">DOKU</option><option value="midtrans">Midtrans</option></select></td><td><Toggle checked={channel.isActive} onChange={(checked) => setChannels((items) => items.map((item, itemIndex) => itemIndex === index ? { ...item, isActive: checked } : item))} /></td><td className="pr-3 text-[8px] text-[#8a98aa]">DOKU = Direct API, Midtrans = Snap.</td></tr>)}</tbody></table>
      </div>
      <div className="mt-3 flex items-center gap-2 rounded-md bg-blue-50 px-3 py-2 text-[8px] text-blue-800"><Route className="size-3.5" />Checkout DOKU hosted tidak dipakai untuk transaksi baru.</div>
    </Panel>
  </div>;
}

export function AdminHostedGatewayCredentialsPanel() {
  const [routing, setRouting] = useState<RoutingOverview | null>(null);
  const [doku, setDoku] = useState<Record<string, string>>({ apiUrl: "https://api-sandbox.doku.com" });
  const [midtransServerKey, setMidtransServerKey] = useState("");
  const [midtransClientKey, setMidtransClientKey] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    const next = await jsonRequest("/api/panel/payment-routing") as RoutingOverview;
    setRouting(next);
    setDoku((current) => ({ ...current, apiUrl: next.dokuEnvironment === "production" ? "https://api.doku.com" : "https://api-sandbox.doku.com" }));
  }, []);
  useEffect(() => { load().catch((reason) => setError(reason instanceof Error ? reason.message : "Mode gateway gagal dimuat.")); }, [load]);
  const setDokuValue = (key: string, value: string) => setDoku((current) => ({ ...current, [key]: value }));

  async function saveCredentials() {
    if (!routing) return;
    setBusy(true); setError(""); setMessage("");
    try {
      if (Object.entries(doku).some(([key, value]) => key !== "apiUrl" && value.trim())) {
        await jsonRequest("/api/panel/payment-routing", {
          method: "PUT", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "save_profile", provider: "doku", mode: "direct", environment: routing.dokuEnvironment, values: doku }),
        });
      }
      if (midtransServerKey.trim() || midtransClientKey.trim()) {
        await jsonRequest("/api/panel/payment-routing", {
          method: "PUT", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "save_profile", provider: "midtrans", mode: "snap", environment: routing.midtransEnvironment, values: { serverKey: midtransServerKey, clientKey: midtransClientKey } }),
        });
      }
      setDoku({ apiUrl: routing.dokuEnvironment === "production" ? "https://api.doku.com" : "https://api-sandbox.doku.com" });
      setMidtransServerKey(""); setMidtransClientKey("");
      await load();
      setMessage("Kredensial DOKU Direct API / Midtrans Snap berhasil disimpan terenkripsi.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Kredensial gagal disimpan."); }
    finally { setBusy(false); }
  }

  if (!routing) return null;
  return <div className="mb-4">
    <Panel title="Kredensial Gateway" description="DOKU Direct API dan Midtrans Snap." action={<button type="button" disabled={busy} onClick={saveCredentials} className={primaryButtonClass}><ShieldCheck className="size-3.5" />{busy ? "Menyimpan..." : "Simpan Kredensial"}</button>}>
      {message && <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] font-semibold text-emerald-700">{message}</div>}
      {error && <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[9px] font-semibold text-red-700">{error}</div>}
      <div className="grid gap-4 xl:grid-cols-2">
        <div className="rounded-md border border-[#e3e8ef] p-3">
          <div className="mb-3 flex items-center justify-between"><strong className="text-[10px] text-[#26364f]">DOKU Direct API · {routing.dokuEnvironment}</strong><Status tone={routing.dokuDirectConfigured ? "green" : "amber"}>{routing.dokuDirectConfigured ? "Tersimpan" : "Belum lengkap"}</Status></div>
          <div className="grid grid-cols-2 gap-3">
            <TextField label="Client ID" value={doku.clientId || ""} onChange={(value) => setDokuValue("clientId", value)} />
            <TextField label="Secret Key" secret value={doku.secretKey || ""} onChange={(value) => setDokuValue("secretKey", value)} />
            <Field label="RSA Private Key"><textarea className={`${inputClass} min-h-28 font-mono text-[8px]`} value={doku.privateKey || ""} onChange={(event) => setDokuValue("privateKey", event.target.value)} /></Field>
            <TextField label="Private Key Passphrase (opsional)" secret value={doku.privateKeyPassphrase || ""} onChange={(value) => setDokuValue("privateKeyPassphrase", value)} />
            <TextField label="API URL" value={doku.apiUrl || ""} onChange={(value) => setDokuValue("apiUrl", value)} />
            <TextField label="QRIS Merchant ID" value={doku.qrisMerchantId || ""} onChange={(value) => setDokuValue("qrisMerchantId", value)} />
            <TextField label="QRIS Terminal ID" value={doku.qrisTerminalId || ""} onChange={(value) => setDokuValue("qrisTerminalId", value)} />
            <TextField label="QRIS Postal Code" value={doku.qrisPostalCode || ""} onChange={(value) => setDokuValue("qrisPostalCode", value)} />
            <Field label="Virtual Account Config JSON"><textarea className={`${inputClass} min-h-28 font-mono text-[8px]`} value={doku.vaConfigJson || ""} onChange={(event) => setDokuValue("vaConfigJson", event.target.value)} placeholder='{"bca":{"partnerServiceId":"...","customerNo":"...","virtualAccountNo":"...","channel":"..."}}' /></Field>
          </div>
        </div>
        <div className="rounded-md border border-[#e3e8ef] p-3"><div className="mb-3 flex items-center justify-between"><strong className="text-[10px] text-[#26364f]">Midtrans Snap · {routing.midtransEnvironment}</strong><Status tone={routing.midtransSnapConfigured ? "green" : "amber"}>{routing.midtransSnapConfigured ? "Tersimpan" : "Belum diisi"}</Status></div><div className="grid gap-3"><TextField label="Server Key" secret value={midtransServerKey} onChange={setMidtransServerKey} /><TextField label="Client Key" value={midtransClientKey} onChange={setMidtransClientKey} /></div></div>
      </div>
      <div className="mt-4 grid grid-cols-3 gap-3 text-[8px]"><Callback label="DOKU Notification" value={routing.callbacks.dokuNotification} /><Callback label="Midtrans Snap Notification" value={routing.callbacks.midtransSnapNotification} /><Callback label="Return URL" value={routing.callbacks.paymentReturn} /></div>
    </Panel>
  </div>;
}

function TextField({ label, value, onChange, secret = false }: { label: string; value: string; onChange(value: string): void; secret?: boolean }) {
  return <Field label={label}><input className={inputClass} type={secret ? "password" : "text"} value={value} onChange={(event) => onChange(event.target.value)} autoComplete={secret ? "new-password" : "off"} /></Field>;
}
function Callback({ label, value }: { label: string; value: string }) {
  return <div className="rounded-md border border-[#e3e8ef] p-2.5"><span className="block font-bold text-[#52627a]">{label}</span><div className="mt-1 flex gap-1"><input readOnly value={value} className={`${inputClass} min-w-0 flex-1 font-mono text-[8px]`} /><button type="button" className={buttonClass} onClick={() => navigator.clipboard.writeText(value)}>Copy</button></div></div>;
}
