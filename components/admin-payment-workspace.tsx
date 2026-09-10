"use client";

import { useState, type ChangeEvent } from "react";
import { AlertTriangle, CheckCircle2, CreditCard, Eye, ImagePlus, Landmark, QrCode, Receipt, RefreshCw, Save, Search, Smartphone, Wallet } from "lucide-react";
import { CopyUrl, Field, MetricCard, Modal, Panel, Status, TabBar, Toggle, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

type Channel = { id: string; name: string; group: string; fee: string; settlement: string; enabled: boolean; tone: "green" | "amber" | "gray" };

const initialChannels: Channel[] = [
  { id: "qris", name: "QRIS DOKU", group: "QRIS", fee: "0,7%", settlement: "T+1", enabled: true, tone: "green" },
  { id: "bca", name: "Virtual Account BCA", group: "VA Bank", fee: "Rp 4.000", settlement: "Realtime", enabled: true, tone: "green" },
  { id: "bri", name: "Virtual Account BRI", group: "VA Bank", fee: "Rp 4.000", settlement: "Realtime", enabled: true, tone: "green" },
  { id: "bni", name: "Virtual Account BNI", group: "VA Bank", fee: "Rp 4.000", settlement: "Realtime", enabled: true, tone: "green" },
  { id: "mandiri", name: "Virtual Account Mandiri", group: "VA Bank", fee: "Rp 4.000", settlement: "Realtime", enabled: true, tone: "green" },
  { id: "gopay", name: "GoPay", group: "E-Wallet", fee: "Sesuai DOKU", settlement: "Belum aktif", enabled: false, tone: "amber" },
  { id: "ovo", name: "OVO", group: "E-Wallet", fee: "Sesuai DOKU", settlement: "Belum aktif", enabled: false, tone: "amber" },
];

const transactions = [
  ["INV/20250424/0012", "Rizky Pratama", "QRIS DOKU", "Rp 20.000", "Berhasil", "24 Apr 2025 10:24"],
  ["INV/20250424/0011", "Siti Aulia", "DOKU VA BCA", "Rp 33.000", "Berhasil", "24 Apr 2025 09:18"],
  ["INV/20250424/0010", "Budi Santoso", "DOKU GoPay", "Rp 75.000", "Menunggu", "24 Apr 2025 08:55"],
  ["INV/20250423/0099", "Andi Saputra", "QRIS DOKU", "Rp 16.000", "Kedaluwarsa", "23 Apr 2025 22:14"],
  ["INV/20250423/0098", "Nabila Putri", "DOKU VA BRI", "Rp 149.000", "Gagal", "23 Apr 2025 21:07"],
];

export function AdminPaymentWorkspace() {
  const [tab, setTab] = useState("Channel Pembayaran");
  const [channels, setChannels] = useState(initialChannels);
  const [editChannel, setEditChannel] = useState<Channel | null>(null);
  const [tested, setTested] = useState(false);
  const [saved, setSaved] = useState(false);
  const [heroImage, setHeroImage] = useState("");
  const [brandImage, setBrandImage] = useState("");
  const [title, setTitle] = useState("Selesaikan Pembayaran");
  const [instruction, setInstruction] = useState("Pilih metode pembayaran lalu ikuti petunjuk yang tersedia.");
  const [accent, setAccent] = useState("#0769e9");
  const [expiry, setExpiry] = useState("60 menit");
  const [feeMode, setFeeMode] = useState("Ditanggung pelanggan");

  function readImage(event: ChangeEvent<HTMLInputElement>, setter: (value: string) => void) {
    const file = event.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => setter(String(reader.result || ""));
    reader.readAsDataURL(file);
  }

  function save() { setSaved(true); window.setTimeout(() => setSaved(false), 1800); }

  return <div>
    <WorkspaceHeader title="Pembayaran" description="Kelola DOKU Direct API, channel pembayaran, transaksi, dan tampilan halaman bayar LFAMILIA." actions={<><button type="button" onClick={() => setTested(true)} className={buttonClass}><RefreshCw className="size-3.5" />Tes Koneksi</button><button type="button" onClick={save} className={primaryButtonClass}><Save className="size-3.5" />{saved ? "Tersimpan" : "Simpan Perubahan"}</button></>} />

    {tested && <div className="mb-3 flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] font-semibold text-emerald-700"><CheckCircle2 className="size-3.5" />Simulasi UI: DOKU Direct API terhubung. Tes koneksi asli akan diaktifkan saat backend dikerjakan.</div>}

    <div className="mb-4 grid grid-cols-5 gap-3">
      <MetricCard icon={Wallet} label="Pembayaran Hari Ini" value="158" detail="+11,3% dari kemarin" tone="green" />
      <MetricCard icon={CreditCard} label="Nilai Transaksi" value="Rp 2.480.980" detail="DOKU Direct API" />
      <MetricCard icon={Receipt} label="Menunggu" value="12" detail="Perlu dipantau" tone="amber" />
      <MetricCard icon={CheckCircle2} label="Berhasil" value="146" detail="92,4% success rate" tone="green" />
      <MetricCard icon={AlertTriangle} label="Gagal / Expired" value="8" detail="5 transaksi perlu dicek" tone="red" />
    </div>

    <TabBar tabs={["Channel Pembayaran", "Tampilan Halaman", "Transaksi DOKU"]} active={tab} onChange={setTab} />

    {tab === "Channel Pembayaran" && <div className="grid grid-cols-[minmax(0,1fr)_330px] gap-4">
      <Panel title="Channel DOKU" description="Hanya channel yang aktif di akun DOKU yang boleh dinyalakan." action={<Status tone="green">DOKU Aktif</Status>}>
        <table className="w-full text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr><th className="px-4 py-2.5">Channel</th><th>Jenis</th><th>Biaya</th><th>Settlement</th><th>Status</th><th className="pr-4 text-right">Aksi</th></tr></thead>
          <tbody className="divide-y divide-[#edf0f4]">{channels.map((channel) => <tr key={channel.id} className="text-[9px] text-[#42516a]"><td className="px-4 py-2.5"><div className="flex items-center gap-2"><span className="grid size-7 place-items-center rounded bg-blue-50 text-[#0769e9]">{channel.group === "QRIS" ? <QrCode className="size-3.5" /> : channel.group === "VA Bank" ? <Landmark className="size-3.5" /> : <Smartphone className="size-3.5" />}</span><strong className="text-[#23334e]">{channel.name}</strong></div></td><td>{channel.group}</td><td>{channel.fee}</td><td>{channel.settlement}</td><td><Toggle checked={channel.enabled} onChange={(checked) => setChannels((current) => current.map((item) => item.id === channel.id ? { ...item, enabled: checked } : item))} /></td><td className="pr-4 text-right"><button type="button" onClick={() => setEditChannel(channel)} className={buttonClass}>Edit</button></td></tr>)}</tbody>
        </table>
      </Panel>
      <div className="space-y-4">
        <Panel title="Pengaturan Checkout" description="Berlaku untuk transaksi toko dan top up saldo."><div className="grid gap-3 p-4">
          <Field label="Gateway utama"><input className={inputClass} value="DOKU Direct API" readOnly /></Field>
          <Field label="Masa berlaku pembayaran"><select className={inputClass} value={expiry} onChange={(e) => setExpiry(e.target.value)}><option>30 menit</option><option>60 menit</option><option>24 jam</option></select></Field>
          <Field label="Biaya layanan"><select className={inputClass} value={feeMode} onChange={(e) => setFeeMode(e.target.value)}><option>Ditanggung pelanggan</option><option>Ditanggung toko</option><option>Dibagi</option></select></Field>
          <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><div><strong className="block text-[9px] text-[#34445f]">Pembayaran otomatis</strong><span className="text-[8px] text-[#8a98aa]">Tanpa pemilihan gateway lain</span></div><Toggle checked /></div>
          <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><div><strong className="block text-[9px] text-[#34445f]">Wallet pelanggan</strong><span className="text-[8px] text-[#8a98aa]">Tetap tampil sebagai opsi pertama</span></div><Toggle checked /></div>
        </div></Panel>
        <Panel title="URL DOKU" description="URL publik untuk konfigurasi gateway."><div className="space-y-2 p-4"><CopyUrl label="Notification URL" value="https://lfamiliastore.my.id/api/payments/doku/callback" /><CopyUrl label="Return URL" value="https://lfamiliastore.my.id/payment" /></div></Panel>
      </div>
    </div>}

    {tab === "Tampilan Halaman" && <div className="grid grid-cols-[minmax(0,1fr)_410px] gap-4">
      <Panel title="Editor Halaman Pembayaran" description="Ubah gambar, teks, warna, instruksi, dan elemen yang dilihat pelanggan."><div className="grid grid-cols-2 gap-4 p-4">
        <Field label="Judul halaman"><input className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} /></Field>
        <Field label="Warna utama"><div className="flex gap-2"><input type="color" value={accent} onChange={(e) => setAccent(e.target.value)} className="h-9 w-12 rounded border border-[#dfe5ed] bg-white p-1" /><input className={inputClass} value={accent} onChange={(e) => setAccent(e.target.value)} /></div></Field>
        <Field label="Instruksi pembayaran" wide><textarea value={instruction} onChange={(e) => setInstruction(e.target.value)} className={`${inputClass} h-20 py-2`} /></Field>
        <ImageEditor label="Logo pembayaran" value={brandImage} onChange={(event) => readImage(event, setBrandImage)} onClear={() => setBrandImage("")} />
        <ImageEditor label="Gambar / Banner pembayaran" value={heroImage} onChange={(event) => readImage(event, setHeroImage)} onClear={() => setHeroImage("")} wide />
        <div className="col-span-2 grid grid-cols-2 gap-3"><SwitchRow label="Tampilkan ringkasan pesanan" /><SwitchRow label="Tampilkan hitung mundur" /><SwitchRow label="Tampilkan panduan QRIS / VA" /><SwitchRow label="Tampilkan tombol salin kode" /></div>
      </div></Panel>
      <Panel title="Preview Halaman Pembayaran" description="Preview desktop pelanggan."><div className="bg-[#f4f7fb] p-5"><div className="overflow-hidden rounded-xl border border-[#e1e6ed] bg-white shadow-sm">{heroImage ? <img src={heroImage} alt="Preview banner pembayaran" className="h-28 w-full object-cover" /> : <div className="grid h-28 place-items-center bg-gradient-to-r from-[#0d2b57] to-[#0769e9] text-[12px] font-black text-white">LFAMILIA PAYMENT</div>}<div className="p-4"><div className="flex items-center gap-2">{brandImage ? <img src={brandImage} alt="Logo pembayaran" className="size-9 rounded object-cover" /> : <span className="grid size-9 place-items-center rounded bg-blue-50 text-[#0769e9]"><CreditCard className="size-4" /></span>}<div><strong className="block text-[13px] text-[#14213a]">{title}</strong><p className="mt-0.5 text-[8px] text-[#8190a5]">{instruction}</p></div></div><div className="mt-4 rounded-lg border border-[#e3e8ef] p-3"><div className="flex justify-between text-[9px]"><span>Mobile Legends 86 Diamonds</span><strong>Rp 20.000</strong></div><div className="mt-3 flex items-center justify-between rounded-md bg-[#f6f8fb] p-3"><div className="flex items-center gap-2"><QrCode className="size-4" style={{ color: accent }} /><span className="text-[9px] font-bold">QRIS DOKU</span></div><Status tone="green">Dipilih</Status></div><button type="button" className="mt-3 h-9 w-full rounded-md text-[10px] font-extrabold text-white" style={{ backgroundColor: accent }}>Bayar Sekarang</button></div></div></div></div></Panel>
    </div>}

    {tab === "Transaksi DOKU" && <Panel title="Transaksi DOKU Terbaru" description="Riwayat pembayaran frontend untuk menyusun tampilan sebelum backend dihubungkan." action={<div className="relative"><Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8190a5]" /><input className={`${inputClass} w-56 pl-8`} placeholder="Cari invoice atau pelanggan..." /></div>}><table className="w-full text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr>{["Invoice", "Pelanggan", "Channel", "Total", "Status", "Waktu", "Aksi"].map((head) => <th key={head} className="px-4 py-2.5">{head}</th>)}</tr></thead><tbody className="divide-y divide-[#edf0f4]">{transactions.map((row) => <tr key={row[0]} className="text-[9px] text-[#42516a]">{row.map((value, index) => <td key={value} className="px-4 py-2.5">{index === 0 ? <strong className="text-[#0769e9]">{value}</strong> : index === 4 ? <Status tone={value === "Berhasil" ? "green" : value === "Menunggu" ? "amber" : "red"}>{value}</Status> : value}</td>)}<td className="px-4"><button type="button" className={buttonClass}><Eye className="size-3.5" />Detail</button></td></tr>)}</tbody></table></Panel>}

    <Modal open={Boolean(editChannel)} title={`Edit ${editChannel?.name || "Channel"}`} description="Pengaturan tampilan channel DOKU di checkout." onClose={() => setEditChannel(null)} footer={<><button type="button" className={buttonClass} onClick={() => setEditChannel(null)}>Batal</button><button type="button" className={primaryButtonClass} onClick={() => setEditChannel(null)}>Simpan Channel</button></>}><div className="grid grid-cols-2 gap-4"><Field label="Nama tampilan"><input className={inputClass} defaultValue={editChannel?.name} /></Field><Field label="Kode channel"><input className={inputClass} defaultValue={editChannel?.id.toUpperCase()} readOnly /></Field><Field label="Biaya admin"><input className={inputClass} defaultValue={editChannel?.fee} /></Field><Field label="Urutan"><input className={inputClass} type="number" defaultValue="1" /></Field><Field label="Logo channel" wide><label className="flex h-20 cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed border-[#b9c8db] bg-[#f8fafc] text-[9px] font-bold text-[#52627a]"><ImagePlus className="size-4 text-[#0769e9]" />Pilih / ganti gambar logo<input type="file" accept="image/*" className="hidden" /></label></Field></div></Modal>
  </div>;
}

function SwitchRow({ label }: { label: string }) { const [checked, setChecked] = useState(true); return <div className="flex items-center justify-between rounded-md border border-[#e3e8ef] p-3"><span className="text-[9px] font-semibold text-[#42516a]">{label}</span><Toggle checked={checked} onChange={setChecked} /></div>; }

function ImageEditor({ label, value, onChange, onClear, wide = false }: { label: string; value: string; onChange(event: ChangeEvent<HTMLInputElement>): void; onClear(): void; wide?: boolean }) {
  return <div className={wide ? "col-span-2" : ""}><span className="mb-1.5 block text-[9px] font-bold text-[#34445f]">{label}</span><div className="flex min-h-24 items-center gap-3 rounded-md border border-dashed border-[#b9c8db] bg-[#f8fafc] p-3">{value ? <img src={value} alt={label} className="h-16 w-28 rounded object-cover" /> : <span className="grid h-16 w-28 place-items-center rounded bg-white text-[#8a98aa]"><ImagePlus className="size-5" /></span>}<div><label className={`${buttonClass} cursor-pointer`}><ImagePlus className="size-3.5" />{value ? "Ganti Gambar" : "Pilih Gambar"}<input type="file" accept="image/png,image/jpeg,image/webp" onChange={onChange} className="hidden" /></label>{value && <button type="button" onClick={onClear} className="ml-2 text-[9px] font-bold text-rose-500">Hapus</button>}<p className="mt-1.5 text-[8px] text-[#8a98aa]">PNG, JPG, WEBP · Maks. 2MB saat backend aktif</p></div></div></div>;
}
