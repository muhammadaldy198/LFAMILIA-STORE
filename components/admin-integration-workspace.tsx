"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { CreditCard, KeyRound, LogIn, Mail, Network, Save, Server, ShieldCheck } from "lucide-react";
import { CopyUrl, Field, Panel, Status, TabBar, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

const tabs = ["Ringkasan", "DOKU Direct API", "Midtrans Snap", "Digiflazz", "KokinPay", "Google Login", "Resend Email", "Relay & Keamanan"] as const;
type Tab = (typeof tabs)[number];
type Provider = "digiflazz" | "kokinpay" | "google" | "resend" | "relay" | "security";
type Environment = "development" | "production" | "global";
type PaymentEnvironment = "sandbox" | "production";
type Profile = { provider: Provider; environment: Environment; configured: boolean; decryptionError: boolean };
type Callback = { id: string; label: string; description: string; url: string };
type Overview = {
  encryptionReady: boolean;
  encryptionHint: string;
  selections: { digiflazzEnvironment: "development" | "production" };
  profiles: Profile[];
  callbacks: Callback[];
};
type RelayResult = { provider: string; label: string; connected: boolean; status: number | null; message: string };
type IntegrationPutResult = {
  error?: string;
  overview?: Overview;
  relay?: RelayResult[];
  digiflazz?: { connected?: boolean; balance?: number };
};
type PaymentOverview = {
  dokuEnvironment: PaymentEnvironment;
  midtransEnvironment: PaymentEnvironment;
  walletTopupGateway: "doku" | "midtrans";
  dokuMode: "direct";
  midtransMode: "snap";
  dokuDirectConfigured: boolean;
  midtransSnapConfigured: boolean;
  configured: {
    doku: Record<PaymentEnvironment, boolean>;
    midtrans: Record<PaymentEnvironment, boolean>;
  };
  callbacks: {
    dokuNotification: string;
    midtransSnapNotification: string;
    paymentReturn: string;
  };
};
type PaymentPutResult = { error?: string; overview?: PaymentOverview };

const fallbackWebhook = "https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback";
const defaultDokuUrl = (environment: PaymentEnvironment) =>
  environment === "production" ? "https://api.doku.com" : "https://api-sandbox.doku.com";

export function AdminIntegrationWorkspace() {
  const [tab, setTab] = useState<Tab>("Ringkasan");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [paymentOverview, setPaymentOverview] = useState<PaymentOverview | null>(null);
  const [digiflazzEnvironment, setDigiflazzEnvironment] = useState<"development" | "production">("development");
  const [dokuProfileEnvironment, setDokuProfileEnvironment] = useState<PaymentEnvironment>("sandbox");
  const [midtransProfileEnvironment, setMidtransProfileEnvironment] = useState<PaymentEnvironment>("sandbox");
  const [values, setValues] = useState<Record<string, string>>({
    transactionApiUrl: "https://api.digiflazz.com/v1/transaction",
    priceListUrl: "https://api.digiflazz.com/v1/price-list",
    relayOrigin: "https://digiflazz-relay.lfamiliastore.my.id",
    resendApiUrl: "https://api.resend.com/emails",
    resendDeliveryChannel: "email",
    dokuApiUrl: defaultDokuUrl("sandbox"),
  });
  const initializedPaymentEnvironments = useRef(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const setValue = (key: string, value: string) => setValues((current) => ({ ...current, [key]: value }));

  const load = useCallback(async () => {
    const [integrationResponse, paymentResponse] = await Promise.all([
      fetch("/api/panel/integrations", { cache: "no-store" }),
      fetch("/api/panel/payment-routing", { cache: "no-store" }),
    ]);
    const integrationPayload = await integrationResponse.json().catch(() => ({})) as Overview & { error?: string };
    const paymentPayload = await paymentResponse.json().catch(() => ({})) as PaymentOverview & { error?: string };
    if (!integrationResponse.ok) throw new Error(integrationPayload.error || "Integrasi gagal dimuat.");
    if (!paymentResponse.ok) throw new Error(paymentPayload.error || "Konfigurasi payment gateway gagal dimuat.");

    setOverview(integrationPayload);
    setPaymentOverview(paymentPayload);
    setDigiflazzEnvironment(integrationPayload.selections.digiflazzEnvironment);
    if (!initializedPaymentEnvironments.current) {
      setDokuProfileEnvironment(paymentPayload.dokuEnvironment);
      setMidtransProfileEnvironment(paymentPayload.midtransEnvironment);
      setValues((current) => ({ ...current, dokuApiUrl: defaultDokuUrl(paymentPayload.dokuEnvironment) }));
      initializedPaymentEnvironments.current = true;
    }
    return { integration: integrationPayload, payment: paymentPayload };
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void load().catch((reason) => setError(reason instanceof Error ? reason.message : "Integrasi gagal dimuat."));
    }, 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const configured = useMemo(
    () => new Set((overview?.profiles ?? [])
      .filter((profile) => profile.configured && !profile.decryptionError)
      .map((profile) => `${profile.provider}:${profile.environment}`)),
    [overview],
  );
  const isConfigured = (provider: Provider, environment: Environment) => configured.has(`${provider}:${environment}`);
  const digiflazzWebhook = overview?.callbacks.find((item) => item.id === "digiflazz")?.url || fallbackWebhook;
  const dokuConfigured = Boolean(paymentOverview?.configured.doku[dokuProfileEnvironment]);
  const midtransConfigured = Boolean(paymentOverview?.configured.midtrans[midtransProfileEnvironment]);

  async function put(body: object) {
    const response = await fetch("/api/panel/integrations", {
      method: "PUT",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({})) as IntegrationPutResult;
    if (!response.ok) throw new Error(payload.error || "Integrasi gagal disimpan.");
    if (payload.overview) setOverview(payload.overview);
    return payload;
  }

  async function paymentPut(body: object) {
    const response = await fetch("/api/panel/payment-routing", {
      method: "PUT",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({})) as PaymentPutResult;
    if (!response.ok) throw new Error(payload.error || "Konfigurasi payment gateway gagal disimpan.");
    if (payload.overview) setPaymentOverview(payload.overview);
    return payload;
  }

  async function save() {
    setBusy(true);
    setMessage("");
    setError("");
    try {
      if (tab === "DOKU Direct API") {
        const gatewayValues = {
          clientId: values.dokuClientId || "",
          secretKey: values.dokuSecretKey || "",
          privateKey: values.dokuPrivateKey || "",
          privateKeyPassphrase: values.dokuPrivateKeyPassphrase || "",
          apiUrl: values.dokuApiUrl || defaultDokuUrl(dokuProfileEnvironment),
          qrisMerchantId: values.dokuQrisMerchantId || "",
          qrisTerminalId: values.dokuQrisTerminalId || "",
          qrisPostalCode: values.dokuQrisPostalCode || "",
          vaConfigJson: values.dokuVaConfigJson || "",
        };
        const enteredCoreCredential = [gatewayValues.clientId, gatewayValues.secretKey, gatewayValues.privateKey].some((value) => value.trim());
        if (!dokuConfigured && !enteredCoreCredential) throw new Error("Isi Client ID, Secret Key, dan RSA Private Key DOKU terlebih dahulu.");
        await paymentPut({
          action: "save_profile",
          provider: "doku",
          mode: "direct",
          environment: dokuProfileEnvironment,
          values: gatewayValues,
        });
        setValues((current) => ({
          ...current,
          dokuClientId: "",
          dokuSecretKey: "",
          dokuPrivateKey: "",
          dokuPrivateKeyPassphrase: "",
          dokuQrisMerchantId: "",
          dokuQrisTerminalId: "",
          dokuQrisPostalCode: "",
          dokuVaConfigJson: "",
          dokuApiUrl: defaultDokuUrl(dokuProfileEnvironment),
        }));
      } else if (tab === "Midtrans Snap") {
        const gatewayValues = {
          serverKey: values.midtransServerKey || "",
          clientKey: values.midtransClientKey || "",
        };
        const entered = Object.values(gatewayValues).some((value) => value.trim());
        if (!midtransConfigured && !entered) throw new Error("Isi Server Key dan Client Key Midtrans terlebih dahulu.");
        await paymentPut({
          action: "save_profile",
          provider: "midtrans",
          mode: "snap",
          environment: midtransProfileEnvironment,
          values: gatewayValues,
        });
        setValues((current) => ({ ...current, midtransServerKey: "", midtransClientKey: "" }));
      } else if (tab === "Digiflazz") {
        await put({
          action: "save_profile",
          provider: "digiflazz",
          mode: "direct",
          environment: digiflazzEnvironment,
          values: {
            username: values.username || "",
            apiKey: values.apiKey || "",
            webhookSecret: values.webhookSecret || "",
            transactionApiUrl: values.transactionApiUrl || "",
            priceListUrl: values.priceListUrl || "",
          },
        });
        await put({ action: "save_selections", selections: { digiflazzEnvironment } });
      } else if (tab === "KokinPay") {
        await put({ action: "save_profile", provider: "kokinpay", mode: "service", environment: "global", values: { apiKey: values.kokinpayApiKey || "" } });
      } else if (tab === "Google Login") {
        await put({ action: "save_profile", provider: "google", mode: "service", environment: "global", values: { clientId: values.googleClientId || "" } });
      } else if (tab === "Resend Email") {
        await put({
          action: "save_profile",
          provider: "resend",
          mode: "service",
          environment: "global",
          values: {
            apiKey: values.resendApiKey || "",
            fromEmail: values.resendFromEmail || "",
            apiUrl: values.resendApiUrl || "",
            deliveryChannel: values.resendDeliveryChannel || "email",
          },
        });
      } else if (tab === "Relay & Keamanan") {
        await put({
          action: "save_profile",
          provider: "relay",
          mode: "service",
          environment: "global",
          values: { digiflazzOrigin: values.relayOrigin || "", hosts: values.relayHosts || "", token: values.relayToken || "" },
        });
        if (values.voucherEncryptionKey?.trim()) {
          await put({
            action: "save_profile",
            provider: "security",
            mode: "service",
            environment: "global",
            values: { voucherEncryptionKey: values.voucherEncryptionKey },
          });
        }
      }
      await load();
      setValues((current) => ({
        ...current,
        apiKey: "",
        webhookSecret: "",
        kokinpayApiKey: "",
        resendApiKey: "",
        relayToken: "",
        voucherEncryptionKey: "",
      }));
      setMessage("Konfigurasi tersimpan aman di backend.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Integrasi gagal disimpan.");
    } finally {
      setBusy(false);
    }
  }

  async function test() {
    setBusy(true);
    setMessage("");
    setError("");
    try {
      if (tab === "Digiflazz") {
        const result = await put({ action: "test_digiflazz" });
        setMessage(`Koneksi Digiflazz berhasil. Saldo terbaca: Rp${Number(result.digiflazz?.balance || 0).toLocaleString("id-ID")}.`);
      } else if (tab === "Relay & Keamanan") {
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
      } else if (tab === "DOKU Direct API" || tab === "Midtrans Snap") {
        const response = await fetch("/api/panel/payment-routing", { cache: "no-store" });
        const payload = await response.json().catch(() => ({})) as PaymentOverview & { error?: string };
        if (!response.ok) throw new Error(payload.error || "Status payment gateway gagal dimuat.");
        setPaymentOverview(payload);
        if (tab === "DOKU Direct API") {
          if (!payload.configured.doku[dokuProfileEnvironment]) throw new Error(`Kredensial DOKU Direct API ${dokuProfileEnvironment} belum lengkap.`);
          setMessage(`Konfigurasi DOKU Direct API ${dokuProfileEnvironment} lengkap dan dapat dibaca backend.`);
        } else {
          if (!payload.configured.midtrans[midtransProfileEnvironment]) throw new Error(`Kredensial Midtrans Snap ${midtransProfileEnvironment} belum lengkap.`);
          setMessage(`Konfigurasi Midtrans Snap ${midtransProfileEnvironment} lengkap dan dapat dibaca backend.`);
        }
      } else if (tab === "Google Login") {
        const response = await fetch("/api/auth/google/status", { cache: "no-store" });
        const payload = await response.json().catch(() => ({})) as { enabled?: boolean; clientId?: string | null };
        if (!response.ok || !payload.enabled || !payload.clientId) throw new Error("Google Login belum aktif. Simpan Client ID yang valid terlebih dahulu.");
        setMessage("Google Login aktif. Client ID berhasil dibaca oleh endpoint customer.");
      } else {
        const latest = await load();
        const mapping: Partial<Record<Tab, [Provider, Environment, string]>> = {
          "KokinPay": ["kokinpay", "global", "KokinPay"],
          "Resend Email": ["resend", "global", "Resend Email"],
        };
        const target = mapping[tab];
        if (!target) throw new Error("Tidak ada pemeriksaan untuk menu ini.");
        const [provider, environment, label] = target;
        const ready = latest.integration.profiles.some((profile) =>
          profile.provider === provider &&
          profile.environment === environment &&
          profile.configured &&
          !profile.decryptionError,
        );
        if (!ready) throw new Error(`${label} belum dikonfigurasi atau kredensial tidak dapat dibuka.`);
        setMessage(`${label} tersimpan dan dapat dibaca backend.`);
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pemeriksaan gagal.");
    } finally {
      setBusy(false);
    }
  }

  function changeDokuEnvironment(value: PaymentEnvironment) {
    setDokuProfileEnvironment(value);
    setValues((current) => {
      const currentUrl = current.dokuApiUrl || "";
      const shouldReplace = !currentUrl || currentUrl === defaultDokuUrl("sandbox") || currentUrl === defaultDokuUrl("production");
      return shouldReplace ? { ...current, dokuApiUrl: defaultDokuUrl(value) } : current;
    });
  }

  return <div>
    <WorkspaceHeader
      title="Integrasi"
      description="Semua kredensial provider dan payment gateway dikelola di sini. Menu Pembayaran hanya untuk operasional, channel, toggle, routing, dan transaksi."
      actions={tab === "Ringkasan" ? undefined : <>
        <button type="button" onClick={test} disabled={busy} className={buttonClass}>Periksa</button>
        <button type="button" onClick={save} disabled={busy} className={primaryButtonClass}><Save className="size-3.5" />Simpan</button>
      </>}
    />
    {message && <p className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] text-emerald-700">{message}</p>}
    {error && <p className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[9px] text-red-700">{error}</p>}
    <TabBar tabs={[...tabs]} active={tab} onChange={(value) => setTab(value as Tab)} />

    {tab === "Ringkasan" && <div className="grid grid-cols-2 gap-4">
      <Card icon={<CreditCard className="size-5" />} title="DOKU Direct API" ready={Boolean(paymentOverview?.dokuDirectConfigured)} onClick={() => { setDokuProfileEnvironment(paymentOverview?.dokuEnvironment || "sandbox"); setTab("DOKU Direct API"); }} />
      <Card icon={<CreditCard className="size-5" />} title="Midtrans Snap" ready={Boolean(paymentOverview?.midtransSnapConfigured)} onClick={() => { setMidtransProfileEnvironment(paymentOverview?.midtransEnvironment || "sandbox"); setTab("Midtrans Snap"); }} />
      <Card icon={<Network className="size-5" />} title="Digiflazz" ready={isConfigured("digiflazz", digiflazzEnvironment)} onClick={() => setTab("Digiflazz")} />
      <Card icon={<KeyRound className="size-5" />} title="KokinPay" ready={isConfigured("kokinpay", "global")} onClick={() => setTab("KokinPay")} />
      <Card icon={<LogIn className="size-5" />} title="Google Login" ready={isConfigured("google", "global")} onClick={() => setTab("Google Login")} />
      <Card icon={<Mail className="size-5" />} title="Resend Email" ready={isConfigured("resend", "global")} onClick={() => setTab("Resend Email")} />
      <Card icon={<Server className="size-5" />} title="VPS Relay" ready={isConfigured("relay", "global")} onClick={() => setTab("Relay & Keamanan")} />
      <Panel title="Callback & Notification URL" description="Tempel URL berikut pada dashboard provider terkait." className="col-span-2">
        <div className="grid gap-3 p-4">
          <CopyUrl label="DOKU Direct API Notification" value={paymentOverview?.callbacks.dokuNotification || "/api/payments/doku/callback"} />
          <CopyUrl label="Midtrans Snap Notification" value={paymentOverview?.callbacks.midtransSnapNotification || "/api/payments/midtrans/snap/notification"} />
          <CopyUrl label="Digiflazz Webhook URL" value={digiflazzWebhook} />
        </div>
      </Panel>
    </div>}

    {tab === "DOKU Direct API" && <Panel
      title="DOKU Direct API"
      description="Credential disimpan terenkripsi per environment. DOKU Hosted Checkout tidak digunakan."
      action={<Status tone={dokuConfigured ? "green" : "amber"}>{dokuConfigured ? `${dokuProfileEnvironment} siap` : `${dokuProfileEnvironment} belum lengkap`}</Status>}
    >
      <div className="grid grid-cols-2 gap-4 p-4">
        <Select label="Credential Environment" value={dokuProfileEnvironment} onChange={(value) => changeDokuEnvironment(value as PaymentEnvironment)} options={["sandbox", "production"]} />
        <Text label="API URL" value={values.dokuApiUrl || defaultDokuUrl(dokuProfileEnvironment)} onChange={(value) => setValue("dokuApiUrl", value)} />
        <Text label="Client ID" value={values.dokuClientId || ""} onChange={(value) => setValue("dokuClientId", value)} />
        <Text label="Secret Key" secret value={values.dokuSecretKey || ""} onChange={(value) => setValue("dokuSecretKey", value)} />
        <TextArea label="RSA Private Key" secret value={values.dokuPrivateKey || ""} onChange={(value) => setValue("dokuPrivateKey", value)} placeholder="-----BEGIN PRIVATE KEY-----" wide />
        <Text label="Private Key Passphrase (opsional)" secret value={values.dokuPrivateKeyPassphrase || ""} onChange={(value) => setValue("dokuPrivateKeyPassphrase", value)} />
        <Text label="QRIS Merchant ID" value={values.dokuQrisMerchantId || ""} onChange={(value) => setValue("dokuQrisMerchantId", value)} />
        <Text label="QRIS Terminal ID" value={values.dokuQrisTerminalId || ""} onChange={(value) => setValue("dokuQrisTerminalId", value)} />
        <Text label="QRIS Postal Code" value={values.dokuQrisPostalCode || ""} onChange={(value) => setValue("dokuQrisPostalCode", value)} />
        <TextArea label="VA Config JSON" value={values.dokuVaConfigJson || ""} onChange={(value) => setValue("dokuVaConfigJson", value)} placeholder='{"BCA": {...}}' wide />
        <div className="col-span-2 rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-[9px] leading-4 text-blue-700">
          Field sensitif tidak pernah dikirim kembali ke browser setelah disimpan. Kosongkan field yang tidak ingin diubah; backend akan mempertahankan nilai lama.
        </div>
      </div>
    </Panel>}

    {tab === "Midtrans Snap" && <Panel
      title="Midtrans Snap"
      description="Credential Snap disimpan terenkripsi terpisah untuk Sandbox dan Production."
      action={<Status tone={midtransConfigured ? "green" : "amber"}>{midtransConfigured ? `${midtransProfileEnvironment} siap` : `${midtransProfileEnvironment} belum lengkap`}</Status>}
    >
      <div className="grid grid-cols-2 gap-4 p-4">
        <Select label="Credential Environment" value={midtransProfileEnvironment} onChange={(value) => setMidtransProfileEnvironment(value as PaymentEnvironment)} options={["sandbox", "production"]} />
        <div />
        <Text label="Server Key" secret value={values.midtransServerKey || ""} onChange={(value) => setValue("midtransServerKey", value)} />
        <Text label="Client Key" value={values.midtransClientKey || ""} onChange={(value) => setValue("midtransClientKey", value)} />
        <div className="col-span-2 rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-[9px] leading-4 text-blue-700">
          Server Key tidak pernah ditampilkan kembali setelah disimpan. Environment aktif untuk checkout/top up tetap dipilih dari menu Pembayaran.
        </div>
      </div>
    </Panel>}

    {tab === "Digiflazz" && <Panel title="Digiflazz" description="Dipanggil backend hanya setelah pembayaran benar-benar berhasil.">
      <div className="grid grid-cols-2 gap-4 p-4">
        <Select label="Environment" value={digiflazzEnvironment} onChange={(value) => setDigiflazzEnvironment(value as "development" | "production")} options={["development", "production"]} />
        <Text label="Username" value={values.username || ""} onChange={(value) => setValue("username", value)} />
        <Text label="API Key" secret value={values.apiKey || ""} onChange={(value) => setValue("apiKey", value)} />
        <Text label="Webhook Secret" secret value={values.webhookSecret || ""} onChange={(value) => setValue("webhookSecret", value)} />
        <Text label="Transaction URL" value={values.transactionApiUrl || ""} onChange={(value) => setValue("transactionApiUrl", value)} />
        <Text label="Pricelist URL" value={values.priceListUrl || ""} onChange={(value) => setValue("priceListUrl", value)} />
      </div>
    </Panel>}

    {tab === "KokinPay" && <Panel title="KokinPay" description="Credential untuk validasi nickname.">
      <div className="grid grid-cols-2 gap-4 p-4">
        <Text label="API Key" secret value={values.kokinpayApiKey || ""} onChange={(value) => setValue("kokinpayApiKey", value)} />
      </div>
    </Panel>}

    {tab === "Google Login" && <Panel title="Google Login" description="Google Identity Services untuk masuk/daftar pelanggan. Hanya Client ID yang dibutuhkan; tidak memakai Client Secret atau redirect URI.">
      <div className="grid gap-4 p-4">
        <Text label="Client ID" value={values.googleClientId || ""} onChange={(value) => setValue("googleClientId", value)} />
        <div className="rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-[9px] leading-4 text-blue-700">
          Di Google Cloud, gunakan OAuth client tipe Web application dan tambahkan domain production pada Authorized JavaScript origins. Contoh: https://lfamiliastore.my.id
        </div>
      </div>
    </Panel>}

    {tab === "Resend Email" && <Panel title="Resend Email" description="Pengiriman notifikasi transaksi.">
      <div className="grid grid-cols-2 gap-4 p-4">
        <Text label="API Key" secret value={values.resendApiKey || ""} onChange={(value) => setValue("resendApiKey", value)} />
        <Text label="From Email" value={values.resendFromEmail || ""} onChange={(value) => setValue("resendFromEmail", value)} />
        <Text label="API URL" value={values.resendApiUrl || ""} onChange={(value) => setValue("resendApiUrl", value)} />
        <Select label="Channel" value={values.resendDeliveryChannel || "email"} onChange={(value) => setValue("resendDeliveryChannel", value)} options={["email", "website"]} />
      </div>
    </Panel>}

    {tab === "Relay & Keamanan" && <div className="grid grid-cols-2 gap-4">
      <Panel title="VPS Relay" description="Hanya untuk request Digiflazz.">
        <div className="grid gap-4 p-4">
          <Text label="Relay URL" value={values.relayOrigin || ""} onChange={(value) => setValue("relayOrigin", value)} />
          <Text label="Host diizinkan" value={values.relayHosts || ""} onChange={(value) => setValue("relayHosts", value)} />
          <Text label="Relay Token" secret value={values.relayToken || ""} onChange={(value) => setValue("relayToken", value)} />
        </div>
      </Panel>
      <Panel title="Keamanan">
        <div className="grid gap-4 p-4">
          <Text label="Voucher Encryption Key" secret value={values.voucherEncryptionKey || ""} onChange={(value) => setValue("voucherEncryptionKey", value)} />
          <p className="text-[9px] text-[#718198]"><ShieldCheck className="mr-1 inline size-3.5 text-emerald-600" />Credential tidak dikirim kembali ke browser.</p>
        </div>
      </Panel>
    </div>}
  </div>;
}

function Card({ icon, title, ready, onClick }: { icon: React.ReactNode; title: string; ready: boolean; onClick(): void }) {
  return <button type="button" onClick={onClick} className="flex items-center gap-3 rounded-lg border border-[#e1e6ed] bg-white p-4 text-left">
    <span className="text-[#0769e9]">{icon}</span>
    <span className="flex-1 font-bold text-[#14213a]">{title}</span>
    <Status tone={ready ? "green" : "amber"}>{ready ? "Siap" : "Belum diisi"}</Status>
  </button>;
}

function Text({ label, value, onChange, secret = false }: { label: string; value: string; onChange(value: string): void; secret?: boolean }) {
  return <Field label={label}>
    <input type={secret ? "password" : "text"} className={inputClass} value={value} onChange={(event) => onChange(event.target.value)} autoComplete="new-password" />
  </Field>;
}

function TextArea({ label, value, onChange, secret = false, placeholder = "", wide = false }: { label: string; value: string; onChange(value: string): void; secret?: boolean; placeholder?: string; wide?: boolean }) {
  return <Field label={label} wide={wide}>
    <textarea
      className={`${inputClass} min-h-24 py-2 font-mono`}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      placeholder={placeholder}
      autoComplete="new-password"
      spellCheck={false}
      style={secret ? { WebkitTextSecurity: "disc" } as React.CSSProperties : undefined}
    />
  </Field>;
}

function Select({ label, value, onChange, options }: { label: string; value: string; onChange(value: string): void; options: string[] }) {
  return <Field label={label}>
    <select className={inputClass} value={value} onChange={(event) => onChange(event.target.value)}>
      {options.map((item) => <option key={item}>{item}</option>)}
    </select>
  </Field>;
}
