"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  ChevronDown,
  Copy,
  KeyRound,
  LoaderCircle,
  Save,
  ShieldCheck,
  Webhook,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

type Provider = "midtrans" | "ipaymu" | "digiflazz" | "vippayment" | "melostore" | "resend" | "relay" | "security";
type Mode = "snap" | "direct" | "service";
type Environment = "sandbox" | "production" | "development" | "global";

type Field = {
  key: string;
  label: string;
  help?: string;
  secret?: boolean;
  multiline?: boolean;
  inputMode?: "url" | "text";
  placeholder?: string;
};

type Definition = {
  id: string;
  provider: Provider;
  mode: Mode;
  title: string;
  description: string;
  environments: Environment[];
  fields: Field[];
};

type Profile = {
  provider: Provider;
  mode: Mode;
  environment: Environment;
  configured: boolean;
  configuredFields: string[];
  decryptionError: boolean;
  updatedAt: string;
};

type Overview = {
  encryptionReady: boolean;
  encryptionHint: string;
  selections: {
    midtransMode: "snap";
    midtransEnvironment: "sandbox" | "production";
    ipaymuEnvironment: "sandbox" | "production";
    digiflazzEnvironment: "development" | "production";
    vippaymentEnvironment: "sandbox" | "production";
  };
  profiles: Profile[];
  callbacks: Array<{ id: string; label: string; description: string; kind: "notification" | "callback" | "fallback"; url: string }>;
};

type RelayConnectionResult = {
  provider: "digiflazz" | "ipaymu";
  label: string;
  connected: boolean;
  status: number | null;
  message: string;
};

const definitions: Definition[] = [
  {
    id: "midtrans-snap",
    provider: "midtrans",
    mode: "snap",
    title: "Midtrans Snap",
    description: "Gateway cadangan otomatis untuk QRIS, e-wallet, dan virtual account ketika iPaymu tidak dapat digunakan.",
    environments: ["sandbox", "production"],
    fields: [
      { key: "serverKey", label: "Server Key", secret: true, placeholder: "SB-Mid-server-… / Mid-server-…" },
      { key: "clientKey", label: "Client Key", secret: true, placeholder: "SB-Mid-client-… / Mid-client-…" },
      { key: "apiUrl", label: "Snap API URL", inputMode: "url", placeholder: "https://app.sandbox.midtrans.com/snap/v1/transactions" },
      { key: "scriptUrl", label: "Snap Script URL", inputMode: "url", placeholder: "https://app.sandbox.midtrans.com/snap/snap.js" },
    ],
  },
  {
    id: "ipaymu",
    provider: "ipaymu",
    mode: "direct",
    title: "iPaymu",
    description: "Gateway utama LFAMILIA untuk QRIS, e-wallet, dan virtual account dengan callback pembayaran otomatis.",
    environments: ["sandbox", "production"],
    fields: [
      { key: "virtualAccount", label: "Virtual Account (VA)", secret: true },
      { key: "apiKey", label: "API Key", secret: true },
      { key: "apiUrl", label: "API URL", inputMode: "url", placeholder: "https://sandbox.ipaymu.com/api/v2/payment/direct" },
    ],
  },
  {
    id: "digiflazz",
    provider: "digiflazz",
    mode: "direct",
    title: "DigiFlazz",
    description: "Provider produk otomatis, sinkron harga, dan webhook status transaksi.",
    environments: ["development", "production"],
    fields: [
      { key: "username", label: "Username DigiFlazz", secret: true },
      { key: "apiKey", label: "API Key", secret: true },
      { key: "transactionApiUrl", label: "Transaction API URL", inputMode: "url" },
      { key: "priceListUrl", label: "Price List API URL", inputMode: "url" },
      { key: "webhookSecret", label: "Webhook Secret", secret: true },
    ],
  },
  {
    id: "vippayment",
    provider: "vippayment",
    mode: "direct",
    title: "VIPayment",
    description: "Provider produk otomatis tambahan. Setiap nominal dapat memilih provider ini dari menu Produk.",
    environments: ["sandbox", "production"],
    fields: [
      { key: "apiId", label: "API ID", secret: true },
      { key: "apiKey", label: "API Key", secret: true },
      { key: "apiUrl", label: "API URL", inputMode: "url" },
    ],
  },
  {
    id: "melostore",
    provider: "melostore",
    mode: "service",
    title: "Melostore",
    description: "Kredensial nickname checker dan endpoint Melostore.",
    environments: ["global"],
    fields: [
      { key: "apiKey", label: "API Key", secret: true },
      { key: "secretKey", label: "Secret Key", secret: true },
      { key: "apiUrl", label: "API URL", inputMode: "url" },
      { key: "nicknameApiKey", label: "Nickname API Key", secret: true, help: "Opsional jika endpoint nickname memakai key terpisah." },
    ],
  },
  {
    id: "resend",
    provider: "resend",
    mode: "service",
    title: "Resend Email",
    description: "Email transaksi dan pengiriman kode voucher. WhatsApp tidak digunakan lagi.",
    environments: ["global"],
    fields: [
      { key: "apiKey", label: "API Key", secret: true },
      { key: "fromEmail", label: "From Email", placeholder: "LFAMILIA <noreply@domain.tld>" },
      { key: "apiUrl", label: "API URL", inputMode: "url", placeholder: "https://api.resend.com/emails" },
      { key: "deliveryChannel", label: "Pengiriman voucher", placeholder: "website atau email", help: "Gunakan website untuk tampil di akun saja, atau email untuk website + email." },
    ],
  },
  {
    id: "relay",
    provider: "relay",
    mode: "service",
    title: "VPS Relay",
    description: "Daftar hostname relay ber-IP statis dan token autentikasi Worker → VPS.",
    environments: ["global"],
    fields: [
      { key: "digiflazzOrigin", label: "DigiFlazz Relay URL", inputMode: "url", placeholder: "https://digiflazz-relay.lfamiliastore.my.id" },
      { key: "ipaymuOrigin", label: "iPaymu Relay URL", inputMode: "url", placeholder: "https://ipaymu-relay.lfamiliastore.my.id" },
      { key: "token", label: "Relay Token", secret: true, help: "Harus sama persis dengan RELAY_TOKEN pada VPS." },
    ],
  },
  {
    id: "security",
    provider: "security",
    mode: "service",
    title: "Encryption Key",
    description: "Kunci enkripsi stok voucher. Root key Integration Manager tetap Cloudflare Secret.",
    environments: ["global"],
    fields: [
      { key: "voucherEncryptionKey", label: "Voucher Encryption Key", secret: true, help: "Minimal 32 karakter. Jangan diganti setelah stok voucher terenkripsi tersimpan." },
    ],
  },
];

function profileKey(definition: Definition, environment: Environment) {
  return `${definition.provider}:${definition.mode}:${environment}`;
}

function environmentLabel(environment: Environment) {
  if (environment === "global") return "Global";
  return environment === "development" ? "Development" : environment === "sandbox" ? "Sandbox" : "Production";
}

export function AdminIntegrationManager({
  view = "providers",
}: {
  view?: "providers" | "relay";
}) {
  const [overview, setOverview] = useState<Overview | null>(null);
  const [selections, setSelections] = useState<Overview["selections"] | null>(null);
  const [formValues, setFormValues] = useState<Record<string, Record<string, string>>>({});
  const [selectedEnvironment, setSelectedEnvironment] = useState<Record<string, Environment>>({});
  const [openId, setOpenId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<string | null>(null);
  const [relayTesting, setRelayTesting] = useState(false);
  const [relayResults, setRelayResults] = useState<RelayConnectionResult[]>([]);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/integrations", { cache: "no-store" });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Pengaturan integrasi gagal dimuat."));
      const next = data as unknown as Overview;
      setOverview(next);
      setSelections(next.selections);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan integrasi gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const profileMap = useMemo(() => new Map(
    (overview?.profiles ?? []).map((profile) => [`${profile.provider}:${profile.mode}:${profile.environment}`, profile]),
  ), [overview]);

  const visibleDefinitions = useMemo(
    () => definitions.filter((definition) =>
      view === "relay" ? definition.provider === "relay" : definition.provider !== "relay",
    ),
    [view],
  );

  function currentEnvironment(definition: Definition) {
    return selectedEnvironment[definition.id] ?? definition.environments[0];
  }

  function updateField(definition: Definition, environment: Environment, field: string, value: string) {
    const key = profileKey(definition, environment);
    setFormValues((current) => ({
      ...current,
      [key]: { ...(current[key] ?? {}), [field]: value },
    }));
  }

  async function saveProfile(definition: Definition) {
    const environment = currentEnvironment(definition);
    const key = profileKey(definition, environment);
    const values = formValues[key] ?? {};
    if (!Object.values(values).some((value) => value.trim())) {
      setError("Isi minimal satu field untuk menyimpan atau mengganti kredensial.");
      return;
    }
    setSaving(key);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/panel/integrations", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          action: "save_profile",
          provider: definition.provider,
          mode: definition.mode,
          environment,
          values,
        }),
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Kredensial gagal disimpan."));
      const next = data.overview as Overview;
      setOverview(next);
      setSelections(next.selections);
      setFormValues((current) => ({ ...current, [key]: {} }));
      setMessage(`${definition.title} ${environmentLabel(environment)} tersimpan terenkripsi.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Kredensial gagal disimpan.");
    } finally {
      setSaving(null);
    }
  }

  async function saveSelections() {
    if (!selections) return;
    setSaving("selections");
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/panel/integrations", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "save_selections", selections }),
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Environment aktif gagal disimpan."));
      const next = data.overview as Overview;
      setOverview(next);
      setSelections(next.selections);
      setMessage("Mode dan environment aktif berhasil disimpan.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Environment aktif gagal disimpan.");
    } finally {
      setSaving(null);
    }
  }

  async function testRelayConnections() {
    setRelayTesting(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/panel/integrations", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "test_relay" }),
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Tes koneksi relay gagal."));
      const results = Array.isArray(data.relay) ? data.relay as RelayConnectionResult[] : [];
      setRelayResults(results);
      if (results.length && results.every((item) => item.connected)) {
        setMessage("Semua relay terhubung dari Worker LFAMILIA.");
      }
    } catch (reason) {
      setRelayResults([]);
      setError(reason instanceof Error ? reason.message : "Tes koneksi relay gagal.");
    } finally {
      setRelayTesting(false);
    }
  }

  async function copy(value: string, label: string) {
    try {
      await navigator.clipboard.writeText(value);
      setMessage(`${label} disalin.`);
    } catch {
      setError("Tidak bisa menyalin otomatis. Tekan dan salin URL secara manual.");
    }
  }

  if (loading) return <div className="flex min-h-40 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat integrasi…</div>;
  if (!overview || !selections) return <div className="rounded-md border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-100">{error || "Pengaturan integrasi tidak tersedia."}</div>;

  return (
    <div className="space-y-3">
      {message && <div className="rounded-md border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] px-3 py-2 text-xs text-[#d8ff8d]">{message}</div>}
      {error && <div className="rounded-md border border-red-400/20 bg-red-400/[0.05] px-3 py-2 text-xs text-red-100">{error}</div>}

      <div className={overview.encryptionReady ? "rounded-lg border border-[#b9ff35]/18 bg-[#b9ff35]/[0.035] p-3" : "rounded-lg border border-amber-300/25 bg-amber-300/[0.06] p-3"}>
        <div className="flex gap-2.5">
          <ShieldCheck className={overview.encryptionReady ? "mt-0.5 size-4 shrink-0 text-[#d8ff8d]" : "mt-0.5 size-4 shrink-0 text-amber-200"} />
          <div>
            <p className="text-xs font-bold">Integration encryption key</p>
            <p className="mt-0.5 text-[10px] leading-4 text-white/52">{overview.encryptionHint}</p>
          </div>
        </div>
      </div>

      {view === "providers" && <section className="rounded-lg border border-white/[0.08] bg-white/[0.015] p-3">
        <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p className="text-xs font-bold">Mode yang dipakai toko</p>
            <p className="mt-0.5 text-[10px] text-white/35">Pilih mode dan environment credential. Aktivasi checkout/top up hanya dikelola dari menu Pembayaran.</p>
          </div>
          <Button type="button" size="sm" disabled={saving === "selections"} onClick={() => void saveSelections()} className="shrink-0 bg-[#b9ff35] text-[#091006] hover:bg-[#d8ff8d]">
            {saving === "selections" ? <LoaderCircle className="size-3.5 animate-spin" /> : <Save className="size-3.5" />}Simpan pilihan
          </Button>
        </div>
        <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
          <SelectField label="Environment Midtrans" value={selections.midtransEnvironment} onChange={(value) => setSelections((current) => current ? { ...current, midtransEnvironment: value as "sandbox" | "production" } : current)} options={[['sandbox', 'Sandbox'], ['production', 'Production']]} />
          <SelectField label="Environment iPaymu" value={selections.ipaymuEnvironment} onChange={(value) => setSelections((current) => current ? { ...current, ipaymuEnvironment: value as "sandbox" | "production" } : current)} options={[['sandbox', 'Sandbox'], ['production', 'Production']]} />
          <SelectField label="Environment DigiFlazz" value={selections.digiflazzEnvironment} onChange={(value) => setSelections((current) => current ? { ...current, digiflazzEnvironment: value as "development" | "production" } : current)} options={[['development', 'Development'], ['production', 'Production']]} />
          <SelectField label="Environment VIPayment" value={selections.vippaymentEnvironment} onChange={(value) => setSelections((current) => current ? { ...current, vippaymentEnvironment: value as "sandbox" | "production" } : current)} options={[['sandbox', 'Sandbox'], ['production', 'Production']]} />
        </div>
      </section>}

      {view === "relay" && (
        <>
          <div className="rounded-lg border border-sky-300/20 bg-sky-300/[0.045] p-3 text-[10px] leading-4 text-white/52">
            Relay menghubungkan Worker LFAMILIA ke VPS ber-IP statis. Isi URL DigiFlazz, iPaymu, dan Relay Token di sini. Semua disimpan terenkripsi di D1; tidak perlu membuat PROVIDER_RELAY_* di Cloudflare.
          </div>
          <section className="rounded-lg border border-white/[0.08] bg-[#0d1019] p-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="text-xs font-bold">Tes routing relay</p>
                <p className="mt-0.5 text-[9px] text-white/35">Memeriksa Worker → VPS, hostname, token, dan status upstream tanpa membuat transaksi provider.</p>
              </div>
              <Button
                type="button"
                size="sm"
                disabled={relayTesting}
                onClick={() => void testRelayConnections()}
                className="shrink-0 bg-[#b9ff35] text-[#091006] hover:bg-[#d8ff8d]"
              >
                {relayTesting ? <LoaderCircle className="size-3.5 animate-spin" /> : <ShieldCheck className="size-3.5" />}
                {relayTesting ? "Menguji..." : "Tes Koneksi Relay"}
              </Button>
            </div>
            <div className="mt-3 grid gap-2 sm:grid-cols-2">
              {(["DigiFlazz", "iPaymu"] as const).map((label) => {
                const result = relayResults.find((item) => item.label === label);
                return (
                  <div key={label} className="rounded-md border border-white/[0.07] bg-white/[0.018] p-2.5">
                    <div className="flex items-center justify-between gap-2">
                      <strong className="text-[10px]">{label}</strong>
                      <span className={result?.connected
                        ? "rounded bg-[#b9ff35]/10 px-1.5 py-0.5 text-[8px] font-black text-[#d8ff8d]"
                        : result
                          ? "rounded bg-red-400/10 px-1.5 py-0.5 text-[8px] font-black text-red-200"
                          : "rounded bg-white/[0.05] px-1.5 py-0.5 text-[8px] font-black text-white/30"}>
                        {result?.connected ? "Connected" : result ? "Error" : "Belum dites"}
                      </span>
                    </div>
                    <p className="mt-1 text-[9px] leading-4 text-white/38">{result?.message || "Tekan Tes Koneksi Relay."}</p>
                  </div>
                );
              })}
            </div>
          </section>
        </>
      )}

      <div className="space-y-2">
        {visibleDefinitions.map((definition) => {
          const environment = currentEnvironment(definition);
          const key = profileKey(definition, environment);
          const saved = profileMap.get(key);
          const savedFields = saved?.configuredFields ?? [];
          const isOpen = openId === definition.id;
          const isActive =
            (definition.provider === "midtrans" && selections.midtransMode === definition.mode && selections.midtransEnvironment === environment) ||
            (definition.provider === "ipaymu" && selections.ipaymuEnvironment === environment) ||
            (definition.provider === "digiflazz" && selections.digiflazzEnvironment === environment) ||
            (definition.provider === "vippayment" && selections.vippaymentEnvironment === environment) ||
            ((definition.provider === "melostore" || definition.provider === "resend" || definition.provider === "relay" || definition.provider === "security") && Boolean(saved?.configured));
          return (
            <section key={definition.id} className="overflow-hidden rounded-lg border border-white/[0.08] bg-[#0d1019]">
              <div className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
                <button type="button" onClick={() => setOpenId(isOpen ? null : definition.id)} className="flex min-w-0 flex-1 items-center gap-2 text-left">
                  <span className="grid size-7 shrink-0 place-items-center rounded-md bg-white/[0.055] text-[#d8ff8d]"><KeyRound className="size-3.5" /></span>
                  <span className="min-w-0">
                    <span className="flex items-center gap-1.5"><strong className="text-xs">{definition.title}</strong>{isActive && <span className="rounded bg-[#b9ff35]/10 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-[0.08em] text-[#d8ff8d]">aktif</span>}</span>
                    <span className="mt-0.5 block text-[9px] leading-4 text-white/35">{definition.description}</span>
                  </span>
                  <ChevronDown className={"ml-auto size-4 shrink-0 text-white/40 transition " + (isOpen ? "rotate-180" : "")} />
                </button>
                <div className="flex items-center gap-2 self-end sm:self-auto">
                  <select value={environment} onChange={(event) => setSelectedEnvironment((current) => ({ ...current, [definition.id]: event.target.value as Environment }))} className="admin-select h-8 min-w-28 text-[10px]">
                    {definition.environments.map((item) => <option key={item} value={item}>{environmentLabel(item)}</option>)}
                  </select>
                  <span className={saved?.decryptionError ? "text-[9px] font-bold text-red-300" : saved?.configured ? "inline-flex items-center gap-1 text-[9px] font-bold text-[#d8ff8d]" : "text-[9px] text-white/30"}>{saved?.decryptionError ? "Kunci enkripsi tidak cocok" : saved?.configured ? <><CheckCircle2 className="size-3" />Tersimpan</> : "Belum diisi"}</span>
                </div>
              </div>
              {isOpen && <div className="border-t border-white/[0.08] bg-white/[0.012] p-3">
                {savedFields.length ? <p className="mb-3 text-[9px] text-white/38">Field tersimpan: {savedFields.map((field) => definition.fields.find((item) => item.key === field)?.label || field).join(", ")}. Isi field kosong hanya jika ingin menggantinya.</p> : <p className="mb-3 text-[9px] text-white/38">Isi data dari dashboard provider untuk environment {environmentLabel(environment)}. Nilai yang disimpan tidak akan ditampilkan lagi.</p>}
                <div className="grid gap-2 sm:grid-cols-2">
                  {definition.fields.map((field) => <CredentialField key={field.key} field={field} value={formValues[key]?.[field.key] ?? ""} onChange={(value) => updateField(definition, environment, field.key, value)} />)}
                </div>
                <div className="mt-3 flex items-center justify-between gap-3 border-t border-white/[0.07] pt-3">
                  <p className="text-[9px] leading-4 text-white/34">{definition.id === "security" ? "Root INTEGRATION_ENCRYPTION_KEY tetap Cloudflare Secret; kunci voucher di sini disimpan terenkripsi." : "Nilai kosong tidak menimpa kredensial yang sudah tersimpan."}</p>
                  <Button type="button" size="sm" disabled={!overview.encryptionReady || saving === key} onClick={() => void saveProfile(definition)} className="shrink-0 bg-[#b9ff35] text-[#091006] hover:bg-[#d8ff8d]">
                    {saving === key ? <LoaderCircle className="size-3.5 animate-spin" /> : <Save className="size-3.5" />}Simpan
                  </Button>
                </div>
              </div>}
            </section>
          );
        })}
      </div>

      <section className="rounded-lg border border-white/[0.08] bg-[#0d1019] p-3">
        <div className="flex items-center gap-2"><Webhook className="size-3.5 text-[#d8ff8d]" /><div><p className="text-xs font-bold">Callback / Notification / Fallback URL</p><p className="text-[9px] text-white/35">Salin URL sesuai jenis yang diminta dashboard provider.</p></div></div>
        <div className="mt-3 space-y-2">
          {overview.callbacks.map((callback) => <div key={callback.id} className="flex flex-col gap-2 rounded-md border border-white/[0.07] bg-white/[0.018] p-2.5 sm:flex-row sm:items-center">
            <div className="min-w-0 flex-1"><div className="flex items-center gap-1.5"><p className="text-[10px] font-bold">{callback.label}</p><span className="rounded bg-white/[0.06] px-1.5 py-0.5 text-[7px] font-black uppercase tracking-wide text-white/40">{callback.kind}</span></div><p className="mt-0.5 text-[8px] text-white/34">{callback.description}</p><code className="mt-1 block break-all text-[9px] text-[#d8ff8d]">{callback.url}</code></div>
            <Button type="button" size="sm" variant="outline" onClick={() => void copy(callback.url, callback.label)} className="border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white"><Copy className="size-3.5" />Salin</Button>
          </div>)}
        </div>
      </section>
    </div>
  );
}

function SelectField({ label, value, onChange, options }: { label: string; value: string; onChange(value: string): void; options: Array<[string, string]> }) {
  return <label><span className="field-label">{label}</span><select value={value} onChange={(event) => onChange(event.target.value)} className="admin-select h-9 w-full text-[10px]">{options.map(([item, text]) => <option key={item} value={item}>{text}</option>)}</select></label>;
}

function CredentialField({ field, value, onChange }: { field: Field; value: string; onChange(value: string): void }) {
  return <label className={field.multiline ? "sm:col-span-2" : ""}>
    <span className="field-label">{field.label}{field.secret && <span className="ml-1 text-[#d8ff8d]">rahasia</span>}</span>
    {field.multiline ? <textarea value={value} onChange={(event) => onChange(event.target.value)} placeholder={field.placeholder || "Tersimpan — isi untuk mengganti"} className="admin-input min-h-24 resize-y py-2 font-mono text-[10px]" spellCheck={false} /> : <Input value={value} onChange={(event) => onChange(event.target.value)} type={field.secret ? "password" : "text"} inputMode={field.inputMode} placeholder={field.placeholder || (field.secret ? "Tersimpan — isi untuk mengganti" : "Isi nilai provider")} className="admin-input h-9 text-[10px]" autoComplete="off" />}
    {field.help && <span className="mt-1 block text-[8px] text-white/30">{field.help}</span>}
  </label>;
}

async function readJson(response: Response): Promise<Record<string, unknown>> {
  const raw = await response.text();
  try { return raw ? JSON.parse(raw) as Record<string, unknown> : { error: "Respons server kosong." }; }
  catch { return { error: "Respons server tidak valid." }; }
}
