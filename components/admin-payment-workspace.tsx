"use client";
/* eslint-disable @next/next/no-img-element, react-hooks/set-state-in-effect */

import { useCallback, useEffect, useState, type ChangeEvent } from "react";
import { AlertTriangle, CheckCircle2, CreditCard, Eye, ImagePlus, Landmark, Plus, QrCode, Receipt, RefreshCw, Save, Search, Smartphone, Trash2, Wallet } from "lucide-react";
import { CopyUrl, Field, MetricCard, Modal, Panel, Status, TabBar, Toggle, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";
import { defaultPaymentPageSettings, type PaymentPageSettings } from "@/lib/payment-page-settings";

type Gateway = "doku" | "midtrans";
type Environment = "sandbox" | "production";
type Channel = {
  dbId: number | null;
  id: string;
  method: "va" | "ewallet" | "qris";
  name: string;
  description: string;
  imageUrl: string;
  group: string;
  enabled: boolean;
  sortOrder: number;
  gateway: Gateway;
  gatewayConfig: Record<string, string>;
  readiness?: { ready?: boolean; reason?: string | null };
};
type GatewaySetting = { gateway: Gateway; isActive: boolean };
type GatewayReadiness = {
  doku?: { ready?: boolean; reason?: string | null; environment?: string | null; mode?: string };
  midtrans?: { ready?: boolean; reason?: string | null; missing?: string[]; environment?: string | null; relayReady?: boolean; mode?: string };
};
type RoutingOverview = {
  dokuMode: "checkout";
  midtransMode: "snap";
  dokuEnvironment: Environment;
  midtransEnvironment: Environment;
  walletTopupGateway: Gateway | null;
  callbacks?: {
    dokuNotification?: string;
    midtransSnapNotification?: string;
    paymentReturn?: string;
  };
};
type PaymentOrder = { id: string; reference_id: string; buyer_name: string; payment_channel: string; payment_method: string; payment_status: string; total: number; created_at: string; product_name: string; package_label: string };

type JsonPayload = { error?: string; url?: string; [key: string]: unknown };

function groupForMethod(method: Channel["method"]) {
  return method === "qris" ? "QRIS" : method === "va" ? "VA Bank" : "E-Wallet";
}

export function AdminPaymentWorkspace() {
  const [tab, setTab] = useState("Metode Pembayaran");
  const [channels, setChannels] = useState<Channel[]>([]);
  const [gatewaySettings, setGatewaySettings] = useState<GatewaySetting[]>([
    { gateway: "midtrans", isActive: false },
    { gateway: "doku", isActive: false },
  ]);
  const [gatewayReadiness, setGatewayReadiness] = useState<GatewayReadiness>({});
  const [routing, setRouting] = useState<RoutingOverview | null>(null);
  const [editChannel, setEditChannel] = useState<Channel | null>(null);
  const [isCreatingChannel, setIsCreatingChannel] = useState(false);
  const [channelFile, setChannelFile] = useState<File | null>(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [canManageWallet, setCanManageWallet] = useState(false);
  const [walletSettings, setWalletSettings] = useState({ minTopup: 10_000, automaticTopupEnabled: false });
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
    const [channelResponse, pageResponse, walletResponse, orderResponse, routingResponse] = await Promise.all([
      fetch("/api/panel/payment-methods", { cache: "no-store" }),
      fetch("/api/panel/payment-page", { cache: "no-store" }),
      fetch("/api/panel/wallet", { cache: "no-store" }),
      fetch("/api/panel/orders", { cache: "no-store" }),
      fetch("/api/panel/payment-routing", { cache: "no-store" }).catch(() => null),
    ]);
    const [channelPayload, pagePayload, walletPayload, orderPayload] = await Promise.all([channelResponse.json(), pageResponse.json(), walletResponse.json(), orderResponse.json()]) as [
      { error?: string; channels?: Array<{ id: number | null; method: Channel["method"]; channel: string; name: string; description: string; imageUrl?: string; isActive: boolean; sortOrder: number; gateway: Gateway; gatewayConfig?: Record<string, string>; readiness?: { ready?: boolean; reason?: string | null } }>; gatewaySettings?: GatewaySetting[]; gatewayReadiness?: GatewayReadiness },
      { error?: string; settings?: PaymentPageSettings },
      { error?: string; settings?: typeof walletSettings },
      { error?: string; orders?: PaymentOrder[] },
    ];
    if (!channelResponse.ok) throw new Error(channelPayload.error || "Metode pembayaran gagal dimuat.");
    if (!pageResponse.ok) throw new Error(pagePayload.error || "Halaman pembayaran gagal dimuat.");
    if (!walletResponse.ok && walletResponse.status !== 403) throw new Error(walletPayload.error || "Pengaturan wallet gagal dimuat.");
    if (!orderResponse.ok) throw new Error(orderPayload.error || "Transaksi gagal dimuat.");

    if (channelPayload.channels) setChannels(channelPayload.channels.map((item) => ({
      dbId: item.id,
      id: item.channel,
      method: item.method,
      name: item.name,
      description: item.description,
      imageUrl: item.imageUrl || "",
      group: groupForMethod(item.method),
      enabled: item.isActive,
      sortOrder: item.sortOrder,
      gateway: item.gateway,
      gatewayConfig: item.gatewayConfig || {},
      readiness: item.readiness,
    })));
    if (channelPayload.gatewaySettings) setGatewaySettings(channelPayload.gatewaySettings);
    if (channelPayload.gatewayReadiness) setGatewayReadiness(channelPayload.gatewayReadiness);
    if (pagePayload.settings) { setPageSettings(pagePayload.settings); setHeroImage(pagePayload.settings.headerImageUrl); }
    const walletAllowed = walletResponse.ok;
    setCanManageWallet(walletAllowed);
    if (walletAllowed && walletPayload.settings) setWalletSettings(walletPayload.settings);
    setTransactions(orderPayload.orders || []);
    if (routingResponse?.ok) setRouting(await routingResponse.json() as RoutingOverview);
    return channelPayload.gatewayReadiness;
  }, []);

  useEffect(() => { load().catch((reason) => setError(reason instanceof Error ? reason.message : "Data pembayaran gagal dimuat.")); }, [load]);

  async function request(url: string, init: RequestInit) {
    const response = await fetch(url, init);
    const payload = await response.json().catch(() => ({})) as JsonPayload;
    if (!response.ok) throw new Error(payload.error || "Perubahan pembayaran gagal disimpan.");
    return payload;
  }

  async function saveChannel(channel: Channel) {
    await request("/api/panel/payment-methods", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        id: channel.dbId,
        method: channel.method,
        channel: channel.id.trim().toLowerCase(),
        name: channel.name,
        description: channel.description,
        imageUrl: channel.imageUrl,
        isActive: channel.enabled,
        sortOrder: channel.sortOrder,
        gateway: channel.gateway,
        gatewayConfig: channel.gatewayConfig,
      }),
    });
  }

  function openCreateChannel() {
    setIsCreatingChannel(true);
    setEditChannel({
      dbId: null,
      id: "",
      method: "va",
      name: "",
      description: "",
      imageUrl: "",
      group: "VA Bank",
      enabled: false,
      sortOrder: channels.length ? Math.max(...channels.map((item) => item.sortOrder)) + 1 : 0,
      gateway: "midtrans",
      gatewayConfig: { customerFeeEnabled: "true", customerFeeBps: "0", customerFeeFixed: "0" },
      readiness: undefined,
    });
  }

  async function saveEditedChannel() {
    if (!editChannel) return;
    setBusy(true); setError(""); setMessage("");
    try {
      let imageUrl = editChannel.imageUrl;
      if (channelFile) {
        const form = new FormData(); form.set("file", channelFile);
        const uploaded = await request("/api/panel/media", { method: "POST", body: form });
        imageUrl = typeof uploaded.url === "string" ? uploaded.url : "";
      }
      await saveChannel({ ...editChannel, imageUrl, group: groupForMethod(editChannel.method) });
      await load();
      setEditChannel(null); setIsCreatingChannel(false); setChannelFile(null);
      setMessage(`${editChannel.name || "Metode pembayaran"} berhasil disimpan.`);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Metode pembayaran gagal disimpan."); }
    finally { setBusy(false); }
  }

  async function deleteChannel(channel: Channel) {
    if (!window.confirm(`Hapus metode ${channel.name}?`)) return;
    setBusy(true); setError(""); setMessage("");
    try {
      if (channel.dbId) {
        await request(`/api/panel/payment-methods?id=${channel.dbId}`, { method: "DELETE" });
      }
      await load();
      setMessage(`${channel.name} berhasil dihapus.`);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Metode pembayaran gagal dihapus."); }
    finally { setBusy(false); }
  }

  async function save() {
    setMessage(""); setError(""); setBusy(true);
    try {
      if (tab === "Metode Pembayaran") {
        if (routing) {
          if (!routing.walletTopupGateway) throw new Error("Pilih gateway top up saldo sebelum menyimpan.");
          await request("/api/panel/payment-routing", {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "save_modes", ...routing }),
          });
        }
        for (const gateway of gatewaySettings) {
          await request("/api/panel/payment-methods", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "gateway_status", gateway: gateway.gateway, enabled: gateway.isActive }) });
        }
        for (const channel of channels) await saveChannel(channel);
        if (canManageWallet) {
          await request("/api/panel/wallet", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(walletSettings) });
        }
      } else if (tab === "Tampilan Halaman") {
        let headerImageUrl = pageSettings.headerImageUrl;
        if (heroFile) {
          const form = new FormData(); form.set("file", heroFile);
          const uploaded = await request("/api/panel/media", { method: "POST", body: form });
          headerImageUrl = typeof uploaded.url === "string" ? uploaded.url : "";
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

  async function syncChannels() {
    setMessage(""); setError(""); setBusy(true);
    try {
      await request("/api/panel/payment-methods", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "sync", gateways: ["midtrans", "doku"] }) });
      await load();
      setMessage("Daftar metode bawaan provider berhasil disinkronkan. Metode yang baru masuk tetap OFF sampai diaktifkan manual.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Sinkronisasi metode gagal."); }
    finally { setBusy(false); }
  }

  async function testConnection() {
    setMessage(""); setError(""); setBusy(true);
    try {
      const readiness = await load();
      const doku = readiness?.doku?.ready ? "DOKU Checkout siap" : "DOKU Checkout belum siap";
      const midtrans = readiness?.midtrans?.ready ? "Midtrans siap" : "Midtrans belum siap";
      setMessage(`${doku} · ${midtrans}.`);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Pemeriksaan gateway gagal."); }
    finally { setBusy(false); }
  }

  const paymentOrders = transactions.filter((order) => order.payment_method !== "wallet");
  const shownTransactions = paymentOrders.filter((order) => `${order.reference_id} ${order.buyer_name} ${order.product_name}`.toLowerCase().includes(transactionQuery.toLowerCase()));
  const successful = paymentOrders.filter((order) => order.payment_status === "paid");
  const failed = paymentOrders.filter((order) => ["failed", "expired", "cancelled"].includes(order.payment_status));
  const gatewayActive = (gateway: Gateway) => gatewaySettings.find((item) => item.gateway === gateway)?.isActive ?? false;
  const walletTopupGateway = routing?.walletTopupGateway ?? null;
  const walletTopupGatewayReady = walletTopupGateway ? Boolean(gatewayReadiness[walletTopupGateway]?.ready) : false;
  const walletTopupGatewayActive = walletTopupGateway ? gatewayActive(walletTopupGateway) : false;

  return <div>
    <WorkspaceHeader title="Pembayaran" description="Kelola metode, gateway, top up saldo, tampilan halaman, dan transaksi. Customer tidak melihat nama gateway internal." actions={<><button type="button" disabled={busy} onClick={testConnection} className={buttonClass}><RefreshCw className={`size-3.5 ${busy ? "animate-spin" : ""}`} />Periksa Konfigurasi</button><button type="button" disabled={busy} onClick={save} className={primaryButtonClass}><Save className="size-3.5" />{busy ? "Memproses..." : "Simpan Perubahan"}</button></>} />

    {message && <button type="button" onClick={() => setMessage("")} className="mb-3 flex w-full items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-left text-[9px] font-semibold text-emerald-700"><CheckCircle2 className="size-3.5" />{message}</button>}
    {error && <button type="button" onClick={() => setError("")} className="mb-3 flex w-full items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-left text-[9px] font-semibold text-red-700"><AlertTriangle className="size-3.5" />{error}</button>}

    <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
      <MetricCard icon={Wallet} label="Total Pembayaran" value={String(paymentOrders.length)} detail="Data transaksi tersimpan" tone="green" />
      <MetricCard icon={CreditCard} label="Nilai Transaksi" value={formatMoney(successful.reduce((sum, order) => sum + Number(order.total || 0), 0))} detail="Pembayaran berhasil" />
      <MetricCard icon={Receipt} label="Menunggu" value={String(paymentOrders.filter((order) => order.payment_status === "pending").length)} detail="Perlu dipantau" tone="amber" />
      <MetricCard icon={CheckCircle2} label="Berhasil" value={String(successful.length)} detail={`${paymentOrders.length ? Math.round(successful.length / paymentOrders.length * 100) : 0}% success rate`} tone="green" />
      <MetricCard icon={AlertTriangle} label="Gagal / Expired" value={String(failed.length)} detail="Perlu diperiksa" tone="red" />
    </div>

    <TabBar tabs={["Metode Pembayaran", "Tampilan Halaman", "Transaksi Pembayaran"]} active={tab} onChange={setTab} />

    {tab === "Metode Pembayaran" && <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
      <Panel title="Metode Pembayaran" description="Tambah, hapus, aktif/nonaktifkan, dan tentukan gateway tiap metode dari sini." action={<div className="flex items-center gap-2"><button type="button" onClick={openCreateChannel} disabled={busy} className={primaryButtonClass}><Plus className="size-3.5" />Tambah Metode</button><button type="button" onClick={syncChannels} disabled={busy} className={buttonClass}><RefreshCw className="size-3.5" />Sinkron Provider</button></div>}>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px] text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr><th className="px-4 py-2.5">Channel</th><th>Jenis</th><th>Gateway</th><th>Fee Customer</th><th>Kesiapan</th><th>Status</th><th className="pr-4 text-right">Aksi</th></tr></thead>
            <tbody className="divide-y divide-[#edf0f4]">{channels.map((channel) => <tr key={`${channel.dbId ?? "new"}:${channel.method}:${channel.id}`} className="text-[9px] text-[#42516a]"><td className="px-4 py-2.5"><div className="flex items-center gap-2"><span className="grid size-7 place-items-center rounded bg-blue-50 text-[#0769e9]">{channel.group === "QRIS" ? <QrCode className="size-3.5" /> : channel.group === "VA Bank" ? <Landmark className="size-3.5" /> : <Smartphone className="size-3.5" />}</span><div><strong className="block text-[#23334e]">{channel.name}</strong><span className="text-[8px] text-[#8a98aa]">{channel.description || channel.id}</span></div></div></td><td>{channel.group}</td><td><select className={`${inputClass} h-8 w-32`} value={channel.gateway} onChange={(event) => setChannels((current) => current.map((item) => item.dbId === channel.dbId ? { ...item, gateway: event.target.value as Gateway, readiness: undefined } : item))}><option value="doku">DOKU Checkout</option><option value="midtrans">Midtrans</option></select></td><td><span className="text-[8px] font-bold text-[#34445f]">{channel.gatewayConfig.customerFeeEnabled === "false" ? "OFF" : `${((Number(channel.gatewayConfig.customerFeeBps || "0") || 0) / 100).toLocaleString("id-ID", { maximumFractionDigits: 2 })}% + ${formatMoney(Number(channel.gatewayConfig.customerFeeFixed || 0) || 0)}`}</span></td><td><Status tone={channel.readiness?.ready ? "green" : channel.readiness ? "amber" : "blue"}>{channel.readiness ? (channel.readiness.ready ? "Siap" : "Belum siap") : "Simpan untuk cek"}</Status>{channel.readiness?.reason && <span className="mt-1 block max-w-[180px] text-[7px] leading-3 text-[#8a98aa]">{channel.readiness.reason}</span>}</td><td><Toggle checked={channel.enabled} onChange={(checked) => setChannels((current) => current.map((item) => item.dbId === channel.dbId ? { ...item, enabled: checked } : item))} /></td><td className="pr-4 text-right"><div className="flex justify-end gap-1.5"><button type="button" onClick={() => { setIsCreatingChannel(false); setEditChannel(channel); }} className={buttonClass}>Edit</button><button type="button" onClick={() => deleteChannel(channel)} className={`${buttonClass} text-rose-600`}><Trash2 className="size-3.5" />Hapus</button></div></td></tr>)}</tbody>
          </table>
          {!channels.length && <div className="p-8 text-center text-[10px] text-[#8190a5]">Belum ada metode pembayaran. Klik <strong>Tambah Metode</strong> atau <strong>Sinkron Provider</strong>.</div>}
        </div>
      </Panel>
      <div className="space-y-4">
        <Panel title="Gateway & Environment" description="Status siap dan status aktif dipisahkan. Gateway yang valid tetap bisa dimatikan dengan toggle."><div className="space-y-3 p-4">
          <GatewayControl gateway="doku" title="DOKU Checkout" ready={Boolean(gatewayReadiness.doku?.ready)} enabled={gatewayActive("doku")} environment={routing?.dokuEnvironment || "sandbox"} onEnabled={(value) => setGatewaySettings((current) => current.map((item) => item.gateway === "doku" ? { ...item, isActive: value } : item))} onEnvironment={(value) => setRouting((current) => current ? { ...current, dokuEnvironment: value } : current)} />
          <GatewayControl gateway="midtrans" title="Midtrans Snap" ready={Boolean(gatewayReadiness.midtrans?.ready)} enabled={gatewayActive("midtrans")} environment={routing?.midtransEnvironment || "sandbox"} onEnabled={(value) => setGatewaySettings((current) => current.map((item) => item.gateway === "midtrans" ? { ...item, isActive: value } : item))} onEnvironment={(value) => setRouting((current) => current ? { ...current, midtransEnvironment: value } : current)} />
        </div></Panel>
        {canManageWallet && <Panel title="Top Up Saldo" description="Pilih gateway khusus untuk top up saldo. Pilihan ini terpisah dari routing checkout."><div className="grid gap-3 p-4">
          <Field label="Gateway top up saldo"><select className={inputClass} value={walletTopupGateway ?? ""} onChange={(event) => setRouting((current) => current ? { ...current, walletTopupGateway: event.target.value as Gateway } : current)}><option value="" disabled>Pilih gateway top up</option><option value="doku">DOKU Checkout</option><option value="midtrans">Midtrans Snap</option></select></Field>
          <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><div><strong className="block text-[9px] text-[#34445f]">Status gateway pilihan</strong><span className="text-[8px] text-[#8a98aa]">Harus siap dan gateway global harus ON agar top up dapat dibuat.</span></div><Status tone={walletTopupGatewayReady && walletTopupGatewayActive ? "green" : "amber"}>{!walletTopupGateway ? "Belum dipilih" : walletTopupGatewayReady ? (walletTopupGatewayActive ? "Siap & Aktif" : "Siap · OFF") : "Belum siap"}</Status></div>
          <Field label="Minimum top up saldo"><input className={inputClass} inputMode="numeric" value={walletSettings.minTopup} onChange={(event) => setWalletSettings((current) => ({ ...current, minTopup: Number(event.target.value.replace(/\D/g, "")) || 0 }))} /></Field>
          <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><div><strong className="block text-[9px] text-[#34445f]">Aktifkan top up saldo otomatis</strong><span className="text-[8px] text-[#8a98aa]">Master toggle top up saldo. OFF menolak semua permintaan top up otomatis.</span></div><Toggle checked={walletSettings.automaticTopupEnabled} onChange={(value) => setWalletSettings((current) => ({ ...current, automaticTopupEnabled: value }))} /></div>
        </div></Panel>}
        <Panel title="Callback Publik" description="URL untuk dashboard gateway."><div className="space-y-2 p-4"><CopyUrl label="DOKU Checkout Notification" value={routing?.callbacks?.dokuNotification || "/api/payments/doku/callback"} /><CopyUrl label="Midtrans Snap Notification" value={routing?.callbacks?.midtransSnapNotification || "/api/payments/midtrans/snap/notification"} /></div></Panel>
      </div>
    </div>}

    {tab === "Tampilan Halaman" && <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_410px]">
      <Panel title="Editor Halaman Pembayaran" description="Ubah gambar, teks, warna, instruksi, dan elemen yang dilihat pelanggan."><div className="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
        <Field label="Judul saat menunggu pembayaran"><input className={inputClass} value={pageSettings.pendingTitle} onChange={(event) => setPageSettings((current) => ({ ...current, pendingTitle: event.target.value }))} /></Field>
        <Field label="Judul pembayaran berhasil"><input className={inputClass} value={pageSettings.paidTitle} onChange={(event) => setPageSettings((current) => ({ ...current, paidTitle: event.target.value }))} /></Field>
        <Field label="Judul pembayaran gagal"><input className={inputClass} value={pageSettings.failedTitle} onChange={(event) => setPageSettings((current) => ({ ...current, failedTitle: event.target.value }))} /></Field>
        <Field label="Teks kecil di atas judul"><input className={inputClass} value={pageSettings.eyebrow} onChange={(event) => setPageSettings((current) => ({ ...current, eyebrow: event.target.value }))} /></Field>
        <Field label="Warna utama"><div className="flex gap-2"><input type="color" value={pageSettings.accentColor} onChange={(event) => setPageSettings((current) => ({ ...current, accentColor: event.target.value }))} className="h-9 w-12 rounded border border-[#dfe5ed] bg-white p-1" /><input className={inputClass} value={pageSettings.accentColor} onChange={(event) => setPageSettings((current) => ({ ...current, accentColor: event.target.value }))} /></div></Field>
        <Field label="Instruksi pembayaran" wide><textarea value={pageSettings.subtitle} onChange={(event) => setPageSettings((current) => ({ ...current, subtitle: event.target.value }))} className={`${inputClass} h-20 py-2`} /></Field>
        <Field label="Judul pemberitahuan invoice"><input className={inputClass} value={pageSettings.invoiceNoticeTitle} onChange={(event) => setPageSettings((current) => ({ ...current, invoiceNoticeTitle: event.target.value }))} /></Field>
        <Field label="Isi pemberitahuan invoice"><input className={inputClass} value={pageSettings.invoiceNoticeText} onChange={(event) => setPageSettings((current) => ({ ...current, invoiceNoticeText: event.target.value }))} /></Field>
        <Field label="Pesan status menunggu" wide><textarea value={pageSettings.pendingStatusText} onChange={(event) => setPageSettings((current) => ({ ...current, pendingStatusText: event.target.value }))} className={`${inputClass} h-16 py-2`} /></Field>
        <Field label="Pesan status berhasil" wide><textarea value={pageSettings.paidStatusText} onChange={(event) => setPageSettings((current) => ({ ...current, paidStatusText: event.target.value }))} className={`${inputClass} h-16 py-2`} /></Field>
        <Field label="Pesan status gagal" wide><textarea value={pageSettings.failedStatusText} onChange={(event) => setPageSettings((current) => ({ ...current, failedStatusText: event.target.value }))} className={`${inputClass} h-16 py-2`} /></Field>
        <Field label="Teks tombol bayar"><input className={inputClass} value={pageSettings.payButtonText} onChange={(event) => setPageSettings((current) => ({ ...current, payButtonText: event.target.value }))} /></Field>
        <Field label="Teks tombol cek status"><input className={inputClass} value={pageSettings.checkStatusButtonText} onChange={(event) => setPageSettings((current) => ({ ...current, checkStatusButtonText: event.target.value }))} /></Field>
        <Field label="Teks tombol cek invoice"><input className={inputClass} value={pageSettings.checkInvoiceButtonText} onChange={(event) => setPageSettings((current) => ({ ...current, checkInvoiceButtonText: event.target.value }))} /></Field>
        <Field label="Teks tombol bantuan"><input className={inputClass} value={pageSettings.supportText} onChange={(event) => setPageSettings((current) => ({ ...current, supportText: event.target.value }))} /></Field>
        <Field label="URL bantuan" wide><input className={inputClass} value={pageSettings.supportUrl} onChange={(event) => setPageSettings((current) => ({ ...current, supportUrl: event.target.value }))} placeholder="https:// atau /hubungi-kami" /></Field>
        <ImageEditor label="Gambar / Banner pembayaran" value={heroImage} onChange={(event) => readImage(event, setHeroImage)} onClear={() => { setHeroImage(""); setHeroFile(null); setPageSettings((current) => ({ ...current, headerImageUrl: "" })); }} wide />
        <div className="grid grid-cols-1 gap-3 sm:col-span-2 sm:grid-cols-2"><SwitchRow label="Tampilkan identitas toko" checked={pageSettings.showStoreBrand} onChange={(value) => setPageSettings((current) => ({ ...current, showStoreBrand: value }))} /><SwitchRow label="Tampilkan ringkasan pesanan" checked={pageSettings.showOrderSummary} onChange={(value) => setPageSettings((current) => ({ ...current, showOrderSummary: value }))} /><SwitchRow label="Tampilkan pemberitahuan invoice" checked={pageSettings.showInvoiceNotice} onChange={(value) => setPageSettings((current) => ({ ...current, showInvoiceNotice: value }))} /><SwitchRow label="Tampilkan status pembayaran" checked={pageSettings.showStatusBox} onChange={(value) => setPageSettings((current) => ({ ...current, showStatusBox: value }))} /><SwitchRow label="Tampilkan bantuan" checked={pageSettings.showSupport} onChange={(value) => setPageSettings((current) => ({ ...current, showSupport: value }))} /></div>
      </div></Panel>
      <Panel title="Preview Halaman Pembayaran" description="Preview customer tanpa nama payment gateway."><div className="bg-[#f4f7fb] p-5"><div className="overflow-hidden rounded-xl border border-[#e1e6ed] bg-white shadow-sm">{heroImage ? <img src={heroImage} alt="Preview banner pembayaran" className="h-28 w-full object-cover" /> : <div className="grid h-28 place-items-center bg-gradient-to-r from-[#0d2b57] to-[#0769e9] text-[12px] font-black text-white">LFAMILIA PAYMENT</div>}<div className="p-4"><div className="flex items-center gap-2"><span className="grid size-9 place-items-center rounded bg-blue-50 text-[#0769e9]"><CreditCard className="size-4" /></span><div><strong className="block text-[13px] text-[#14213a]">{pageSettings.pendingTitle}</strong><p className="mt-0.5 text-[8px] text-[#8190a5]">{pageSettings.subtitle}</p></div></div><div className="mt-4 rounded-lg border border-[#e3e8ef] p-3"><div className="flex justify-between text-[9px]"><span>Mobile Legends 86 Diamonds</span><strong>Rp 20.000</strong></div><div className="mt-3 flex items-center justify-between rounded-md bg-[#f6f8fb] p-3"><div className="flex items-center gap-2"><QrCode className="size-4" style={{ color: pageSettings.accentColor }} /><span className="text-[9px] font-bold">QRIS</span></div><Status tone="green">Dipilih</Status></div><button type="button" className="mt-3 h-9 w-full rounded-md text-[10px] font-extrabold text-white" style={{ backgroundColor: pageSettings.accentColor }}>{pageSettings.payButtonText}</button></div></div></div></div></Panel>
    </div>}

    {tab === "Transaksi Pembayaran" && <Panel title="Transaksi Pembayaran Terbaru" description="Riwayat pembayaran lintas gateway yang tersimpan di database." action={<div className="relative w-full sm:w-auto"><Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8190a5]" /><input value={transactionQuery} onChange={(event) => setTransactionQuery(event.target.value)} className={`${inputClass} w-full pl-8 sm:w-56`} placeholder="Cari invoice atau pelanggan..." /></div>}><div className="overflow-x-auto"><table className="w-full min-w-[760px] text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr>{["Invoice", "Pelanggan", "Channel", "Total", "Status", "Waktu", "Aksi"].map((head) => <th key={head} className="px-4 py-2.5">{head}</th>)}</tr></thead><tbody className="divide-y divide-[#edf0f4]">{shownTransactions.map((order) => <tr key={order.id} className="text-[9px] text-[#42516a]"><td className="px-4 py-2.5"><strong className="text-[#0769e9]">{order.reference_id}</strong></td><td className="px-4">{order.buyer_name}</td><td className="px-4">{order.payment_channel}</td><td className="px-4">{formatMoney(order.total)}</td><td className="px-4"><Status tone={order.payment_status === "paid" ? "green" : order.payment_status === "pending" ? "amber" : "red"}>{order.payment_status}</Status></td><td className="px-4">{formatDate(order.created_at)}</td><td className="px-4"><button type="button" onClick={() => setSelectedTransaction(order)} className={buttonClass}><Eye className="size-3.5" />Detail</button></td></tr>)}</tbody></table></div></Panel>}

    <Modal open={Boolean(editChannel)} title={`${isCreatingChannel ? "Tambah" : "Edit"} ${editChannel?.name || "Metode Pembayaran"}`} description="Metode dan gateway dapat dikelola dari Admin. Kode gateway opsional hanya dipakai jika channel belum ada pada mapping bawaan provider." onClose={() => { setEditChannel(null); setIsCreatingChannel(false); setChannelFile(null); }} footer={<><button type="button" className={buttonClass} onClick={() => { setEditChannel(null); setIsCreatingChannel(false); setChannelFile(null); }}>Batal</button><button type="button" disabled={busy} className={primaryButtonClass} onClick={saveEditedChannel}>{busy ? "Menyimpan..." : "Simpan Metode"}</button></>}><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <Field label="Nama tampilan"><input className={inputClass} value={editChannel?.name || ""} onChange={(event) => setEditChannel((current) => current ? { ...current, name: event.target.value } : current)} /></Field>
      <Field label="Kode channel"><input className={inputClass} value={editChannel?.id || ""} onChange={(event) => setEditChannel((current) => current ? { ...current, id: event.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "") } : current)} placeholder="contoh: bca, dana, qris" /></Field>
      <Field label="Jenis"><select className={inputClass} value={editChannel?.method || "va"} onChange={(event) => setEditChannel((current) => current ? { ...current, method: event.target.value as Channel["method"], group: groupForMethod(event.target.value as Channel["method"]) } : current)}><option value="va">VA Bank</option><option value="ewallet">E-Wallet</option><option value="qris">QRIS</option></select></Field>
      <Field label="Gateway"><select className={inputClass} value={editChannel?.gateway || "midtrans"} onChange={(event) => setEditChannel((current) => current ? { ...current, gateway: event.target.value as Gateway } : current)}><option value="doku">DOKU Checkout</option><option value="midtrans">Midtrans</option></select></Field>
      <Field label="Urutan"><input className={inputClass} type="number" value={editChannel?.sortOrder ?? 0} onChange={(event) => setEditChannel((current) => current ? { ...current, sortOrder: Number(event.target.value) || 0 } : current)} /></Field>
      <Field label="Kode gateway (opsional)" help="Isi payment type resmi provider jika channel custom belum ada pada mapping bawaan. Untuk DOKU gunakan token Checkout resmi, mis. VIRTUAL_ACCOUNT_BCA."><input className={`${inputClass} font-mono`} value={editChannel?.gatewayConfig.paymentType || ""} onChange={(event) => setEditChannel((current) => current ? { ...current, gatewayConfig: { ...current.gatewayConfig, paymentType: event.target.value.trim() } } : current)} placeholder="contoh: VIRTUAL_ACCOUNT_BCA" /></Field>
      <div className="col-span-2 flex items-center justify-between rounded-md border border-[#e3e8ef] bg-[#f8fafc] p-3"><div><strong className="block text-[9px] text-[#34445f]">Bebankan biaya gateway ke customer</strong><span className="mt-0.5 block text-[8px] text-[#8a98aa]">ON: biaya persen + biaya tetap ditambahkan ke total pembayaran customer. Persentase dihitung gross-up agar merchant tidak menanggung potongannya.</span></div><Toggle checked={editChannel?.gatewayConfig.customerFeeEnabled !== "false"} onChange={(checked) => setEditChannel((current) => current ? { ...current, gatewayConfig: { ...current.gatewayConfig, customerFeeEnabled: checked ? "true" : "false" } } : current)} /></div>
      <Field label="Biaya persentase customer (%)" help="Contoh 0,7 untuk 0,7%. Backend melakukan gross-up terhadap total akhir."><input className={inputClass} type="number" min="0" max="99.99" step="0.01" value={String((Number(editChannel?.gatewayConfig.customerFeeBps ?? 0) || 0) / 100)} onChange={(event) => setEditChannel((current) => current ? { ...current, gatewayConfig: { ...current.gatewayConfig, customerFeeBps: String(Math.max(0, Math.min(9999, Math.round(Number(event.target.value || 0) * 100)))) } } : current)} placeholder="0.7" /></Field>
      <Field label="Biaya tetap customer (Rp)" help="Biaya nominal tetap per transaksi. Contoh 4000 untuk Rp4.000."><input className={inputClass} type="number" min="0" max="100000000" step="1" value={editChannel?.gatewayConfig.customerFeeFixed || "0"} onChange={(event) => setEditChannel((current) => current ? { ...current, gatewayConfig: { ...current.gatewayConfig, customerFeeFixed: String(Math.max(0, Math.min(100_000_000, Math.round(Number(event.target.value || 0))))) } } : current)} placeholder="4000" /></Field>
      <Field label="Deskripsi pelanggan" wide><input className={inputClass} value={editChannel?.description || ""} onChange={(event) => setEditChannel((current) => current ? { ...current, description: event.target.value } : current)} /></Field>
      <Field label="Logo channel" wide><label className="flex h-20 cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed border-[#b9c8db] bg-[#f8fafc] text-[9px] font-bold text-[#52627a]"><ImagePlus className="size-4 text-[#0769e9]" />{channelFile || editChannel?.imageUrl ? "Ganti gambar logo" : "Pilih gambar logo"}<input type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(event) => { const file = event.target.files?.[0]; if (!file) return; if (file.size > 2 * 1024 * 1024) { setError("Ukuran gambar maksimal 2MB."); return; } setChannelFile(file); const reader = new FileReader(); reader.onload = () => setEditChannel((current) => current ? { ...current, imageUrl: String(reader.result || "") } : current); reader.readAsDataURL(file); }} /></label></Field>
    </div></Modal>
    <Modal open={Boolean(selectedTransaction)} title={`Detail ${selectedTransaction?.reference_id || "Transaksi"}`} description="Data pembayaran tersimpan" onClose={() => setSelectedTransaction(null)} footer={<button type="button" className={buttonClass} onClick={() => setSelectedTransaction(null)}>Tutup</button>}><div className="grid grid-cols-2 gap-3 text-[9px]"><Detail label="Pelanggan" value={selectedTransaction?.buyer_name} /><Detail label="Produk" value={`${selectedTransaction?.product_name || ""} ${selectedTransaction?.package_label || ""}`} /><Detail label="Channel" value={selectedTransaction?.payment_channel} /><Detail label="Total" value={formatMoney(selectedTransaction?.total || 0)} /><Detail label="Status" value={selectedTransaction?.payment_status} /><Detail label="Waktu" value={formatDate(selectedTransaction?.created_at || "")} /></div></Modal>
  </div>;
}

function GatewayControl({ title, ready, enabled, environment, onEnabled, onEnvironment }: { gateway: Gateway; title: string; ready: boolean; enabled: boolean; environment: Environment; onEnabled(value: boolean): void; onEnvironment(value: Environment): void }) {
  return <div className="rounded-md border border-[#e3e8ef] p-3">
    <div className="mb-3 flex items-center justify-between"><div><strong className="block text-[10px] text-[#34445f]">{title}</strong><Status tone={ready ? "green" : "amber"}>{ready ? "Konfigurasi Siap" : "Belum Siap"}</Status></div><div className="flex items-center gap-2"><span className="text-[8px] font-semibold text-[#718198]">Aktif</span><Toggle checked={enabled} onChange={onEnabled} /></div></div>
    <div className="space-y-2">
      <div><span className="mb-1 block text-[8px] font-bold text-[#52627a]">Environment</span><div className="flex items-center justify-between rounded-md border border-[#e3e8ef] bg-[#f8fafc] px-3 py-2"><span className={`text-[8px] ${environment === "sandbox" ? "font-extrabold text-[#1769e8]" : "font-semibold text-[#8190a5]"}`}>Sandbox</span><Toggle checked={environment === "production"} onChange={(checked) => onEnvironment(checked ? "production" : "sandbox")} /><span className={`text-[8px] ${environment === "production" ? "font-extrabold text-[#1769e8]" : "font-semibold text-[#8190a5]"}`}>Production</span></div></div>
    </div>
  </div>;
}
function SwitchRow({ label, checked, onChange }: { label: string; checked: boolean; onChange(value: boolean): void }) { return <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><span className="text-[9px] font-semibold text-[#42516a]">{label}</span><Toggle checked={checked} onChange={onChange} /></div>; }
function ImageEditor({ label, value, onChange, onClear, wide = false }: { label: string; value: string; onChange(event: ChangeEvent<HTMLInputElement>): void; onClear(): void; wide?: boolean }) { return <div className={wide ? "col-span-2" : ""}><span className="mb-1.5 block text-[9px] font-bold text-[#34445f]">{label}</span><div className="flex min-h-24 items-center gap-3 rounded-md border border-dashed border-[#b9c8db] bg-[#f8fafc] p-3">{value ? <img src={value} alt={label} className="h-16 w-28 rounded object-cover" /> : <span className="grid h-16 w-28 place-items-center rounded bg-white text-[#8a98aa]"><ImagePlus className="size-5" /></span>}<div><label className={`${buttonClass} cursor-pointer`}><ImagePlus className="size-3.5" />{value ? "Ganti Gambar" : "Pilih Gambar"}<input type="file" accept="image/png,image/jpeg,image/webp" onChange={onChange} className="hidden" /></label>{value && <button type="button" onClick={onClear} className="ml-2 text-[9px] font-bold text-rose-500">Hapus</button>}<p className="mt-1.5 text-[8px] text-[#8a98aa]">PNG, JPG, WEBP · Maks. 2MB</p></div></div></div>; }
function formatMoney(value: number) { return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value); }
function formatDate(value: string) { return value ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "-"; }
function Detail({ label, value }: { label: string; value?: string }) { return <div className="rounded-md border border-[#e3e8ef] p-3"><span className="block text-[8px] text-[#8190a5]">{label}</span><strong className="mt-1 block text-[#243653]">{value || "-"}</strong></div>; }
