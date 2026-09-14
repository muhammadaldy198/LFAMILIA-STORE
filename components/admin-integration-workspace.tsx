"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import { CheckCircle2, Eye, EyeOff, KeyRound, Mail, Network, RefreshCw, Save, Server, ShieldCheck, WalletCards, XCircle } from "lucide-react";
import { CopyUrl, Field, Panel, Status, TabBar, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

const tabs = ["Ringkasan", "DOKU Direct API", "Digiflazz", "Melostore Nickname", "Resend Email", "Relay & Keamanan"] as const;
type Tab = (typeof tabs)[number];
type Environment = "sandbox" | "production" | "development" | "global";
type Provider = "doku" | "digiflazz" | "melostore" | "resend" | "relay" | "security";
type Profile = { provider: Provider; mode: "direct" | "service"; environment: Environment; configured: boolean; configuredFields: string[]; decryptionError: boolean; updatedAt: string };
type Callback = { id: string; label: string; description: string; url: string };
type Overview = { encryptionReady: boolean; encryptionHint: string; selections: { dokuEnvironment: "sandbox" | "production"; digiflazzEnvironment: "development" | "production" }; profiles: Profile[]; callbacks: Callback[] };
type FormValues = Record<string, string>;
type DokuConnectionResult = { environment: "sandbox" | "production"; apiOrigin: string; tokenType: string; expiresIn: number };

function dokuApiUrlForEnvironment(environment: "sandbox" | "production") {
  return environment === "sandbox"
    ? "https://api-sandbox.doku.com"
    : "https://api.doku.com";
}

const defaultValues: FormValues = {
  dokuApiUrl: dokuApiUrlForEnvironment("sandbox"),
  digiflazzTransactionApiUrl: "https://api.digiflazz.com/v1/transaction",
  digiflazzPriceListUrl: "https://api.digiflazz.com/v1/price-list",
  resendApiUrl: "https://api.resend.com/emails",
  resendFromName: "LFAMILIA STORE",
  resendDeliveryChannel: "email",
  relayOrigin: "https://digiflazz-relay.lfamiliastore.my.id",
  relayHosts: "digiflazz-relay.lfamiliastore.my.id",
};

const fallbackCallbacks: Callback[] = [
  { id: "doku", label: "DOKU Notification URL", description: "Tempel di DOKU", url: "https://lfamiliastore.my.id/api/payments/doku/callback" },
  { id: "doku-fallback", label: "DOKU Return URL", description: "Tempel di DOKU", url: "https://lfamiliastore.my.id/payment" },
  { id: "digiflazz", label: "Digiflazz Webhook URL", description: "Tempel di Digiflazz", url: "https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback" },
];

export function AdminIntegrationWorkspace() {
  const [tab, setTab] = useState<Tab>("Ringkasan");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [values, setValues] = useState<FormValues>(defaultValues);
  const [dokuEnvironment, setDokuEnvironment] = useState<"sandbox" | "production">("sandbox");
  const [digiflazzEnvironment, setDigiflazzEnvironment] = useState<"development" | "production">("production");
  const [showSecrets, setShowSecrets] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const callbacks = overview?.callbacks.length ? overview.callbacks : fallbackCallbacks;

  const loadOverview = useCallback(async () => {
    const response = await fetch("/api/panel/integrations", { cache: "no-store" });
    const payload = await response.json().catch(() => ({})) as Partial<Overview> & { error?: string };
    if (!response.ok) throw new Error(payload.error || "Konfigurasi integrasi gagal dimuat.");
    const next = payload as Overview;
    setOverview(next);
    setDokuEnvironment(next.selections.dokuEnvironment);
    setDigiflazzEnvironment(next.selections.digiflazzEnvironment);
    setValues((current) => ({
      ...current,
      dokuApiUrl: dokuApiUrlForEnvironment(next.selections.dokuEnvironment),
    }));
    return next;
  }, []);

  useEffect(() => {
    loadOverview().catch((reason) => setError(reason instanceof Error ? reason.message : "Konfigurasi integrasi gagal dimuat."));
  }, [loadOverview]);

  const configured = useMemo(() => new Set((overview?.profiles ?? []).filter((item) => item.configured).map((item) => `${item.provider}:${item.environment}`)), [overview]);
  const setValue = (key: string, value: string) => setValues((current) => ({ ...current, [key]: value }));
  const profileConfigured = (provider: Provider, environment: Environment) => configured.has(`${provider}:${environment}`);

  async function put(body: object) {
    const response = await fetch("/api/panel/integrations", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
    const payload = await response.json().catch(() => ({})) as { error?: string; overview?: Overview; relay?: unknown; doku?: DokuConnectionResult };
    if (!response.ok) throw new Error(payload.error || "Konfigurasi integrasi gagal disimpan.");
    if (payload.overview) setOverview(payload.overview);
    return payload;
  }

  async function saveProfile(provider: Provider, mode: "direct" | "service", environment: Environment, fields: Record<string, string>) {
    await put({ action: "save_profile", provider, mode, environment, values: Object.fromEntries(Object.entries(fields).filter(([, value]) => value.trim())), clearFields: [] });
  }

  async function save() {
    setMessage(""); setError("");
    if (tab === "Ringkasan") { setError("Pilih integrasi yang ingin disimpan."); return; }
    setBusy(true);
    try {
      if (tab === "DOKU Direct API") {
        await saveProfile("doku", "direct", dokuEnvironment, {
          clientId: values.dokuClientId || "",
          secretKey: values.dokuSecretKey || "",
          privateKey: values.dokuPrivateKey || "",
          privateKeyPassphrase: values.dokuPrivateKeyPassphrase || "",
          apiUrl: dokuApiUrlForEnvironment(dokuEnvironment),
          qrisMerchantId: values.dokuMerchantId || "",
          qrisTerminalId: values.dokuTerminalId || "",
          qrisPostalCode: values.dokuPostalCode || "",
          vaConfigJson: values.dokuVaConfig || "",
        });
        await put({ action: "save_selections", selections: { dokuEnvironment } });
      } else if (tab === "Digiflazz") {
        await saveProfile("digiflazz", "direct", digiflazzEnvironment, { username: values.digiflazzUsername || "", apiKey: values.digiflazzApiKey || "", webhookSecret: values.digiflazzWebhookSecret || "", transactionApiUrl: values.digiflazzTransactionApiUrl || "", priceListUrl: values.digiflazzPriceListUrl || "" });
        await put({ action: "save_selections", selections: { digiflazzEnvironment } });
      } else if (tab === "Melostore Nickname") {
        await saveProfile("melostore", "service", "global", { apiUrl: values.melostoreApiUrl || "", apiKey: values.melostoreApiKey || "", secretKey: values.melostoreSecretKey || "", nicknameApiKey: values.melostoreNicknameApiKey || "" });
      } else if (tab === "Resend Email") {
        await saveProfile("resend", "service", "global", { apiKey: values.resendApiKey || "", fromEmail: values.resendFromEmail || "", apiUrl: values.resendApiUrl || "", deliveryChannel: values.resendDeliveryChannel || "email" });
      } else {
        await saveProfile("relay", "service", "global", { digiflazzOrigin: values.relayOrigin || "", hosts: values.relayHosts || "", token: values.relayToken || "" });
        if (values.voucherEncryptionKey?.trim()) await saveProfile("security", "service", "global", { voucherEncryptionKey: values.voucherEncryptionKey });
      }
      await loadOverview();
      setMessage(`${tab} berhasil disimpan terenkripsi di backend.`);
      setValues((current) => ({ ...current, dokuClientId: "", dokuSecretKey: "", dokuPrivateKey: "", dokuPrivateKeyPassphrase: "", digiflazzApiKey: "", digiflazzWebhookSecret: "", melostoreApiKey: "", melostoreSecretKey: "", melostoreNicknameApiKey: "", resendApiKey: "", relayToken: "", voucherEncryptionKey: "" }));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Konfigurasi integrasi gagal disimpan.");
    } finally { setBusy(false); }
  }

  async function testCurrent() {
    setMessage(""); setError(""); setBusy(true);
    try {
      if (tab === "Relay & Keamanan") {
        await put({ action: "test_relay" });
        setMessage("Relay Digiflazz merespons pemeriksaan backend.");
      } else if (tab === "DOKU Direct API") {
        const latest = await loadOverview();
        const ready = latest.profiles.some((item) => item.provider === "doku" && item.environment === dokuEnvironment && item.configured && !item.decryptionError);
        if (!ready) throw new Error(`Kredensial DOKU ${dokuEnvironment} belum lengkap atau belum bisa didekripsi.`);
        const result = await put({ action: "test_doku", environment: dokuEnvironment });
        if (!result.doku) throw new Error("DOKU tidak mengembalikan hasil tes koneksi.");
        setMessage(`Tes koneksi DOKU ${result.doku.environment} berhasil. B2B token diterbitkan dari ${result.doku.apiOrigin} dan berlaku sekitar ${result.doku.expiresIn} detik.`);
      } else {
        const latest = await loadOverview();
        const target = tab === "Digiflazz" ? ["digiflazz", digiflazzEnvironment] : tab === "Melostore Nickname" ? ["melostore", "global"] : tab === "Resend Email" ? ["resend", "global"] : null;
        const ready = target && latest.profiles.some((item) => item.provider === target[0] && item.environment === target[1] && item.configured && !item.decryptionError);
        if (!ready) throw new Error("Kredensial belum lengkap atau belum bisa didekripsi oleh backend.");
        setMessage(`${tab} terbaca dan siap dipakai oleh backend.`);
      }
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Pemeriksaan integrasi gagal."); }
    finally { setBusy(false); }
  }

  async function checkNickname() {
    setMessage(""); setError(""); setBusy(true);
    try {
      const response = await fetch("/api/nickname", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ game: values.nicknameGame || "", userId: values.nicknameUserId || "", server: values.nicknameServer || undefined }) });
      const payload = await response.json().catch(() => ({})) as { error?: string; nickname?: string | null; supported?: boolean };
      if (!response.ok) throw new Error(payload.error || "Nickname tidak ditemukan.");
      setMessage(payload.supported ? `Nickname ditemukan: ${payload.nickname || "-"}` : "Game ini tidak memakai pemeriksaan nickname dan dapat langsung checkout.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Pemeriksaan nickname gagal."); }
    finally { setBusy(false); }
  }

  const callback = (id: string, fallback: string) => callbacks.find((item) => item.id === id)?.url || fallback;
  const relayBase = values.relayOrigin || defaultValues.relayOrigin;

  return <div>
    <WorkspaceHeader title="Integrasi" description="Pusat konfigurasi DOKU, Digiflazz, Melostore, email, callback, relay, dan keamanan. Khusus Super Admin." actions={<><button type="button" disabled={busy || tab === "Ringkasan"} onClick={testCurrent} className={buttonClass}><RefreshCw className={`size-3.5 ${busy ? "animate-spin" : ""}`} />{tab === "DOKU Direct API" ? "Tes Koneksi DOKU" : "Periksa Konfigurasi"}</button><button type="button" disabled={busy || tab === "Ringkasan"} onClick={save} className={primaryButtonClass}><Save className="size-3.5" />{busy ? "Memproses..." : "Simpan"}</button></>} />
    {message && <Notice ok text={message} onClose={() => setMessage("")} />}
    {error && <Notice text={error} onClose={() => setError("")} />}
    {overview && !overview.encryptionReady && <Notice text={overview.encryptionHint} onClose={() => {}} />}
    <TabBar tabs={[...tabs]} active={tab} onChange={(value) => { setTab(value as Tab); setMessage(""); setError(""); }} />

    {tab === "Ringkasan" && <div className="grid grid-cols-2 gap-4">
      <IntegrationCard icon={<WalletCards className="size-5" />} title="DOKU Direct API" description="QRIS, Virtual Account, e-wallet, notification, dan halaman pembayaran." ready={profileConfigured("doku", dokuEnvironment)} onClick={() => setTab("DOKU Direct API")} />
      <IntegrationCard icon={<Network className="size-5" />} title="Digiflazz" description="Produk otomatis, sinkron harga, transaksi, webhook, dan relay." ready={profileConfigured("digiflazz", digiflazzEnvironment)} onClick={() => setTab("Digiflazz")} />
      <IntegrationCard icon={<KeyRound className="size-5" />} title="Melostore Nickname" description="Pemeriksaan nickname berdasarkan ID atau ID + Server sebelum checkout." ready={profileConfigured("melostore", "global")} onClick={() => setTab("Melostore Nickname")} />
      <IntegrationCard icon={<Mail className="size-5" />} title="Resend Email" description="Invoice, status pesanan, reset password, dan pengiriman voucher." ready={profileConfigured("resend", "global")} onClick={() => setTab("Resend Email")} />
      <IntegrationCard icon={<Server className="size-5" />} title="Relay Digiflazz" description="VPS ber-IP statis untuk koneksi Digiflazz dan pemeriksaan health." ready={profileConfigured("relay", "global")} onClick={() => setTab("Relay & Keamanan")} />
      <Panel title="URL yang Dipasang di Layanan" description="Salin URL ini ke dashboard masing-masing layanan." className="col-span-2"><div className="grid grid-cols-2 gap-3 p-4"><CopyUrl label="DOKU Notification URL" value={callback("doku", fallbackCallbacks[0].url)} note="Tempel di DOKU" /><CopyUrl label="DOKU Return URL" value={callback("doku-fallback", fallbackCallbacks[1].url)} note="Tempel di DOKU" /><CopyUrl label="Digiflazz Webhook URL" value={callback("digiflazz", fallbackCallbacks[2].url)} note="Tempel di Digiflazz" /><CopyUrl label="Digiflazz Relay Endpoint" value={relayBase} note="Worker → VPS" /></div></Panel>
    </div>}

    {tab === "DOKU Direct API" && <TwoColumn main={<Panel title="Kredensial DOKU Direct API" description="Field rahasia dikosongkan kembali setelah tersimpan dan tidak pernah ditampilkan oleh API." action={<SecretToggle show={showSecrets} onClick={() => setShowSecrets((current) => !current)} />}><div className="grid grid-cols-2 gap-4 p-4">
      <SelectField label="Environment" value={dokuEnvironment} onChange={(value) => { const environment = value as "sandbox" | "production"; setDokuEnvironment(environment); setValue("dokuApiUrl", dokuApiUrlForEnvironment(environment)); }} options={["sandbox", "production"]} />
      <TextField label="Direct API Base URL" value={values.dokuApiUrl || dokuApiUrlForEnvironment(dokuEnvironment)} readOnly />
      <SecretField label="Client ID" value={values.dokuClientId || ""} onChange={(value) => setValue("dokuClientId", value)} placeholder={profileConfigured("doku", dokuEnvironment) ? "Tersimpan — isi hanya untuk mengganti" : "Masukkan Client ID DOKU"} show={showSecrets} />
      <SecretField label="Secret Key" value={values.dokuSecretKey || ""} onChange={(value) => setValue("dokuSecretKey", value)} placeholder={profileConfigured("doku", dokuEnvironment) ? "Tersimpan — isi hanya untuk mengganti" : "Masukkan Secret Key DOKU"} show={showSecrets} />
      <TextField label="Merchant ID / Mall ID" value={values.dokuMerchantId || ""} onChange={(value) => setValue("dokuMerchantId", value)} placeholder="ID merchant QRIS" />
      <TextField label="QRIS Terminal ID" value={values.dokuTerminalId || ""} onChange={(value) => setValue("dokuTerminalId", value)} />
      <TextField label="QRIS Postal Code" value={values.dokuPostalCode || ""} onChange={(value) => setValue("dokuPostalCode", value)} placeholder="5 digit" />
      <SecretField label="Private Key Passphrase" value={values.dokuPrivateKeyPassphrase || ""} onChange={(value) => setValue("dokuPrivateKeyPassphrase", value)} placeholder={profileConfigured("doku", dokuEnvironment) ? "Tersimpan — isi hanya untuk mengganti" : "Passphrase merchant private key"} show={showSecrets} />
      <Field label="RSA Private Key (PKCS#8)" help="Simpan private key merchant. Nilai dienkripsi di backend dan tidak pernah ditampilkan kembali." wide><textarea value={values.dokuPrivateKey || ""} onChange={(event) => setValue("dokuPrivateKey", event.target.value)} className={`${inputClass} h-24 py-2 font-mono`} placeholder={profileConfigured("doku", dokuEnvironment) ? "Tersimpan — isi hanya untuk mengganti" : "-----BEGIN ENCRYPTED PRIVATE KEY-----"} /></Field>
      <Field label="Konfigurasi Virtual Account (JSON)" wide><textarea value={values.dokuVaConfig || ""} onChange={(event) => setValue("dokuVaConfig", event.target.value)} className={`${inputClass} h-20 py-2`} placeholder='{"BCA":{"partnerServiceId":"..."}}' /></Field>
    </div></Panel>} side={<><Panel title="URL DOKU" description="Tempel persis di pengaturan DOKU."><div className="space-y-2 p-4"><CopyUrl label="Notification URL" value={callback("doku", fallbackCallbacks[0].url)} /><CopyUrl label="Redirect / Return URL" value={callback("doku-fallback", fallbackCallbacks[1].url)} /><CopyUrl label="Origin Website" value="https://lfamiliastore.my.id" /></div></Panel><SecurityPanel /></>} />}

    {tab === "Digiflazz" && <TwoColumn main={<Panel title="Kredensial Digiflazz" description="API key mengikuti environment yang dipilih; tidak ada field tumpang tindih." action={<SecretToggle show={showSecrets} onClick={() => setShowSecrets((current) => !current)} />}><div className="grid grid-cols-2 gap-4 p-4">
      <SelectField label="Environment" value={digiflazzEnvironment} onChange={(value) => setDigiflazzEnvironment(value as "development" | "production")} options={["production", "development"]} />
      <TextField label="Username Digiflazz" value={values.digiflazzUsername || ""} onChange={(value) => setValue("digiflazzUsername", value)} />
      <SecretField label={`API Key ${digiflazzEnvironment}`} value={values.digiflazzApiKey || ""} onChange={(value) => setValue("digiflazzApiKey", value)} placeholder={profileConfigured("digiflazz", digiflazzEnvironment) ? "Tersimpan — isi hanya untuk mengganti" : "Masukkan API key"} show={showSecrets} />
      <SecretField label="Webhook Secret" value={values.digiflazzWebhookSecret || ""} onChange={(value) => setValue("digiflazzWebhookSecret", value)} placeholder="Secret validasi webhook" show={showSecrets} />
      <TextField label="Transaction API URL" value={values.digiflazzTransactionApiUrl || ""} onChange={(value) => setValue("digiflazzTransactionApiUrl", value)} />
      <TextField label="Pricelist API URL" value={values.digiflazzPriceListUrl || ""} onChange={(value) => setValue("digiflazzPriceListUrl", value)} />
    </div></Panel>} side={<><Panel title="URL Digiflazz" description="Salin untuk webhook dan relay."><div className="space-y-2 p-4"><CopyUrl label="Webhook / Callback URL" value={callback("digiflazz", fallbackCallbacks[2].url)} note="Tempel di Digiflazz" /><CopyUrl label="Relay Endpoint" value={relayBase} /><CopyUrl label="Website Origin" value="https://lfamiliastore.my.id" /></div></Panel><Panel title="Alur Koneksi" description="Rute backend aktif."><div className="space-y-3 p-4"><Flow number="1" text="Website menerima pesanan" /><Flow number="2" text="Backend memanggil VPS Relay" /><Flow number="3" text="Relay meneruskan ke Digiflazz" /><Flow number="4" text="Webhook memperbarui status" /></div></Panel></>} />}

    {tab === "Melostore Nickname" && <TwoColumn main={<Panel title="API Keys Check Nickname" description="Dipakai backend untuk memvalidasi akun sebelum pesanan dibuat." action={<SecretToggle show={showSecrets} onClick={() => setShowSecrets((current) => !current)} />}><div className="grid grid-cols-2 gap-4 p-4"><TextField label="API URL" value={values.melostoreApiUrl || ""} onChange={(value) => setValue("melostoreApiUrl", value)} placeholder="URL API resmi dari akun Melostore" /><TextField label="Endpoint" value="/api/v1/h2h/check-nickname" readOnly /><SecretField label="API Key" value={values.melostoreApiKey || ""} onChange={(value) => setValue("melostoreApiKey", value)} placeholder={profileConfigured("melostore", "global") ? "Tersimpan — isi hanya untuk mengganti" : "Masukkan API Key"} show={showSecrets} /><SecretField label="Secret Key" value={values.melostoreSecretKey || ""} onChange={(value) => setValue("melostoreSecretKey", value)} placeholder="Masukkan Secret Key" show={showSecrets} /><SecretField label="Nickname API Key Cadangan" value={values.melostoreNicknameApiKey || ""} onChange={(value) => setValue("melostoreNicknameApiKey", value)} placeholder="Opsional" show={showSecrets} /><div className="col-span-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-[8px] leading-4 text-blue-800">Keputusan wajib verifikasi atau boleh langsung checkout dikendalikan backend, bukan toggle panel.</div></div></Panel>} side={<><Panel title="Aturan Backend" description="Tidak dapat dimatikan oleh staff."><div className="space-y-3 p-4"><SecurityLine text="Game didukung: nickname wajib ditemukan" /><SecurityLine text="Game tidak didukung: checkout dilanjutkan" /><SecurityLine text="Nickname browser tidak dipercaya" /><SecurityLine text="Kunci API tidak dikirim ke frontend" /></div></Panel><Panel title="Tes Akun" description="Memanggil endpoint validasi backend yang sama dengan checkout."><div className="space-y-3 p-4"><TextField label="Game code" value={values.nicknameGame || ""} onChange={(value) => setValue("nicknameGame", value)} placeholder="mobile-legends" /><TextField label="ID akun" value={values.nicknameUserId || ""} onChange={(value) => setValue("nicknameUserId", value)} placeholder="123456789" /><TextField label="Server / Zone" value={values.nicknameServer || ""} onChange={(value) => setValue("nicknameServer", value)} placeholder="Opsional" /><button type="button" disabled={busy} onClick={checkNickname} className={`${primaryButtonClass} w-full`}><RefreshCw className="size-3.5" />Cek Nickname</button></div></Panel></>} />}

    {tab === "Resend Email" && <TwoColumn main={<Panel title="Konfigurasi Resend" description="Email transaksi dan pengiriman voucher."><div className="grid grid-cols-2 gap-4 p-4"><SecretField label="Resend API Key" value={values.resendApiKey || ""} onChange={(value) => setValue("resendApiKey", value)} placeholder={profileConfigured("resend", "global") ? "Tersimpan — isi hanya untuk mengganti" : "re_••••••••"} show={showSecrets} /><TextField label="API URL" value={values.resendApiUrl || ""} onChange={(value) => setValue("resendApiUrl", value)} /><TextField label="Nama pengirim" value={values.resendFromName || ""} onChange={(value) => setValue("resendFromName", value)} /><TextField label="Email pengirim" value={values.resendFromEmail || ""} onChange={(value) => setValue("resendFromEmail", value)} placeholder="noreply@lfamiliastore.my.id" /><SelectField label="Channel voucher" value={values.resendDeliveryChannel || "email"} onChange={(value) => setValue("resendDeliveryChannel", value)} options={["email", "website"]} /></div></Panel>} side={<Panel title="Status Backend" description="Konfigurasi email disimpan sebagai service internal."><div className="space-y-3 p-4"><SecurityLine text="API key hanya tersedia di server" /><SecurityLine text="Status pengiriman dicatat backend" /><SecurityLine text="Voucher tidak bocor ke log publik" /></div></Panel>} />}

    {tab === "Relay & Keamanan" && <TwoColumn main={<Panel title="VPS Relay Digiflazz" description="Satu relay khusus Digiflazz, tanpa layanan lain." action={<Status tone={profileConfigured("relay", "global") ? "green" : "amber"}>{profileConfigured("relay", "global") ? "Tersimpan" : "Belum diatur"}</Status>}><div className="grid grid-cols-2 gap-4 p-4"><TextField label="Identitas relay" value="digiflazz-relay@lfamilia.my.id" readOnly /><TextField label="Hostname relay" value="digiflazz-relay.lfamiliastore.my.id" readOnly /><TextField label="Relay URL" value={values.relayOrigin || ""} onChange={(value) => setValue("relayOrigin", value)} /><SecretField label="Relay Token" value={values.relayToken || ""} onChange={(value) => setValue("relayToken", value)} placeholder={profileConfigured("relay", "global") ? "Tersimpan — isi hanya untuk mengganti" : "Sama dengan RELAY_TOKEN di VPS"} show={showSecrets} /><TextField label="Host yang diizinkan" value={values.relayHosts || ""} onChange={(value) => setValue("relayHosts", value)} /><SecretField label="Voucher Encryption Key" value={values.voucherEncryptionKey || ""} onChange={(value) => setValue("voucherEncryptionKey", value)} placeholder={profileConfigured("security", "global") ? "Tersimpan — isi hanya untuk mengganti" : "Minimal 32 karakter"} show={showSecrets} /></div></Panel>} side={<Panel title="URL Relay" description="Endpoint untuk backend dan pemeriksaan health."><div className="space-y-2 p-4"><CopyUrl label="Relay Base URL" value={relayBase} /><CopyUrl label="Health URL" value={`${relayBase.replace(/\/$/, "")}/health`} /><button type="button" disabled={busy} onClick={testCurrent} className={`${buttonClass} w-full`}><ShieldCheck className="size-3.5" />Periksa Konfigurasi Relay</button></div></Panel>} />}
  </div>;
}

function TwoColumn({ main, side }: { main: ReactNode; side: ReactNode }) { return <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4"><div>{main}</div><div className="space-y-4">{side}</div></div>; }
function Notice({ ok = false, text, onClose }: { ok?: boolean; text: string; onClose(): void }) { return <button type="button" onClick={onClose} className={`mb-3 flex w-full items-center gap-2 rounded-md border px-3 py-2 text-left text-[9px] font-semibold ${ok ? "border-emerald-200 bg-emerald-50 text-emerald-700" : "border-red-200 bg-red-50 text-red-700"}`}>{ok ? <CheckCircle2 className="size-3.5" /> : <XCircle className="size-3.5" />}<span className="flex-1">{text}</span></button>; }
function IntegrationCard({ icon, title, description, ready, onClick }: { icon: ReactNode; title: string; description: string; ready: boolean; onClick(): void }) { return <button type="button" onClick={onClick} className="flex min-h-28 items-center gap-4 rounded-lg border border-[#e1e6ed] bg-white p-4 text-left shadow-[0_1px_3px_rgba(15,23,42,0.04)] transition hover:border-[#aac8ef] hover:shadow-md"><span className="grid size-12 shrink-0 place-items-center rounded-lg bg-blue-50 text-[#0769e9]">{icon}</span><span className="min-w-0 flex-1"><span className="flex items-center justify-between"><strong className="text-[13px] text-[#14213a]">{title}</strong><Status tone={ready ? "green" : "amber"}>{ready ? "Tersimpan" : "Belum diisi"}</Status></span><span className="mt-1 block text-[9px] leading-4 text-[#718198]">{description}</span></span></button>; }
function TextField({ label, value, onChange, placeholder, readOnly }: { label: string; value: string; onChange?(value: string): void; placeholder?: string; readOnly?: boolean }) { return <Field label={label}><input className={inputClass} value={value} onChange={(event) => onChange?.(event.target.value)} placeholder={placeholder} readOnly={readOnly} /></Field>; }
function SecretField({ label, value, onChange, placeholder, show }: { label: string; value: string; onChange(value: string): void; placeholder: string; show: boolean }) { return <Field label={label}><div className="relative"><KeyRound className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8a98aa]" /><input type={show ? "text" : "password"} className={`${inputClass} pl-8`} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} autoComplete="new-password" /></div></Field>; }
function SelectField({ label, value, onChange, options }: { label: string; value: string; onChange(value: string): void; options: string[] }) { return <Field label={label}><select className={inputClass} value={value} onChange={(event) => onChange(event.target.value)}>{options.map((option) => <option key={option} value={option}>{option}</option>)}</select></Field>; }
function SecretToggle({ show, onClick }: { show: boolean; onClick(): void }) { return <button type="button" onClick={onClick} className={buttonClass}>{show ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}{show ? "Sembunyikan" : "Lihat Field"}</button>; }
function SecurityPanel() { return <Panel title="Pengamanan" description="Aturan backend aktif."><div className="space-y-3 p-4"><SecurityLine text="Credential terenkripsi di database" /><SecurityLine text="Private key dan passphrase tidak ditampilkan kembali" /><SecurityLine text="Tes koneksi hanya meminta B2B token, tidak membuat transaksi" /><SecurityLine text="Signature callback diverifikasi" /><SecurityLine text="Idempotency mencegah saldo ganda" /></div></Panel>; }
function SecurityLine({ text }: { text: string }) { return <div className="flex items-center gap-2 text-[9px] font-semibold text-[#42516a]"><ShieldCheck className="size-3.5 text-emerald-600" />{text}</div>; }
function Flow({ number, text }: { number: string; text: string }) { return <div className="flex items-center gap-2 text-[9px] text-[#42516a]"><span className="grid size-5 shrink-0 place-items-center rounded-full bg-blue-50 font-extrabold text-[#0769e9]">{number}</span>{text}</div>; }
