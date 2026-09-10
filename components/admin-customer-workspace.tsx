"use client";

import { useMemo, useState } from "react";
import { Ban, Eye, Mail, Search, ShieldCheck, UserPlus, Users, Wallet, WalletCards } from "lucide-react";
import { Field, MetricCard, Modal, Panel, Status, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

type Customer = { id: number; name: string; email: string; phone: string; level: string; balance: number; orders: number; spent: string; status: "Aktif" | "Suspend"; joined: string };
const initialCustomers: Customer[] = [
  { id: 1, name: "Rizky Pratama", email: "rizky@gmail.com", phone: "0857••••1123", level: "Gold", balance: 245000, orders: 42, spent: "Rp 2.840.000", status: "Aktif", joined: "12 Jan 2025" },
  { id: 2, name: "Siti Aulia", email: "sitiaulia@gmail.com", phone: "0812••••7789", level: "Silver", balance: 86000, orders: 18, spent: "Rp 980.000", status: "Aktif", joined: "20 Feb 2025" },
  { id: 3, name: "Budi Santoso", email: "budi@gmail.com", phone: "0813••••4455", level: "Member", balance: 12500, orders: 8, spent: "Rp 425.000", status: "Aktif", joined: "08 Mar 2025" },
  { id: 4, name: "Andi Saputra", email: "andi@gmail.com", phone: "0821••••6677", level: "Gold", balance: 510000, orders: 56, spent: "Rp 4.120.000", status: "Aktif", joined: "02 Jan 2025" },
  { id: 5, name: "Maya Sari", email: "maya@gmail.com", phone: "0819••••3344", level: "Silver", balance: 0, orders: 11, spent: "Rp 710.000", status: "Suspend", joined: "15 Mar 2025" },
  { id: 6, name: "Dimas Kurniawan", email: "dimas@gmail.com", phone: "0856••••9988", level: "Member", balance: 34000, orders: 4, spent: "Rp 220.000", status: "Aktif", joined: "18 Apr 2025" },
];

export function AdminCustomerWorkspace() {
  const [customers, setCustomers] = useState(initialCustomers);
  const [query, setQuery] = useState("");
  const [level, setLevel] = useState("Semua Level");
  const [selected, setSelected] = useState<Customer | null>(null);
  const [balanceOpen, setBalanceOpen] = useState(false);
  const [accountType, setAccountType] = useState<"Pelanggan" | "Admin">("Pelanggan");
  const [targetId, setTargetId] = useState("1");
  const [operation, setOperation] = useState<"Tambah" | "Kurangi">("Tambah");
  const [amount, setAmount] = useState("100000");
  const [note, setNote] = useState("");
  const [notice, setNotice] = useState("");
  const filtered = useMemo(() => customers.filter((customer) => `${customer.name} ${customer.email} ${customer.phone}`.toLowerCase().includes(query.toLowerCase()) && (level === "Semua Level" || customer.level === level)), [customers, query, level]);

  function adjustBalance() {
    const value = Math.max(0, Number(amount) || 0);
    if (accountType === "Pelanggan") setCustomers((current) => current.map((customer) => customer.id === Number(targetId) ? { ...customer, balance: Math.max(0, customer.balance + (operation === "Tambah" ? value : -value)) } : customer));
    setNotice(`${operation} saldo ${accountType.toLowerCase()} Rp ${value.toLocaleString("id-ID")} dicatat pada UI.`);
    setBalanceOpen(false);
  }

  return <div>
    <WorkspaceHeader title="Pelanggan" description="Kelola akun, level member, saldo wallet, aktivitas, dan status pelanggan." actions={<><button type="button" onClick={() => setBalanceOpen(true)} className={primaryButtonClass}><WalletCards className="size-3.5" />Atur Saldo</button><button type="button" className={buttonClass}><UserPlus className="size-3.5" />Tambah Pelanggan</button></>} />
    {notice && <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] font-semibold text-emerald-700">{notice}</div>}
    <div className="mb-4 grid grid-cols-5 gap-3"><MetricCard icon={Users} label="Total Pelanggan" value="2.482" detail="+8,1% bulan ini" /><MetricCard icon={UserPlus} label="Pelanggan Baru" value="124" detail="30 hari terakhir" tone="green" /><MetricCard icon={Wallet} label="Total Saldo Wallet" value="Rp 18.450.000" detail="Saldo seluruh akun" tone="violet" /><MetricCard icon={ShieldCheck} label="Member Aktif" value="2.451" detail="98,7% dari total" tone="green" /><MetricCard icon={Ban} label="Akun Suspend" value="31" detail="Perlu peninjauan" tone="red" /></div>
    <Panel title="Daftar Pelanggan" description="Cari dan kelola seluruh akun LFAMILIA." action={<div className="flex gap-2"><div className="relative"><Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8190a5]" /><input value={query} onChange={(e) => setQuery(e.target.value)} className={`${inputClass} w-60 pl-8`} placeholder="Nama, email, atau nomor HP..." /></div><select className={`${inputClass} w-32`} value={level} onChange={(e) => setLevel(e.target.value)}><option>Semua Level</option><option>Member</option><option>Silver</option><option>Gold</option></select></div>}>
      <table className="w-full text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr>{["Pelanggan", "Kontak", "Level", "Saldo Wallet", "Pesanan", "Total Belanja", "Status", "Bergabung", "Aksi"].map((head) => <th key={head} className="px-3 py-2.5">{head}</th>)}</tr></thead><tbody className="divide-y divide-[#edf0f4]">{filtered.map((customer) => <tr key={customer.id} className="text-[9px] text-[#42516a]"><td className="px-3 py-2.5"><div className="flex items-center gap-2"><span className="grid size-8 place-items-center rounded-full bg-blue-50 font-extrabold text-[#0769e9]">{customer.name.charAt(0)}</span><strong className="text-[#23334e]">{customer.name}</strong></div></td><td className="px-3"><strong className="block font-semibold">{customer.email}</strong><span className="text-[8px] text-[#8a98aa]">{customer.phone}</span></td><td className="px-3"><Status tone={customer.level === "Gold" ? "amber" : customer.level === "Silver" ? "blue" : "gray"}>{customer.level}</Status></td><td className="px-3 font-bold text-[#23334e]">Rp {customer.balance.toLocaleString("id-ID")}</td><td className="px-3">{customer.orders}</td><td className="px-3">{customer.spent}</td><td className="px-3"><Status tone={customer.status === "Aktif" ? "green" : "red"}>{customer.status}</Status></td><td className="px-3">{customer.joined}</td><td className="px-3"><div className="flex gap-1"><button type="button" className={buttonClass} onClick={() => setSelected(customer)}><Eye className="size-3.5" />Detail</button><button type="button" className={`${buttonClass} px-2`} title="Kirim pesan"><Mail className="size-3.5" /></button></div></td></tr>)}</tbody></table>
      <div className="flex items-center justify-between border-t border-[#edf0f4] px-4 py-3 text-[9px] text-[#718198]"><span>Menampilkan {filtered.length} dari 2.482 pelanggan</span><div className="flex gap-1"><button className={buttonClass}>1</button><button className={buttonClass}>2</button><button className={buttonClass}>3</button><button className={buttonClass}>… 249</button></div></div>
    </Panel>

    <Modal open={balanceOpen} title="Atur Saldo" description="Super Admin dapat menambah atau mengurangi saldo pelanggan maupun akun admin sendiri." onClose={() => setBalanceOpen(false)} footer={<><button type="button" onClick={() => setBalanceOpen(false)} className={buttonClass}>Batal</button><button type="button" onClick={adjustBalance} className={primaryButtonClass}>Simpan Perubahan Saldo</button></>}><div className="grid grid-cols-2 gap-4">
      <Field label="Jenis akun"><div className="grid grid-cols-2 gap-2">{(["Pelanggan", "Admin"] as const).map((type) => <button type="button" key={type} onClick={() => setAccountType(type)} className={`h-9 rounded-md border text-[9px] font-bold ${accountType === type ? "border-[#0769e9] bg-blue-50 text-[#0769e9]" : "border-[#dfe5ed] text-[#52627a]"}`}>{type}</button>)}</div></Field>
      <Field label="Akun tujuan"><select className={inputClass} value={targetId} onChange={(e) => setTargetId(e.target.value)}>{accountType === "Pelanggan" ? customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} — Rp {customer.balance.toLocaleString("id-ID")}</option>) : <><option value="owner">Admin / Super Admin</option><option value="staff01">Staff Operasional</option></>}</select></Field>
      <Field label="Tindakan"><select className={inputClass} value={operation} onChange={(e) => setOperation(e.target.value as "Tambah" | "Kurangi")}><option>Tambah</option><option>Kurangi</option></select></Field>
      <Field label="Nominal"><input className={inputClass} inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value.replace(/\D/g, ""))} /></Field>
      <Field label="Catatan wajib" wide><textarea className={`${inputClass} h-20 py-2`} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Contoh: bonus kompensasi atau koreksi saldo..." /></Field>
      <div className="col-span-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-[9px] leading-4 text-amber-700">Setiap perubahan saldo nantinya wajib tercatat di audit log: pelaku, akun tujuan, nominal, saldo sebelum/sesudah, alasan, dan waktu.</div>
    </div></Modal>

    <Modal open={Boolean(selected)} title={`Detail Pelanggan — ${selected?.name || ""}`} description="Profil, saldo, histori transaksi, dan tindakan akun." onClose={() => setSelected(null)} width="max-w-[760px]" footer={<><button type="button" className={buttonClass} onClick={() => setSelected(null)}>Tutup</button><button type="button" className={primaryButtonClass} onClick={() => { setTargetId(String(selected?.id || 1)); setBalanceOpen(true); setSelected(null); }}><WalletCards className="size-3.5" />Atur Saldo</button></>}><div className="grid grid-cols-[220px_1fr] gap-4"><div className="rounded-lg border border-[#e3e8ef] p-4"><span className="mx-auto grid size-16 place-items-center rounded-full bg-blue-50 text-xl font-black text-[#0769e9]">{selected?.name.charAt(0)}</span><strong className="mt-3 block text-center text-sm text-[#14213a]">{selected?.name}</strong><p className="text-center text-[9px] text-[#8190a5]">{selected?.email}</p><div className="mt-4 space-y-2 text-[9px]"><Info label="Level" value={selected?.level || ""} /><Info label="Saldo" value={`Rp ${(selected?.balance || 0).toLocaleString("id-ID")}`} /><Info label="Pesanan" value={String(selected?.orders || 0)} /><Info label="Total belanja" value={selected?.spent || ""} /></div></div><div><h3 className="text-[11px] font-extrabold text-[#14213a]">Aktivitas Terbaru</h3><div className="mt-2 divide-y divide-[#edf0f4] rounded-lg border border-[#e3e8ef]">{["Top up Mobile Legends — Rp 20.000", "Saldo wallet ditambah — Rp 100.000", "Pembayaran QRIS berhasil", "Login akun pelanggan"].map((item, index) => <div key={item} className="flex justify-between px-3 py-3 text-[9px]"><span className="font-semibold text-[#42516a]">{item}</span><span className="text-[#8a98aa]">{index + 2} jam lalu</span></div>)}</div><div className="mt-4 grid grid-cols-3 gap-2"><button className={buttonClass}>Reset Password</button><button className={buttonClass}>Kirim Pesan</button><button className={`${buttonClass} text-rose-600`}>{selected?.status === "Suspend" ? "Aktifkan" : "Suspend"}</button></div></div></div></Modal>
  </div>;
}

function Info({ label, value }: { label: string; value: string }) { return <div className="flex justify-between border-b border-[#edf0f4] pb-2"><span className="text-[#8190a5]">{label}</span><strong className="text-[#34445f]">{value}</strong></div>; }
