"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useCallback, useEffect, useState } from "react";
import { CheckCircle2, Eye, EyeOff, KeyRound, Save, XCircle } from "lucide-react";
import { CopyUrl, Field, Panel, Status, Toggle, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

type Environment = "sandbox" | "production";
type HostedOverview = {
  dokuMode: "checkout" | "direct";
  midtransMode: "snap" | "bisnap";
  dokuEnvironment: Environment;
  midtransEnvironment: Environment;
  hostedConfigured?: {
    doku?: Record<Environment, boolean>;
    midtrans?: Record<Environment, boolean>;
  };
  callbacks: {
    dokuNotification: string;
    midtransSnapNotification: string;
    paymentReturn: string;
  };
};

type Provider = "doku" | "midtrans";

async function jsonRequest(url: string, init?: RequestInit) {
  const response = await fetch(url, { cache: "no-store", ...init });
  const payload = await response.json().catch(() => ({})) as HostedOverview & { error?: string };
  if (!response.ok) throw new Error(payload.error || "Konfigurasi gateway gagal diproses.");
  return payload;
}

export function AdminHostedGatewayIntegration({ provider }: { provider: Provider }) {
  const [overview, setOverview] = useState<HostedOverview | null>(null);
  const [environment, setEnvironment] = useState<Environment>("sandbox");
  const [firstKey, setFirstKey] = useState("");
  const [secondKey, setSecondKey] = useState("");
  const [showSecrets, setShowSecrets] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    const next = await jsonRequest("/api/panel/payment-routing");
    setOverview(next);
    setEnvironment(provider === "doku" ? next.dokuEnvironment : next.midtransEnvironment);
    return next;
  }, [provider]);

  useEffect(() => { load().catch((reason) => setError(reason instanceof Error ? reason.message : "Konfigurasi gateway gagal dimuat.")); }, [load]);

  const configured = Boolean(overview?.hostedConfigured?.[provider]?.[environment]);
  const isDoku = provider === "doku";
  const title = isDoku ? "DOKU Checkout" : "Midtrans Snap";
  const firstLabel = isDoku ? "Client ID" : "Server Key";
  const secondLabel = isDoku ? "Secret Key" : "Client Key";
  const activeEnvironment = isDoku ? overview?.dokuEnvironment : overview?.midtransEnvironment;
  const activeMode = isDoku ? overview?.dokuMode : overview?.midtransMode;

  async function save() {
    setBusy(true); setMessage(""); setError("");
    try {
      const values = isDoku
        ? { clientId: firstKey, secretKey: secondKey }
        : { serverKey: firstKey, clientKey: secondKey };
      await jsonRequest("/api/panel/payment-routing", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "save_profile",
          provider,
          mode: isDoku ? "checkout" : "snap",
          environment,
          values,
        }),
      });
      setFirstKey(""); setSecondKey("");
      await load();
      setEnvironment(environment);
      setMessage(`${title} ${environment} berhasil disimpan terenkripsi.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Kredensial gagal disimpan.");
    } finally { setBusy(false); }
  }

  return <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4">
    <Panel title={`Kredensial ${title}`} description="Sandbox dan Production disimpan terpisah. Mengganti environment tidak menghapus credential environment lainnya." action={<button type="button" disabled={busy} onClick={save} className={primaryButtonClass}><Save className="size-3.5" />{busy ? "Menyimpan..." : "Simpan"}</button>}>
      {message && <Notice ok text={message} onClose={() => setMessage("")} />}
      {error && <Notice text={error} onClose={() => setError("")} />}
      <div className="grid grid-cols-2 gap-4 p-4">
        <Field label="Environment"><div className="flex items-center justify-between rounded-md border border-[#e3e8ef] bg-[#f8fafc] px-3 py-2"><span className={`text-[9px] ${environment === "sandbox" ? "font-extrabold text-[#1769e8]" : "font-semibold text-[#8190a5]"}`}>Sandbox</span><Toggle checked={environment === "production"} onChange={(checked) => setEnvironment(checked ? "production" : "sandbox")} /><span className={`text-[9px] ${environment === "production" ? "font-extrabold text-[#1769e8]" : "font-semibold text-[#8190a5]"}`}>Production</span></div></Field>
        <Field label="Status"><div className="flex h-9 items-center"><Status tone={configured ? "green" : "amber"}>{configured ? "Tersimpan" : "Belum diisi"}</Status></div></Field>
        <SecretField label={firstLabel} value={firstKey} onChange={setFirstKey} show={showSecrets} placeholder={configured ? "Tersimpan — isi hanya untuk mengganti" : `Masukkan ${firstLabel}`} />
        <SecretField label={secondLabel} value={secondKey} onChange={setSecondKey} show={showSecrets} placeholder={configured ? "Tersimpan — isi hanya untuk mengganti" : `Masukkan ${secondLabel}`} />
        <div className="col-span-2 flex items-center justify-between rounded-md border border-[#e3e8ef] bg-[#f8fafc] px-3 py-2"><div><strong className="block text-[9px] text-[#34445f]">Tampilkan field credential</strong><span className="text-[8px] text-[#8190a5]">Credential tersimpan tidak pernah dibaca kembali ke browser.</span></div><button type="button" className={buttonClass} onClick={() => setShowSecrets((current) => !current)}>{showSecrets ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}{showSecrets ? "Sembunyikan" : "Lihat Field"}</button></div>
      </div>
    </Panel>

    <div className="space-y-4">
      <Panel title="Status Pemakaian" description="Mode hosted aktif diatur dari menu Pembayaran → Metode Pembayaran."><div className="space-y-3 p-4 text-[9px] text-[#42516a]"><div className="flex items-center justify-between"><span>Mode aktif</span><strong>{activeMode === (isDoku ? "checkout" : "snap") ? title : "Tidak aktif"}</strong></div><div className="flex items-center justify-between"><span>Environment aktif</span><strong className="capitalize">{activeEnvironment || "sandbox"}</strong></div><div className="flex items-center justify-between"><span>{environment} credential</span><Status tone={configured ? "green" : "amber"}>{configured ? "Siap" : "Belum siap"}</Status></div></div></Panel>
      <Panel title="URL Gateway" description="Salin ke dashboard provider jika diperlukan."><div className="space-y-2 p-4">{isDoku ? <><CopyUrl label="Notification URL" value={overview?.callbacks.dokuNotification || "https://lfamiliastore.my.id/api/payments/doku/callback"} /><CopyUrl label="Return URL" value={overview?.callbacks.paymentReturn || "https://lfamiliastore.my.id/payment"} /></> : <><CopyUrl label="Payment Notification URL" value={overview?.callbacks.midtransSnapNotification || "https://lfamiliastore.my.id/api/payments/midtrans/snap/notification"} /><CopyUrl label="Return URL" value={overview?.callbacks.paymentReturn || "https://lfamiliastore.my.id/payment"} /></>}</div></Panel>
    </div>
  </div>;
}

function SecretField({ label, value, onChange, show, placeholder }: { label: string; value: string; onChange(value: string): void; show: boolean; placeholder: string }) {
  return <Field label={label}><div className="relative"><KeyRound className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8a98aa]" /><input type={show ? "text" : "password"} className={`${inputClass} pl-8`} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} autoComplete="new-password" /></div></Field>;
}
function Notice({ ok = false, text, onClose }: { ok?: boolean; text: string; onClose(): void }) {
  return <button type="button" onClick={onClose} className={`m-4 mb-0 flex w-[calc(100%-2rem)] items-center gap-2 rounded-md border px-3 py-2 text-left text-[9px] font-semibold ${ok ? "border-emerald-200 bg-emerald-50 text-emerald-700" : "border-red-200 bg-red-50 text-red-700"}`}>{ok ? <CheckCircle2 className="size-3.5" /> : <XCircle className="size-3.5" />}<span className="flex-1">{text}</span></button>;
}
