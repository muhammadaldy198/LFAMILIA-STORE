"use client";

import { useState, type ReactNode } from "react";
import { CheckCircle2, Eye, EyeOff, KeyRound, Mail, Network, RefreshCw, Save, Server, ShieldCheck, WalletCards } from "lucide-react";
import { CopyUrl, Field, Panel, Status, TabBar, Toggle, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

const tabs = ["Ringkasan", "DOKU Direct API", "Digiflazz", "Melostore Nickname", "Resend Email", "Relay & Keamanan"];

export function AdminIntegrationWorkspace() {
  const [tab, setTab] = useState("Ringkasan");
  const [saved, setSaved] = useState(false);
  const [testName, setTestName] = useState("");
  const [showSecrets, setShowSecrets] = useState(false);
  function save() { setSaved(true); window.setTimeout(() => setSaved(false), 1800); }
  return <div>
    <WorkspaceHeader title="Integrasi" description="Pusat konfigurasi DOKU, Digiflazz, Melostore, email, callback, relay, dan keamanan. Khusus Super Admin." actions={<><button type="button" onClick={() => setTestName(tab)} className={buttonClass}><RefreshCw className="size-3.5" />Tes Koneksi</button><button type="button" onClick={save} className={primaryButtonClass}><Save className="size-3.5" />{saved ? "Tersimpan" : "Simpan"}</button></>} />
    {(saved || testName) && <div className="mb-3 flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] font-semibold text-emerald-700"><CheckCircle2 className="size-3.5" />{saved ? "Perubahan tersimpan di UI sementara." : `Simulasi tes ${testName}. Koneksi asli diaktifkan pada tahap backend.`}</div>}
    <TabBar tabs={tabs} active={tab} onChange={(value) => { setTab(value); setTestName(""); }} />

    {tab === "Ringkasan" && <div className="grid grid-cols-2 gap-4">
      <IntegrationCard icon={<WalletCards className="size-5" />} title="DOKU Direct API" description="QRIS, Virtual Account, e-wallet, notification, dan halaman pembayaran." status="Siap diatur" onClick={() => setTab("DOKU Direct API")} />
      <IntegrationCard icon={<Network className="size-5" />} title="Digiflazz" description="Provider produk otomatis, sinkron harga, transaksi, webhook, dan relay." status="Siap diatur" onClick={() => setTab("Digiflazz")} />
      <IntegrationCard icon={<KeyRound className="size-5" />} title="Melostore Nickname" description="Pemeriksaan nickname berdasarkan ID atau ID + Server sebelum checkout." status="Siap diatur" onClick={() => setTab("Melostore Nickname")} />
      <IntegrationCard icon={<Mail className="size-5" />} title="Resend Email" description="Invoice, status pesanan, reset password, dan pengiriman voucher." status="Belum diisi" onClick={() => setTab("Resend Email")} />
      <IntegrationCard icon={<Server className="size-5" />} title="Relay Digiflazz" description="VPS ber-IP statis untuk koneksi Digiflazz dan pemeriksaan health." status="Relay tersedia" onClick={() => setTab("Relay & Keamanan")} />
      <Panel title="URL yang Dipasang di Provider" description="Salin URL ini ke dashboard masing-masing layanan." className="col-span-2"><div className="grid grid-cols-2 gap-3 p-4"><CopyUrl label="DOKU Notification URL" value="https://lfamiliastore.my.id/api/payments/doku/callback" note="Tempel di DOKU" /><CopyUrl label="DOKU Return URL" value="https://lfamiliastore.my.id/payment" note="Tempel di DOKU" /><CopyUrl label="Digiflazz Webhook URL" value="https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback" note="Tempel di Digiflazz" /><CopyUrl label="Digiflazz Relay Endpoint" value="https://digiflazz-relay.lfamiliastore.my.id" note="Worker → VPS" /></div></Panel>
    </div>}

    {tab === "DOKU Direct API" && <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4">
      <Panel title="Kredensial DOKU Direct API" description="Kredensial nantinya disimpan terenkripsi dan tidak pernah ditampilkan kembali." action={<div className="flex items-center gap-2"><Status tone="green">Production</Status><button type="button" onClick={() => setShowSecrets((value) => !value)} className={buttonClass}>{showSecrets ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}{showSecrets ? "Sembunyikan" : "Lihat Field"}</button></div>}><div className="grid grid-cols-2 gap-4 p-4">
        <Field label="Environment"><select className={inputClass} defaultValue="production"><option value="production">Production</option><option value="sandbox">Sandbox</option></select></Field>
        <Field label="Direct API Base URL"><input className={inputClass} defaultValue="https://api.doku.com" /></Field>
        <SecretField label="Client ID" placeholder="Masukkan Client ID DOKU" show={showSecrets} />
        <SecretField label="Secret Key" placeholder="Masukkan Secret Key DOKU" show={showSecrets} />
        <SecretField label="Merchant ID / Mall ID" placeholder="Masukkan ID merchant" show={showSecrets} />
        <Field label="Chain Store ID"><input className={inputClass} placeholder="Opsional sesuai akun DOKU" /></Field>
        <Field label="QRIS Terminal ID"><input className={inputClass} placeholder="Terminal ID dari DOKU" /></Field>
        <Field label="QRIS Postal Code"><input className={inputClass} inputMode="numeric" maxLength={5} placeholder="Kode pos merchant" /></Field>
        <Field label="RSA Private Key (PKCS#8)" help="Private key merchant. Jangan pernah ditempel ke dashboard publik." wide><textarea className={`${inputClass} h-24 py-2 font-mono`} placeholder="-----BEGIN PRIVATE KEY-----" /></Field>
        <Field label="Konfigurasi Virtual Account" help="Isi konfigurasi hanya untuk BCA, BRI, BNI, dan Mandiri yang sudah aktif." wide><textarea className={`${inputClass} h-20 py-2`} placeholder="Partner Service ID / Customer Number / Virtual Account Number" /></Field>
        <SwitchLine label="Aktifkan DOKU sebagai satu-satunya payment gateway" checked />
        <SwitchLine label="Verifikasi signature callback" checked />
      </div></Panel>
      <div className="space-y-4"><Panel title="URL DOKU" description="Tempel persis di pengaturan DOKU."><div className="space-y-2 p-4"><CopyUrl label="Notification URL" value="https://lfamiliastore.my.id/api/payments/doku/callback" /><CopyUrl label="Redirect / Return URL" value="https://lfamiliastore.my.id/payment" /><CopyUrl label="Origin Website" value="https://lfamiliastore.my.id" /></div></Panel><Panel title="Pengamanan" description="Aturan yang diterapkan saat backend diaktifkan."><div className="space-y-3 p-4"><SecurityLine text="Credential terenkripsi di database" /><SecurityLine text="Private key tidak dikirim ke browser pelanggan" /><SecurityLine text="Signature & timestamp callback diverifikasi" /><SecurityLine text="Idempotency mencegah saldo ganda" /></div></Panel></div>
    </div>}

    {tab === "Digiflazz" && <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4">
      <Panel title="Kredensial Digiflazz" description="Menu ini untuk koneksi; operasional produk tetap berada di menu Digiflazz." action={<Status tone="green">Provider Utama</Status>}><div className="grid grid-cols-2 gap-4 p-4">
        <Field label="Environment"><select className={inputClass} defaultValue="production"><option value="production">Production</option><option value="development">Development</option></select></Field>
        <Field label="Username Digiflazz"><input className={inputClass} placeholder="Username akun Digiflazz" /></Field>
        <SecretField label="API Key Production" placeholder="Masukkan API key production" show={showSecrets} />
        <SecretField label="API Key Development" placeholder="Masukkan API key development" show={showSecrets} />
        <SecretField label="Webhook Secret" placeholder="Secret untuk validasi webhook" show={showSecrets} />
        <Field label="Transaction API URL"><input className={inputClass} defaultValue="https://api.digiflazz.com/v1/transaction" /></Field>
        <Field label="Pricelist API URL"><input className={inputClass} defaultValue="https://api.digiflazz.com/v1/price-list" /></Field>
        <Field label="Relay Digiflazz"><input className={inputClass} defaultValue="https://digiflazz-relay.lfamiliastore.my.id" /></Field>
        <SwitchLine label="Gunakan relay VPS untuk transaksi" checked />
        <SwitchLine label="Sinkron harga melalui relay" checked />
        <SwitchLine label="Verifikasi webhook Digiflazz" checked />
        <SwitchLine label="Mode development untuk uji coba" />
      </div></Panel>
      <div className="space-y-4"><Panel title="URL Digiflazz" description="Salin untuk webhook dan whitelist."><div className="space-y-2 p-4"><CopyUrl label="Webhook / Callback URL" value="https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback" note="Tempel di Digiflazz" /><CopyUrl label="Relay Endpoint" value="https://digiflazz-relay.lfamiliastore.my.id" /><CopyUrl label="Website Origin" value="https://lfamiliastore.my.id" /></div></Panel><Panel title="Alur Koneksi" description="Rute yang akan dipakai backend."><div className="space-y-3 p-4"><Flow number="1" text="Cloudflare Worker menerima pesanan" /><Flow number="2" text="Worker memanggil VPS Relay" /><Flow number="3" text="Relay meneruskan ke Digiflazz" /><Flow number="4" text="Webhook memperbarui status pesanan" /></div></Panel></div>
    </div>}

    {tab === "Melostore Nickname" && <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4">
      <Panel title="API Keys Check Nickname" description="Dipakai backend untuk memvalidasi akun sebelum pesanan dibuat." action={<Status tone="green">Server-side</Status>}><div className="grid grid-cols-2 gap-4 p-4">
        <Field label="API URL"><input className={inputClass} placeholder="Masukkan URL resmi dari akun Melostore" /></Field>
        <Field label="Endpoint"><input className={inputClass} value="/api/v1/h2h/check-nickname" readOnly /></Field>
        <SecretField label="API Key" placeholder="Masukkan API Key Melostore" show={showSecrets} />
        <SecretField label="Secret Key" placeholder="Masukkan Secret Key Melostore" show={showSecrets} />
        <SecretField label="Nickname API Key Cadangan" placeholder="Opsional untuk layanan cadangan" show={showSecrets} />
        <Field label="Timeout"><select className={inputClass} defaultValue="8"><option value="5">5 detik</option><option value="8">8 detik</option><option value="12">12 detik</option></select></Field>
        <div className="col-span-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-[8px] leading-4 text-blue-800">Kredensial hanya dapat diatur di sini. Keputusan wajib verifikasi atau boleh langsung checkout dikendalikan otomatis oleh backend.</div>
      </div></Panel>
      <div className="space-y-4"><Panel title="Aturan Backend" description="Aturan ini wajib dan tidak mengikuti pilihan staff."><div className="space-y-3 p-4"><SecurityLine text="Akun didukung: nickname wajib ditemukan" /><SecurityLine text="Akun tidak didukung: checkout tetap dilanjutkan" /><SecurityLine text="Nickname dari browser tidak pernah dipercaya" /><SecurityLine text="Kunci API tidak dikirim ke frontend" /></div></Panel><Panel title="Tes Akun" description="Uji kredensial sebelum digunakan saat checkout."><div className="space-y-3 p-4"><Field label="Game code"><input className={inputClass} placeholder="mobile-legends" /></Field><Field label="ID akun"><input className={inputClass} placeholder="123456789" /></Field><Field label="Server / Zone"><input className={inputClass} placeholder="Opsional" /></Field><button type="button" onClick={() => setTestName("Melostore Nickname")} className={`${primaryButtonClass} w-full`}><RefreshCw className="size-3.5" />Cek Nickname</button></div></Panel></div>
    </div>}

    {tab === "Resend Email" && <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4"><Panel title="Konfigurasi Resend" description="Email transaksi dan pengiriman voucher."><div className="grid grid-cols-2 gap-4 p-4"><SecretField label="Resend API Key" placeholder="re_••••••••" show={showSecrets} /><Field label="API URL"><input className={inputClass} defaultValue="https://api.resend.com/emails" /></Field><Field label="Nama pengirim"><input className={inputClass} defaultValue="LFAMILIA STORE" /></Field><Field label="Email pengirim"><input className={inputClass} placeholder="noreply@lfamiliastore.my.id" /></Field><Field label="Reply-to"><input className={inputClass} placeholder="support@lfamiliastore.my.id" /></Field><Field label="Channel voucher"><select className={inputClass}><option>Website + Email</option><option>Website saja</option></select></Field><SwitchLine label="Invoice pembayaran" checked /><SwitchLine label="Status pesanan" checked /><SwitchLine label="Pengiriman voucher" checked /><SwitchLine label="Reset password" checked /></div></Panel><Panel title="Tes Pengiriman" description="Kirim email percobaan setelah backend aktif."><div className="space-y-3 p-4"><Field label="Email tujuan"><input className={inputClass} placeholder="alamat@email.com" /></Field><button type="button" className={`${primaryButtonClass} w-full`}><Mail className="size-3.5" />Kirim Email Tes</button><p className="text-[8px] leading-4 text-[#8190a5]">Domain pengirim harus diverifikasi lebih dahulu di dashboard Resend.</p></div></Panel></div>}

    {tab === "Relay & Keamanan" && <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4"><Panel title="VPS Relay Digiflazz" description="Satu relay khusus Digiflazz, tanpa provider lain." action={<Status tone="green">Online</Status>}><div className="grid grid-cols-2 gap-4 p-4"><Field label="Identitas relay"><input className={inputClass} value="digiflazz-relay@lfamilia.my.id" readOnly /></Field><Field label="Hostname relay"><input className={inputClass} value="digiflazz-relay.lfamiliastore.my.id" readOnly /></Field><Field label="Relay URL"><input className={inputClass} defaultValue="https://digiflazz-relay.lfamiliastore.my.id" /></Field><SecretField label="Relay Token" placeholder="Harus sama dengan RELAY_TOKEN di VPS" show={showSecrets} /><Field label="Health endpoint"><input className={inputClass} defaultValue="/health" /></Field><Field label="Timeout"><select className={inputClass}><option>15 detik</option><option>30 detik</option><option>60 detik</option></select></Field><SwitchLine label="Aktifkan relay transaksi" checked /><SwitchLine label="Blok request tanpa token" checked /></div></Panel><div className="space-y-4"><Panel title="URL Relay" description="Endpoint untuk Cloudflare Worker."><div className="space-y-2 p-4"><CopyUrl label="Relay Base URL" value="https://digiflazz-relay.lfamiliastore.my.id" /><CopyUrl label="Health URL" value="https://digiflazz-relay.lfamiliastore.my.id/health" /></div></Panel><Panel title="Kunci Keamanan" description="Dikelola Super Admin."><div className="space-y-3 p-4"><SecretField label="Voucher Encryption Key" placeholder="Minimal 32 karakter" show={showSecrets} /><SecretField label="Integration Encryption Key" placeholder="Root key tetap Cloudflare Secret" show={showSecrets} /><button type="button" className={`${buttonClass} w-full`}><ShieldCheck className="size-3.5" />Periksa Keamanan</button></div></Panel></div></div>}

  </div>;
}

function IntegrationCard({ icon, title, description, status, onClick }: { icon: ReactNode; title: string; description: string; status: string; onClick(): void }) { return <button type="button" onClick={onClick} className="flex min-h-28 items-center gap-4 rounded-lg border border-[#e1e6ed] bg-white p-4 text-left shadow-[0_1px_3px_rgba(15,23,42,0.04)] transition hover:border-[#aac8ef] hover:shadow-md"><span className="grid size-12 shrink-0 place-items-center rounded-lg bg-blue-50 text-[#0769e9]">{icon}</span><span className="min-w-0 flex-1"><span className="flex items-center justify-between"><strong className="text-[13px] text-[#14213a]">{title}</strong><Status tone={status === "Belum diisi" ? "amber" : "green"}>{status}</Status></span><span className="mt-1 block text-[9px] leading-4 text-[#718198]">{description}</span></span></button>; }
function SecretField({ label, placeholder, show }: { label: string; placeholder: string; show: boolean }) { return <Field label={label}><div className="relative"><KeyRound className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8a98aa]" /><input type={show ? "text" : "password"} className={`${inputClass} pl-8`} placeholder={placeholder} /></div></Field>; }
function SwitchLine({ label, checked = false }: { label: string; checked?: boolean }) { const [value, setValue] = useState(checked); return <div className="flex min-h-10 items-center justify-between rounded-md border border-[#e3e8ef] px-3"><span className="text-[9px] font-semibold text-[#42516a]">{label}</span><Toggle checked={value} onChange={setValue} /></div>; }
function SecurityLine({ text }: { text: string }) { return <div className="flex items-center gap-2 text-[9px] font-semibold text-[#42516a]"><ShieldCheck className="size-3.5 text-emerald-600" />{text}</div>; }
function Flow({ number, text }: { number: string; text: string }) { return <div className="flex items-center gap-2 text-[9px] text-[#42516a]"><span className="grid size-5 shrink-0 place-items-center rounded-full bg-blue-50 font-extrabold text-[#0769e9]">{number}</span>{text}</div>; }
