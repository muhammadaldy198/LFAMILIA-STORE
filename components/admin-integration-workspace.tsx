"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { KeyRound, LogIn, Mail, Network, Save, Server, ShieldCheck } from "lucide-react";
import { CopyUrl, Field, Panel, Status, TabBar, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

const tabs = ["Ringkasan", "Digiflazz", "KokinPay", "Google Login", "Resend Email", "Relay & Keamanan"] as const;
type Tab = (typeof tabs)[number];
type Provider = "digiflazz" | "kokinpay" | "google" | "resend" | "relay" | "security";
type Environment = "development" | "production" | "global";
type Profile = { provider: Provider; environment: Environment; configured: boolean; decryptionError: boolean };
type Callback = { id: string; label: string; description: string; url: string };
type Overview = { encryptionReady: boolean; encryptionHint: string; selections: { digiflazzEnvironment: "development" | "production" }; profiles: Profile[]; callbacks: Callback[] };
type RelayResult = { provider: string; label: string; connected: boolean; status: number | null; message: string };
type IntegrationPutResult = {
  error?: string;
  overview?: Overview;
  relay?: RelayResult[];
  digiflazz?: { connected?: boolean; balance?: number };
};

const fallbackWebhook = "https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback";

export function AdminIntegrationWorkspace() {
  const [tab, setTab] = useState<Tab>("Ringkasan");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [digiflazzEnvironment, setDigiflazzEnvironment] = useState<"development" | "production">("development");
  const [values, setValues] = useState<Record<string, string>>({
    transactionApiUrl: "https://api.digiflazz.com/v1/transaction",
    priceListUrl: "https://api.digiflazz.com/v1/price-list",
    relayOrigin: "https://digiflazz-relay.lfamiliastore.my.id",
    resendApiUrl: "https://api.resend.com/emails",
    resendDeliveryChannel: "email",
  });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const setValue = (key: string, value: string) => setValues((current) => ({ ...current, [key]: value }));

  const load = useCallback(async () => {
    const response = await fetch("/api/panel/integrations", { cache: "no-store" });
    const payload = await response.json().catch(() => ({})) as Overview & { error?: string };
    if (!response.ok) throw new Error(payload.error || "Integrasi gagal dimuat.");
    setOverview(payload);
    setDigiflazzEnvironment(payload.selections.digiflazzEnvironment);
    return payload;
  }, []);
  useEffect(() => {
    const timer = window.setTimeout(() => {
      void load().catch((reason) => setError(reason instanceof Error ? reason.message : "Integrasi gagal dimuat."));
    }, 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const configured = useMemo(() => new Set((overview?.profiles ?? []).filter((profile) => profile.configured && !profile.decryptionError).map((profile) => `${profile.provider}:${profile.environment}`)), [overview]);
  const isConfigured = (provider: Provider, environment: Environment) => configured.has(`${provider}:${environment}`);
  const digiflazzWebhook = overview?.callbacks.find((item) => item.id === "digiflazz")?.url || fallbackWebhook;
  const googleCallback = overview?.callbacks.find((item) => item.id === "google-oauth")?.url || "/api/auth/google/callback";

  async function put(body: object) {
    const response = await fetch("/api/panel/integrations", { method: "PUT", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
    const payload = await response.json().catch(() => ({})) as IntegrationPutResult;
    if (!response.ok) throw new Error(payload.error || "Integrasi gagal disimpan.");
    if (payload.overview) setOverview(payload.overview);
    return payload;
  }

  async function save() {
    setBusy(true); setMessage(""); setError("");
    try {
      if (tab === "Digiflazz") {
        await put({ action: "save_profile", provider: "digiflazz", mode: "direct", environment: digiflazzEnvironment, values: { username: values.username || "", apiKey: values.apiKey || "", webhookSecret: values.webhookSecret || "", transactionApiUrl: values.transactionApiUrl || "", priceListUrl: values.priceListUrl || "" } });
        await put({ action: "save_selections", selections: { digiflazzEnvironment } });
      } else if (tab === "KokinPay") {
        await put({ action: "save_profile", provider: "kokinpay", mode: "service", environment: "global", values: { apiKey: values.kokinpayApiKey || "" } });
      } else if (tab === "Google Login") {
        await put({ action: "save_profile", provider: "google", mode: "service", environment: "global", values: { clientId: values.googleClientId || "", clientSecret: values.googleClientSecret || "" } });
      } else if (tab === "Resend Email") {
        await put({ action: "save_profile", provider: "resend", mode: "service", environment: "global", values: { apiKey: values.resendApiKey || "", fromEmail: values.resendFromEmail || "", apiUrl: values.resendApiUrl || "", deliveryChannel: values.resendDeliveryChannel || "email" } });
      } else if (tab === "Relay & Keamanan") {
        await put({ action: "save_profile", provider: "relay", mode: "service", environment: "global", values: { digiflazzOrigin: values.relayOrigin || "", hosts: values.relayHosts || "", token: values.relayToken || "" } });
        if (values.voucherEncryptionKey?.trim()) await put({ action: "save_profile", provider: "security", mode: "service", environment: "global", values: { voucherEncryptionKey: values.voucherEncryptionKey } });
      }
      await load();
      setValues((current) => ({ ...current, apiKey: "", webhookSecret: "", kokinpayApiKey: "", googleClientSecret: "", resendApiKey: "", relayToken: "", voucherEncryptionKey: "" }));
      setMessage("Konfigurasi tersimpan aman di backend.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Integrasi gagal disimpan."); }
    finally { setBusy(false); }
  }

  async function test() {
    setBusy(true); setMessage(""); setError("");
    try {
      if (tab === "Digiflazz") {
        const result = await put({ action: "test_digiflazz" });
        setMessage(`Koneksi Digiflazz berhasil. Saldo terbaca: Rp${Number(result.digiflazz?.balance || 0).toLocaleString("id-ID")}.`);
      } else {
        const result = await put({ action: "test_relay" });
        const relay = result.relay ?? [];
        if (!relay.length) throw new Error("Backend tidak mengembalikan hasil pemeriksaan relay.");

        const details = relay.map((item) => {
          const connection = item.connected ? "Terhubung" : "Gagal";
          const http = item.status === null ? "" : ` · HTTP ${item.status}`;
          return `${item.label}: ${connection}${http} · ${item.message}`;
        }).join(" | ");

        if (relay.every((item) => item.connected)) setMessage(details);
        else setError(details);
      }
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Pemeriksaan gagal."); }
    finally { setBusy(false); }
  }

  return <div>
    <WorkspaceHeader title="Integrasi" description="Kredensial operasional toko. Pembayaran hosted dikelola di menu Pembayaran." actions={tab === "Ringkasan" ? undefined : <><button type="button" onClick={test} disabled={busy || (tab !== "Digiflazz" && tab !== "Relay & Keamanan")} className={buttonClass}>Periksa</button><button type="button" onClick={save} disabled={busy} className={primaryButtonClass}><Save className="size-3.5" />Simpan</button></>} />
    {message && <p className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] text-emerald-700">{message}</p>}
    {error && <p className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[9px] text-red-700">{error}</p>}
    <TabBar tabs={[...tabs]} active={tab} onChange={(value) => setTab(value as Tab)} />
    {tab === "Ringkasan" && <div className="grid grid-cols-2 gap-4"><Card icon={<Network className="size-5" />} title="Digiflazz" ready={isConfigured("digiflazz", digiflazzEnvironment)} onClick={() => setTab("Digiflazz")} /><Card icon={<KeyRound className="size-5" />} title="KokinPay" ready={isConfigured("kokinpay", "global")} onClick={() => setTab("KokinPay")} /><Card icon={<LogIn className="size-5" />} title="Google Login" ready={isConfigured("google", "global")} onClick={() => setTab("Google Login")} /><Card icon={<Mail className="size-5" />} title="Resend Email" ready={isConfigured("resend", "global")} onClick={() => setTab("Resend Email")} /><Card icon={<Server className="size-5" />} title="VPS Relay" ready={isConfigured("relay", "global")} onClick={() => setTab("Relay & Keamanan")} /><Panel title="Callback & Redirect URL" description="Gunakan URL ini pada dashboard provider terkait." className="col-span-2"><div className="grid gap-3 p-4"><CopyUrl label="Digiflazz Webhook URL" value={digiflazzWebhook} /><CopyUrl label="Google Authorized Redirect URI" value={googleCallback} /></div></Panel></div>}
    {tab === "Digiflazz" && <Panel title="Digiflazz" description="Dipanggil backend hanya setelah pembayaran benar-benar berhasil."><div className="grid grid-cols-2 gap-4 p-4"><Select label="Environment" value={digiflazzEnvironment} onChange={(value) => setDigiflazzEnvironment(value as "development" | "production")} options={["development", "production"]} /><Text label="Username" value={values.username || ""} onChange={(value) => setValue("username", value)} /><Text label="API Key" secret value={values.apiKey || ""} onChange={(value) => setValue("apiKey", value)} /><Text label="Webhook Secret" secret value={values.webhookSecret || ""} onChange={(value) => setValue("webhookSecret", value)} /><Text label="Transaction URL" value={values.transactionApiUrl || ""} onChange={(value) => setValue("transactionApiUrl", value)} /><Text label="Pricelist URL" value={values.priceListUrl || ""} onChange={(value) => setValue("priceListUrl", value)} /></div></Panel>}
    {tab === "KokinPay" && <Panel title="KokinPay" description="Credential untuk validasi nickname."><div className="grid grid-cols-2 gap-4 p-4"><Text label="API Key" secret value={values.kokinpayApiKey || ""} onChange={(value) => setValue("kokinpayApiKey", value)} /></div></Panel>}
    {tab === "Google Login" && <Panel title="Google Login" description="OAuth pelanggan. Client Secret disimpan terenkripsi dan tidak pernah dikirim kembali ke browser."><div className="grid grid-cols-2 gap-4 p-4"><Text label="Client ID" value={values.googleClientId || ""} onChange={(value) => setValue("googleClientId", value)} /><Text label="Client Secret" secret value={values.googleClientSecret || ""} onChange={(value) => setValue("googleClientSecret", value)} /><div className="col-span-2"><CopyUrl label="Authorized Redirect URI" value={googleCallback} /></div></div></Panel>}
    {tab === "Resend Email" && <Panel title="Resend Email" description="Pengiriman notifikasi transaksi."><div className="grid grid-cols-2 gap-4 p-4"><Text label="API Key" secret value={values.resendApiKey || ""} onChange={(value) => setValue("resendApiKey", value)} /><Text label="From Email" value={values.resendFromEmail || ""} onChange={(value) => setValue("resendFromEmail", value)} /><Text label="API URL" value={values.resendApiUrl || ""} onChange={(value) => setValue("resendApiUrl", value)} /><Select label="Channel" value={values.resendDeliveryChannel || "email"} onChange={(value) => setValue("resendDeliveryChannel", value)} options={["email", "website"]} /></div></Panel>}
    {tab === "Relay & Keamanan" && <div className="grid grid-cols-2 gap-4"><Panel title="VPS Relay" description="Hanya untuk request Digiflazz."><div className="grid gap-4 p-4"><Text label="Relay URL" value={values.relayOrigin || ""} onChange={(value) => setValue("relayOrigin", value)} /><Text label="Host diizinkan" value={values.relayHosts || ""} onChange={(value) => setValue("relayHosts", value)} /><Text label="Relay Token" secret value={values.relayToken || ""} onChange={(value) => setValue("relayToken", value)} /></div></Panel><Panel title="Keamanan"><div className="grid gap-4 p-4"><Text label="Voucher Encryption Key" secret value={values.voucherEncryptionKey || ""} onChange={(value) => setValue("voucherEncryptionKey", value)} /><p className="text-[9px] text-[#718198]"><ShieldCheck className="mr-1 inline size-3.5 text-emerald-600" />Credential tidak dikirim kembali ke browser.</p></div></Panel></div>}
  </div>;
}

function Card({ icon, title, ready, onClick }: { icon: React.ReactNode; title: string; ready: boolean; onClick(): void }) { return <button type="button" onClick={onClick} className="flex items-center gap-3 rounded-lg border border-[#e1e6ed] bg-white p-4 text-left"><span className="text-[#0769e9]">{icon}</span><span className="flex-1 font-bold text-[#14213a]">{title}</span><Status tone={ready ? "green" : "amber"}>{ready ? "Siap" : "Belum diisi"}</Status></button>; }
function Text({ label, value, onChange, secret = false }: { label: string; value: string; onChange(value: string): void; secret?: boolean }) { return <Field label={label}><input type={secret ? "password" : "text"} className={inputClass} value={value} onChange={(event) => onChange(event.target.value)} autoComplete="new-password" /></Field>; }
function Select({ label, value, onChange, options }: { label: string; value: string; onChange(value: string): void; options: string[] }) { return <Field label={label}><select className={inputClass} value={value} onChange={(event) => onChange(event.target.value)}>{options.map((item) => <option key={item}>{item}</option>)}</select></Field>; }
