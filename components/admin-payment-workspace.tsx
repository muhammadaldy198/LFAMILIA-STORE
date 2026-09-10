"use client";

import { useCallback, useEffect, useState, type ChangeEvent } from "react";
import { AlertTriangle, CheckCircle2, CreditCard, Eye, ImagePlus, Landmark, QrCode, Receipt, RefreshCw, Save, Search, Smartphone, Wallet } from "lucide-react";
import { CopyUrl, Field, MetricCard, Modal, Panel, Status, TabBar, Toggle, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";
import { defaultPaymentPageSettings, type PaymentPageSettings } from "@/lib/payment-page-settings";

type Channel = { dbId: number | null; id: string; method: "va" | "ewallet" | "qris"; name: string; description: string; imageUrl: string; group: string; fee: string; settlement: string; enabled: boolean; sortOrder: number; tone: "green" | "amber" | "gray" };
type PaymentOrder = { id: string; reference_id: string; buyer_name: string; payment_channel: string; payment_method: string; payment_status: string; total: number; created_at: string; product_name: string; package_label: string };

const initialChannels: Channel[] = [
  { dbId: null, id: "qris", method: "qris", name: "QRIS DOKU", description: "Pembayaran QRIS", imageUrl: "", group: "QRIS", fee: "0,7%", settlement: "T+1", enabled: false, sortOrder: 0, tone: "green" },
  { dbId: null, id: "bca", method: "va", name: "Virtual Account BCA", description: "Virtual Account BCA", imageUrl: "", group: "VA Bank", fee: "Rp 4.000", settlement: "Realtime", enabled: false, sortOrder: 1, tone: "green" },
  { dbId: null, id: "bri", method: "va", name: "Virtual Account BRI", description: "Virtual Account BRI", imageUrl: "", group: "VA Bank", fee: "Rp 4.000", settlement: "Realtime", enabled: false, sortOrder: 2, tone: "green" },
  { dbId: null, id: "bni", method: "va", name: "Virtual Account BNI", description: "Virtual Account BNI", imageUrl: "", group: "VA Bank", fee: "Rp 4.000", settlement: "Realtime", enabled: false, sortOrder: 3, tone: "green" },
  { dbId: null, id: "mandiri", method: "va", name: "Virtual Account Mandiri", description: "Virtual Account Mandiri", imageUrl: "", group: "VA Bank", fee: "Rp 4.000", settlement: "Realtime", enabled: false, sortOrder: 4, tone: "green" },
  { dbId: null, id: "gopay", method: "ewallet", name: "GoPay", description: "GoPay", imageUrl: "", group: "E-Wallet", fee: "Sesuai DOKU", settlement: "Belum aktif", enabled: false, sortOrder: 5, tone: "amber" },
  { dbId: null, id: "ovo", method: "ewallet", name: "OVO", description: "OVO", imageUrl: "", group: "E-Wallet", fee: "Sesuai DOKU", settlement: "Belum aktif", enabled: false, sortOrder: 6, tone: "amber" },
];

export function AdminPaymentWorkspace() {
  const [tab, setTab] = useState("Channel Pembayaran");
  const [channels, setChannels] = useState(initialChannels);
  const [editChannel, setEditChannel] = useState<Channel | null>(null);
  const [channelFile, setChannelFile] = useState<File | null>(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [dokuReady, setDokuReady] = useState(false);
  const [walletSettings, setWalletSettings] = useState({ minTopup: 10_000, dokuTopupEnabled: true, dokuCheckoutEnabled: true });
  const [pageSettings, setPageSettings] = useState<PaymentPageSettings>(defaultPaymentPageSettings);
  const [heroImage, setHeroImage] = useState("");
  const [heroFile, setHeroFile] = useState<File | null>(null);
  const [transactions, setTransactions] = useState<PaymentOrder[]>([]);
  const [transactionQuery, setTransactionQuery] = useState("");
  const [selectedTransaction, setSelectedTransaction] = useState<PaymentOrder | null>(null);

  function readImage(event: ChangeEvent<HTMLInputElement>, setter: (value: string) => void) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { setError("Ukuran gambar maksimal 2MB."); return; }
    setHeroFile(file);
    const reader = new FileReader();
    reader.onload = () => setter(String(reader.result || ""));
    reader.readAsDataURL(file);
  }

  const load = useCallback(async () => {
    const [channelResponse, pageResponse, walletResponse, orderResponse] = await Promise.all([
      fetch("/api/panel/payment-methods", { cache: "no-store" }),
      fetch("/api/panel/payment-page", { cache: "no-store" }),
      fetch("/api/panel/wallet", { cache: "no-store" }),
      fetch("/api/panel/orders", { cache: "no-store" }),
    ]);
    const [channelPayload, pagePayload, walletPayload, orderPayload] = await Promise.all([channelResponse.json(), pageResponse.json(), walletResponse.json(), orderResponse.json()]) as [
      { error?: string; channels?: Array<{ id: number | null; method: "va" | "ewallet" | "qris"; channel: string; name: string; description: string; imageUrl?: string; isActive: boolean; sortOrder: number }> },
      { error?: string; settings?: PaymentPageSettings },
      { error?: string; settings?: typeof walletSettings; gatewayReadiness?: { doku?: { ready?: boolean; reason?: string | null } } },
      { error?: string; orders?: PaymentOrder[] },
    ];
    if (!channelResponse.ok) throw new Error(channelPayload.error || "Channel pembayaran gagal dimuat.");
    if (!pageResponse.ok) throw new Error(pagePayload.error || "Halaman pembayaran gagal dimuat.");
    if (!walletResponse.ok) throw new Error(walletPayload.error || "Pengaturan checkout gagal dimuat.");
    if (!orderResponse.ok) throw new Error(orderPayload.error || "Transaksi gagal dimuat.");
    if (channelPayload.channels) setChannels(channelPayload.channels.map((item) => ({ dbId: item.id, id: item.channel, method: item.method, name: item.name, description: item.description, imageUrl: item.imageUrl || "", group: item.method === "qris" ? "QRIS" : item.method === "va" ? "VA Bank" : "E-Wallet", fee: "Sesuai kontrak", settlement: item.isActive ? "Aktif" : "Belum aktif", enabled: item.isActive, sortOrder: item.sortOrder, tone: item.isActive ? "green" : "amber" })));
    if (pagePayload.settings) { setPageSettings(pagePayload.settings); setHeroImage(pagePayload.settings.headerImageUrl); }
    if (walletPayload.settings) setWalletSettings(walletPayload.settings);
    setDokuReady(Boolean(walletPayload.gatewayReadiness?.doku?.ready));
    setTransactions(orderPayload.orders || []);
    return walletPayload.gatewayReadiness?.doku;
  }, []);

  useEffect(() => { load().catch((reason) => setError(reason instanceof Error ? reason.message : "Data pembayaran gagal dimuat.")); }, [load]);

  async function request(url: string, init: RequestInit) {
    const response = await fetch(url, init);
    const payload = await response.json().catch(() => ({})) as { error?: string; url?: string };
    if (!response.ok) throw new Error(payload.error || "Perubahan pembayaran gagal disimpan.");
    return payload;
  }

  async function saveChannel(channel: Channel) {
    await request("/api/panel/payment-methods", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: channel.dbId, method: channel.method, channel: channel.id, name: channel.name, description: channel.description, imageUrl: channel.imageUrl, isActive: channel.enabled, sortOrder: channel.sortOrder }) });
  }

  async function saveEditedChannel() {
    if (!editChannel) return;
    setBusy(true); setError(""); setMessage("");
    try {
      let imageUrl = editChannel.imageUrl;
      if (channelFile) {
        const form = new FormData(); form.set("file", channelFile);
        const uploaded = await request("/api/panel/media", { method: "POST", body: form });
        imageUrl = uploaded.url || "";
      }
      await saveChannel({ ...editChannel, imageUrl });
      await load();
      setEditChannel(null); setChannelFile(null);
      setMessage(`${editChannel.name} berhasil disimpan.`);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Channel gagal disimpan."); }
    finally { setBusy(false); }
  }

  async function save() {
    setMessage(""); setError(""); setBusy(true);
    try {
      if (tab === "Channel Pembayaran") {
        await Promise.all(channels.map(saveChannel));
        await request("/api/panel/wallet", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(walletSettings) });
      } else if (tab === "Tampilan Halaman") {
        let headerImageUrl = pageSettings.headerImageUrl;
        if (heroFile) {
          const form = new FormData(); form.set("file", heroFile);
          const uploaded = await request("/api/panel/media", { method: "POST", body: form });
          headerImageUrl = uploaded.url || "";
        }
        const next = { ...pageSettings, headerImageUrl };
        await request("/api/panel/payment-page", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(next) });
        setPageSettings(next); setHeroImage(headerImageUrl); setHeroFile(null);
      } else {
        setMessage("Tidak ada pengaturan pada daftar transaksi.");
        return;
      }
      await load();
      setMessage(`${tab} berhasil disimpan ke backend.`);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Perubahan pembayaran gagal disimpan."); }
    finally { setBusy(false); }
  }

  async function testConnection() {
    setMessage(""); setError(""); setBusy(true);
    try {
      const readiness = await load();
      if (!readiness?.ready) throw new Error(readiness?.reason || "Konfigurasi DOKU Direct API belum lengkap.");
      setMessage("Kredensial DOKU Direct API terbaca dan kunci tanda tangan valid.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Pemeriksaan DOKU gagal."); }
    finally { setBusy(false); }
  }

  const paymentOrders = transactions.filter((order) => order.payment_method !== "wallet");
  const shownTransactions = paymentOrders.filter((order) => `${order.reference_id} ${order.buyer_name} ${order.product_name}`.toLowerCase().includes(transactionQuery.toLowerCase()));
  const successful = paymentOrders.filter((order) => order.payment_status === "paid");
  const failed = paymentOrders.filter((order) => ["failed", "expired", "cancelled"].includes(order.payment_status));

  return <div>
    <WorkspaceHeader title="Pembayaran" description="Kelola DOKU Direct API, channel pembayaran, transaksi, dan tampilan halaman bayar LFAMILIA." actions={<><button type="button" disabled={busy} onClick={testConnection} className={buttonClass}><RefreshCw className={`size-3.5 ${busy ? "animate-spin" : ""}`} />Tes Koneksi</button><button type="button" disabled={busy} onClick={save} className={primaryButtonClass}><Save className="size-3.5" />{busy ? "Memproses..." : "Simpan Perubahan"}</button></>} />

    {message && <button type="button" onClick={() => setMessage("")} className="mb-3 flex w-full items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-left text-[9px] font-semibold text-emerald-700"><CheckCircle2 className="size-3.5" />{message}</button>}
    {error && <button type="button" onClick={() => setError("")} className="mb-3 flex w-full items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-left text-[9px] font-semibold text-red-700"><AlertTriangle className="size-3.5" />{error}</button>}

    <div className="mb-4 grid grid-cols-5 gap-3">
      <MetricCard icon={Wallet} label="Total Pembayaran" value={String(paymentOrders.length)} detail="Data transaksi tersimpan" tone="green" />
      <MetricCard icon={CreditCard} label="Nilai Transaksi" value={formatMoney(successful.reduce((sum, order) => sum + Number(order.total || 0), 0))} detail="Pembayaran berhasil" />
      <MetricCard icon={Receipt} label="Menunggu" value={String(paymentOrders.filter((order) => order.payment_status === "pending").length)} detail="Perlu dipantau" tone="amber" />
      <MetricCard icon={CheckCircle2} label="Berhasil" value={String(successful.length)} detail={`${paymentOrders.length ? Math.round(successful.length / paymentOrders.length * 100) : 0}% success rate`} tone="green" />
      <MetricCard icon={AlertTriangle} label="Gagal / Expired" value={String(failed.length)} detail="Perlu diperiksa" tone="red" />
    </div>

    <TabBar tabs={["Channel Pembayaran", "Tampilan Halaman", "Transaksi DOKU"]} active={tab} onChange={setTab} />

    {tab === "Channel Pembayaran" && <div className="grid grid-cols-[minmax(0,1fr)_330px] gap-4">
      <Panel title="Channel DOKU" description="Hanya channel yang aktif di akun DOKU yang boleh dinyalakan." action={<Status tone={dokuReady ? "green" : "amber"}>{dokuReady ? "DOKU Siap" : "Konfigurasi Belum Lengkap"}</Status>}>
        <table className="w-full text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr><th className="px-4 py-2.5">Channel</th><th>Jenis</th><th>Biaya</th><th>Settlement</th><th>Status</th><th className="pr-4 text-right">Aksi</th></tr></thead>
          <tbody className="divide-y divide-[#edf0f4]">{channels.map((channel) => <tr key={channel.id} className="text-[9px] text-[#42516a]"><td className="px-4 py-2.5"><div className="flex items-center gap-2"><span className="grid size-7 place-items-center rounded bg-blue-50 text-[#0769e9]">{channel.group === "QRIS" ? <QrCode className="size-3.5" /> : channel.group === "VA Bank" ? <Landmark className="size-3.5" /> : <Smartphone className="size-3.5" />}</span><strong className="text-[#23334e]">{channel.name}</strong></div></td><td>{channel.group}</td><td>{channel.fee}</td><td>{channel.settlement}</td><td><Toggle checked={channel.enabled} onChange={(checked) => setChannels((current) => current.map((item) => item.id === channel.id ? { ...item, enabled: checked } : item))} /></td><td className="pr-4 text-right"><button type="button" onClick={() => setEditChannel(channel)} className={buttonClass}>Edit</button></td></tr>)}</tbody>
        </table>
      </Panel>
      <div className="space-y-4">
        <Panel title="Pengaturan Checkout" description="Berlaku untuk transaksi toko dan top up saldo."><div className="grid gap-3 p-4">
          <Field label="Gateway utama"><input className={inputClass} value="DOKU Direct API" readOnly /></Field>
          <Field label="Minimum top up saldo"><input className={inputClass} inputMode="numeric" value={walletSettings.minTopup} onChange={(event) => setWalletSettings((current) => ({ ...current, minTopup: Number(event.target.value.replace(/\D/g, "")) || 0 }))} /></Field>
          <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><div><strong className="block text-[9px] text-[#34445f]">Pembayaran otomatis</strong><span className="text-[8px] text-[#8a98aa]">DOKU sebagai satu-satunya jalur eksternal</span></div><Toggle checked={walletSettings.dokuCheckoutEnabled} onChange={(value) => setWalletSettings((current) => ({ ...current, dokuCheckoutEnabled: value }))} /></div>
          <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><div><strong className="block text-[9px] text-[#34445f]">Top up wallet</strong><span className="text-[8px] text-[#8a98aa]">Dibayar otomatis melalui DOKU</span></div><Toggle checked={walletSettings.dokuTopupEnabled} onChange={(value) => setWalletSettings((current) => ({ ...current, dokuTopupEnabled: value }))} /></div>
        </div></Panel>
        <Panel title="URL DOKU" description="URL publik untuk konfigurasi gateway."><div className="space-y-2 p-4"><CopyUrl label="Notification URL" value="https://lfamiliastore.my.id/api/payments/doku/callback" /><CopyUrl label="Return URL" value="https://lfamiliastore.my.id/payment" /></div></Panel>
      </div>
    </div>}

    {tab === "Tampilan Halaman" && <div className="grid grid-cols-[minmax(0,1fr)_410px] gap-4">
      <Panel title="Editor Halaman Pembayaran" description="Ubah gambar, teks, warna, instruksi, dan elemen yang dilihat pelanggan."><div className="grid grid-cols-2 gap-4 p-4">
        <Field label="Judul halaman"><input className={inputClass} value={pageSettings.pendingTitle} onChange={(event) => setPageSettings((current) => ({ ...current, pendingTitle: event.target.value }))} /></Field>
        <Field label="Warna utama"><div className="flex gap-2"><input type="color" value={pageSettings.accentColor} onChange={(event) => setPageSettings((current) => ({ ...current, accentColor: event.target.value }))} className="h-9 w-12 rounded border border-[#dfe5ed] bg-white p-1" /><input className={inputClass} value={pageSettings.accentColor} onChange={(event) => setPageSettings((current) => ({ ...current, accentColor: event.target.value }))} /></div></Field>
        <Field label="Instruksi pembayaran" wide><textarea value={pageSettings.subtitle} onChange={(event) => setPageSettings((current) => ({ ...current, subtitle: event.target.value }))} className={`${inputClass} h-20 py-2`} /></Field>
        <ImageEditor label="Gambar / Banner pembayaran" value={heroImage} onChange={(event) => readImage(event, setHeroImage)} onClear={() => { setHeroImage(""); setHeroFile(null); setPageSettings((current) => ({ ...current, headerImageUrl: "" })); }} wide />
        <div className="col-span-2 grid grid-cols-2 gap-3"><SwitchRow label="Tampilkan ringkasan pesanan" checked={pageSettings.showOrderSummary} onChange={(value) => setPageSettings((current) => ({ ...current, showOrderSummary: value }))} /><SwitchRow label="Tampilkan pemberitahuan invoice" checked={pageSettings.showInvoiceNotice} onChange={(value) => setPageSettings((current) => ({ ...current, showInvoiceNotice: value }))} /><SwitchRow label="Tampilkan status pembayaran" checked={pageSettings.showStatusBox} onChange={(value) => setPageSettings((current) => ({ ...current, showStatusBox: value }))} /><SwitchRow label="Tampilkan bantuan" checked={pageSettings.showSupport} onChange={(value) => setPageSettings((current) => ({ ...current, showSupport: value }))} /></div>
      </div></Panel>
      <Panel title="Preview Halaman Pembayaran" description="Preview desktop pelanggan."><div className="bg-[#f4f7fb] p-5"><div className="overflow-hidden rounded-xl border border-[#e1e6ed] bg-white shadow-sm">{heroImage ? <img src={heroImage} alt="Preview banner pembayaran" className="h-28 w-full object-cover" /> : <div className="grid h-28 place-items-center bg-gradient-to-r from-[#0d2b57] to-[#0769e9] text-[12px] font-black text-white">LFAMILIA PAYMENT</div>}<div className="p-4"><div className="flex items-center gap-2"><span className="grid size-9 place-items-center rounded bg-blue-50 text-[#0769e9]"><CreditCard className="size-4" /></span><div><strong className="block text-[13px] text-[#14213a]">{pageSettings.pendingTitle}</strong><p className="mt-0.5 text-[8px] text-[#8190a5]">{pageSettings.subtitle}</p></div></div><div className="mt-4 rounded-lg border border-[#e3e8ef] p-3"><div className="flex justify-between text-[9px]"><span>Mobile Legends 86 Diamonds</span><strong>Rp 20.000</strong></div><div className="mt-3 flex items-center justify-between rounded-md bg-[#f6f8fb] p-3"><div className="flex items-center gap-2"><QrCode className="size-4" style={{ color: pageSettings.accentColor }} /><span className="text-[9px] font-bold">QRIS DOKU</span></div><Status tone="green">Dipilih</Status></div><button type="button" className="mt-3 h-9 w-full rounded-md text-[10px] font-extrabold text-white" style={{ backgroundColor: pageSettings.accentColor }}>{pageSettings.payButtonText}</button></div></div></div></div></Panel>
    </div>}

    {tab === "Transaksi DOKU" && <Panel title="Transaksi DOKU Terbaru" description="Riwayat pembayaran yang tersimpan di database." action={<div className="relative"><Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8190a5]" /><input value={transactionQuery} onChange={(event) => setTransactionQuery(event.target.value)} className={`${inputClass} w-56 pl-8`} placeholder="Cari invoice atau pelanggan..." /></div>}><table className="w-full text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr>{["Invoice", "Pelanggan", "Channel", "Total", "Status", "Waktu", "Aksi"].map((head) => <th key={head} className="px-4 py-2.5">{head}</th>)}</tr></thead><tbody className="divide-y divide-[#edf0f4]">{shownTransactions.map((order) => <tr key={order.id} className="text-[9px] text-[#42516a]"><td className="px-4 py-2.5"><strong className="text-[#0769e9]">{order.reference_id}</strong></td><td className="px-4">{order.buyer_name}</td><td className="px-4">{order.payment_channel}</td><td className="px-4">{formatMoney(order.total)}</td><td className="px-4"><Status tone={order.payment_status === "paid" ? "green" : order.payment_status === "pending" ? "amber" : "red"}>{order.payment_status}</Status></td><td className="px-4">{formatDate(order.created_at)}</td><td className="px-4"><button type="button" onClick={() => setSelectedTransaction(order)} className={buttonClass}><Eye className="size-3.5" />Detail</button></td></tr>)}</tbody></table></Panel>}

    <Modal open={Boolean(editChannel)} title={`Edit ${editChannel?.name || "Channel"}`} description="Pengaturan tampilan channel DOKU di checkout." onClose={() => { setEditChannel(null); setChannelFile(null); }} footer={<><button type="button" className={buttonClass} onClick={() => { setEditChannel(null); setChannelFile(null); }}>Batal</button><button type="button" disabled={busy} className={primaryButtonClass} onClick={saveEditedChannel}>{busy ? "Menyimpan..." : "Simpan Channel"}</button></>}><div className="grid grid-cols-2 gap-4"><Field label="Nama tampilan"><input className={inputClass} value={editChannel?.name || ""} onChange={(event) => setEditChannel((current) => current ? { ...current, name: event.target.value } : current)} /></Field><Field label="Kode channel"><input className={inputClass} value={editChannel?.id.toUpperCase() || ""} readOnly /></Field><Field label="Deskripsi pelanggan"><input className={inputClass} value={editChannel?.description || ""} onChange={(event) => setEditChannel((current) => current ? { ...current, description: event.target.value } : current)} /></Field><Field label="Urutan"><input className={inputClass} type="number" value={editChannel?.sortOrder ?? 0} onChange={(event) => setEditChannel((current) => current ? { ...current, sortOrder: Number(event.target.value) || 0 } : current)} /></Field><Field label="Logo channel" wide><label className="flex h-20 cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed border-[#b9c8db] bg-[#f8fafc] text-[9px] font-bold text-[#52627a]"><ImagePlus className="size-4 text-[#0769e9]" />{channelFile || editChannel?.imageUrl ? "Ganti gambar logo" : "Pilih gambar logo"}<input type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(event) => { const file = event.target.files?.[0]; if (!file) return; if (file.size > 2 * 1024 * 1024) { setError("Ukuran gambar maksimal 2MB."); return; } setChannelFile(file); const reader = new FileReader(); reader.onload = () => setEditChannel((current) => current ? { ...current, imageUrl: String(reader.result || "") } : current); reader.readAsDataURL(file); }} /></label></Field></div></Modal>
    <Modal open={Boolean(selectedTransaction)} title={`Detail ${selectedTransaction?.reference_id || "Transaksi"}`} description="Data pembayaran tersimpan" onClose={() => setSelectedTransaction(null)} footer={<button type="button" className={buttonClass} onClick={() => setSelectedTransaction(null)}>Tutup</button>}><div className="grid grid-cols-2 gap-3 text-[9px]"><Detail label="Pelanggan" value={selectedTransaction?.buyer_name} /><Detail label="Produk" value={`${selectedTransaction?.product_name || ""} ${selectedTransaction?.package_label || ""}`} /><Detail label="Channel" value={selectedTransaction?.payment_channel} /><Detail label="Total" value={formatMoney(selectedTransaction?.total || 0)} /><Detail label="Status" value={selectedTransaction?.payment_status} /><Detail label="Waktu" value={formatDate(selectedTransaction?.created_at || "")} /></div></Modal>
  </div>;
}

function SwitchRow({ label, checked, onChange }: { label: string; checked: boolean; onChange(value: boolean): void }) { return <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><span className="text-[9px] font-semibold text-[#42516a]">{label}</span><Toggle checked={checked} onChange={onChange} /></div>; }

function ImageEditor({ label, value, onChange, onClear, wide = false }: { label: string; value: string; onChange(event: ChangeEvent<HTMLInputElement>): void; onClear(): void; wide?: boolean }) {
  return <div className={wide ? "col-span-2" : ""}><span className="mb-1.5 block text-[9px] font-bold text-[#34445f]">{label}</span><div className="flex min-h-24 items-center gap-3 rounded-md border border-dashed border-[#b9c8db] bg-[#f8fafc] p-3">{value ? <img src={value} alt={label} className="h-16 w-28 rounded object-cover" /> : <span className="grid h-16 w-28 place-items-center rounded bg-white text-[#8a98aa]"><ImagePlus className="size-5" /></span>}<div><label className={`${buttonClass} cursor-pointer`}><ImagePlus className="size-3.5" />{value ? "Ganti Gambar" : "Pilih Gambar"}<input type="file" accept="image/png,image/jpeg,image/webp" onChange={onChange} className="hidden" /></label>{value && <button type="button" onClick={onClear} className="ml-2 text-[9px] font-bold text-rose-500">Hapus</button>}<p className="mt-1.5 text-[8px] text-[#8a98aa]">PNG, JPG, WEBP · Maks. 2MB</p></div></div></div>;
}

function formatMoney(value: number) { return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value); }
function formatDate(value: string) { return value ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "-"; }
function Detail({ label, value }: { label: string; value?: string }) { return <div className="rounded-md border border-[#e3e8ef] p-3"><span className="block text-[8px] text-[#8190a5]">{label}</span><strong className="mt-1 block text-[#243653]">{value || "-"}</strong></div>; }
