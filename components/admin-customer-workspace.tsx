"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Ban, Eye, Search, ShieldCheck, UserPlus, Users, Wallet, WalletCards } from "lucide-react";
import { Field, MetricCard, Modal, Panel, Status, WorkspaceHeader, buttonClass, inputClass, primaryButtonClass } from "@/components/admin-workspace-ui";

type Customer = { id: string; name: string; email: string; phone: string; level: string; balance: number; orders: number; spent: string; status: "Aktif" | "Suspend"; joined: string };
type AdminAccount = { id: string; name: string; email: string; role: string; balance: number; active: boolean };
const initialCustomers: Customer[] = [
  { id: "loading", name: "Memuat pelanggan…", email: "", phone: "", level: "Member", balance: 0, orders: 0, spent: "Rp 0", status: "Aktif", joined: "" },
];

export function AdminCustomerWorkspace() {
  const [customers, setCustomers] = useState(initialCustomers);
  const [admins, setAdmins] = useState<AdminAccount[]>([]);
  const [query, setQuery] = useState("");
  const [level, setLevel] = useState("Semua Level");
  const [selected, setSelected] = useState<Customer | null>(null);
  const [balanceOpen, setBalanceOpen] = useState(false);
  const [accountType, setAccountType] = useState<"Pelanggan" | "Admin">("Pelanggan");
  const [targetId, setTargetId] = useState("");
  const [operation, setOperation] = useState<"Tambah" | "Kurangi">("Tambah");
  const [amount, setAmount] = useState("100000");
  const [note, setNote] = useState("");
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const filtered = useMemo(() => customers.filter((customer) => `${customer.name} ${customer.email} ${customer.phone}`.toLowerCase().includes(query.toLowerCase()) && (level === "Semua Level" || customer.level === level)), [customers, query, level]);

  const load = useCallback(async () => {
    const response = await fetch("/api/panel/balances", { cache: "no-store" });
    const payload = await response.json().catch(() => ({})) as {
      error?: string;
      customers?: Array<{ id: string; name: string; email: string; phone: string; balance: number; is_active: number; created_at: string; lifetime_spend: number; paid_orders: number }>;
      admins?: Array<{ id: string; name: string; email: string; role: string; balance: number; is_active: number }>;
    };
    if (!response.ok) throw new Error(payload.error || "Data pelanggan gagal dimuat.");
    const nextCustomers = (payload.customers || []).map((item) => ({ id: item.id, name: item.name, email: item.email, phone: item.phone, level: Number(item.lifetime_spend || 0) >= 10_000_000 ? "Gold" : Number(item.lifetime_spend || 0) >= 1_000_000 ? "Silver" : "Member", balance: Number(item.balance || 0), orders: Number(item.paid_orders || 0), spent: `Rp ${Number(item.lifetime_spend || 0).toLocaleString("id-ID")}`, status: item.is_active ? "Aktif" as const : "Suspend" as const, joined: item.created_at ? new Date(item.created_at).toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" }) : "-" }));
    const nextAdmins = (payload.admins || []).map((item) => ({ id: item.id, name: item.name, email: item.email, role: item.role, balance: Number(item.balance || 0), active: Boolean(item.is_active) }));
    setCustomers(nextCustomers); setAdmins(nextAdmins);
    setTargetId((current) => current || nextCustomers[0]?.id || nextAdmins[0]?.id || "");
  }, []);

  useEffect(() => { load().catch((reason) => setError(reason instanceof Error ? reason.message : "Data pelanggan gagal dimuat.")); }, [load]);

  async function adjustBalance() {
    const value = Math.max(0, Number(amount) || 0);
    setNotice(""); setError("");
    if (!targetId || value < 1 || note.trim().length < 3) { setError("Pilih akun, isi nominal, dan tulis alasan minimal 3 karakter."); return; }
    setBusy(true);
    try {
      const response = await fetch("/api/panel/balances", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ accountType: accountType === "Pelanggan" ? "customer" : "admin", targetId, operation: operation === "Tambah" ? "credit" : "debit", amount: value, reason: note }) });
      const payload = await response.json().catch(() => ({})) as { error?: string; balanceBefore?: number; balanceAfter?: number };
      if (!response.ok) throw new Error(payload.error || "Saldo gagal diperbarui.");
      await load();
      setNotice(`${operation} saldo ${accountType.toLowerCase()} Rp ${value.toLocaleString("id-ID")} berhasil. Saldo: Rp ${Number(payload.balanceBefore || 0).toLocaleString("id-ID")} → Rp ${Number(payload.balanceAfter || 0).toLocaleString("id-ID")}.`);
      setBalanceOpen(false); setNote("");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Saldo gagal diperbarui."); }
    finally { setBusy(false); }
  }

  return <div>
    <WorkspaceHeader title="Pelanggan" description="Kelola akun, level member, saldo wallet, aktivitas, dan status pelanggan." actions={<button type="button" onClick={() => setBalanceOpen(true)} className={primaryButtonClass}><WalletCards className="size-3.5" />Atur Saldo</button>} />
    {notice && <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[9px] font-semibold text-emerald-700">{notice}</div>}
    {error && <button type="button" onClick={() => setError("")} className="mb-3 w-full rounded-md border border-red-200 bg-red-50 px-3 py-2 text-left text-[9px] font-semibold text-red-700">{error}</button>}
    <div className="mb-4 grid grid-cols-5 gap-3"><MetricCard icon={Users} label="Total Pelanggan" value={customers.length.toLocaleString("id-ID")} detail="Data database aktif" /><MetricCard icon={UserPlus} label="Pelanggan Bertransaksi" value={customers.filter((item) => item.orders > 0).length.toLocaleString("id-ID")} detail="Memiliki pesanan dibayar" tone="green" /><MetricCard icon={Wallet} label="Total Saldo Wallet" value={`Rp ${customers.reduce((sum, item) => sum + item.balance, 0).toLocaleString("id-ID")}`} detail="Saldo seluruh pelanggan" tone="violet" /><MetricCard icon={ShieldCheck} label="Member Aktif" value={customers.filter((item) => item.status === "Aktif").length.toLocaleString("id-ID")} detail="Akun dapat digunakan" tone="green" /><MetricCard icon={Ban} label="Akun Suspend" value={customers.filter((item) => item.status === "Suspend").length.toLocaleString("id-ID")} detail="Perlu peninjauan" tone="red" /></div>
    <Panel title="Daftar Pelanggan" description="Cari dan kelola seluruh akun LFAMILIA." action={<div className="flex gap-2"><div className="relative"><Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8190a5]" /><input value={query} onChange={(e) => setQuery(e.target.value)} className={`${inputClass} w-60 pl-8`} placeholder="Nama, email, atau nomor HP..." /></div><select className={`${inputClass} w-32`} value={level} onChange={(e) => setLevel(e.target.value)}><option>Semua Level</option><option>Member</option><option>Silver</option><option>Gold</option></select></div>}>
      <table className="w-full text-left"><thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr>{["Pelanggan", "Kontak", "Level", "Saldo Wallet", "Pesanan", "Total Belanja", "Status", "Bergabung", "Aksi"].map((head) => <th key={head} className="px-3 py-2.5">{head}</th>)}</tr></thead><tbody className="divide-y divide-[#edf0f4]">{filtered.map((customer) => <tr key={customer.id} className="text-[9px] text-[#42516a]"><td className="px-3 py-2.5"><div className="flex items-center gap-2"><span className="grid size-8 place-items-center rounded-full bg-blue-50 font-extrabold text-[#0769e9]">{customer.name.charAt(0)}</span><strong className="text-[#23334e]">{customer.name}</strong></div></td><td className="px-3"><strong className="block font-semibold">{customer.email}</strong><span className="text-[8px] text-[#8a98aa]">{customer.phone}</span></td><td className="px-3"><Status tone={customer.level === "Gold" ? "amber" : customer.level === "Silver" ? "blue" : "gray"}>{customer.level}</Status></td><td className="px-3 font-bold text-[#23334e]">Rp {customer.balance.toLocaleString("id-ID")}</td><td className="px-3">{customer.orders}</td><td className="px-3">{customer.spent}</td><td className="px-3"><Status tone={customer.status === "Aktif" ? "green" : "red"}>{customer.status}</Status></td><td className="px-3">{customer.joined}</td><td className="px-3"><button type="button" className={buttonClass} onClick={() => setSelected(customer)}><Eye className="size-3.5" />Detail</button></td></tr>)}</tbody></table>
      <div className="border-t border-[#edf0f4] px-4 py-3 text-[9px] text-[#718198]">Menampilkan {filtered.length} dari {customers.length} pelanggan</div>
    </Panel>

    <Modal open={balanceOpen} title="Atur Saldo" description="Super Admin dapat menambah atau mengurangi saldo pelanggan maupun akun admin sendiri." onClose={() => setBalanceOpen(false)} footer={<><button type="button" onClick={() => setBalanceOpen(false)} className={buttonClass}>Batal</button><button type="button" disabled={busy} onClick={adjustBalance} className={primaryButtonClass}>{busy ? "Menyimpan..." : "Simpan Perubahan Saldo"}</button></>}><div className="grid grid-cols-2 gap-4">
      <Field label="Jenis akun"><div className="grid grid-cols-2 gap-2">{(["Pelanggan", "Admin"] as const).map((type) => <button type="button" key={type} onClick={() => { setAccountType(type); setTargetId(type === "Pelanggan" ? customers[0]?.id || "" : admins[0]?.id || ""); }} className={`h-9 rounded-md border text-[9px] font-bold ${accountType === type ? "border-[#0769e9] bg-blue-50 text-[#0769e9]" : "border-[#dfe5ed] text-[#52627a]"}`}>{type}</button>)}</div></Field>
      <Field label="Akun tujuan"><select className={inputClass} value={targetId} onChange={(e) => setTargetId(e.target.value)}>{accountType === "Pelanggan" ? customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} — Rp {customer.balance.toLocaleString("id-ID")}</option>) : admins.map((admin) => <option key={admin.id} value={admin.id}>{admin.name} ({admin.role}) — Rp {admin.balance.toLocaleString("id-ID")}</option>)}</select></Field>
      <Field label="Tindakan"><select className={inputClass} value={operation} onChange={(e) => setOperation(e.target.value as "Tambah" | "Kurangi")}><option>Tambah</option><option>Kurangi</option></select></Field>
      <Field label="Nominal"><input className={inputClass} inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value.replace(/\D/g, ""))} /></Field>
      <Field label="Catatan wajib" wide><textarea className={`${inputClass} h-20 py-2`} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Contoh: bonus kompensasi atau koreksi saldo..." /></Field>
      <div className="col-span-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-[9px] leading-4 text-amber-700">Setiap perubahan saldo langsung dicatat di ledger dan audit log: pelaku, akun tujuan, nominal, saldo sebelum/sesudah, alasan, dan waktu.</div>
    </div></Modal>

    <Modal open={Boolean(selected)} title={`Detail Pelanggan — ${selected?.name || ""}`} description="Profil, saldo, dan ringkasan transaksi pelanggan." onClose={() => setSelected(null)} width="max-w-[760px]" footer={<><button type="button" className={buttonClass} onClick={() => setSelected(null)}>Tutup</button><button type="button" className={primaryButtonClass} onClick={() => { setAccountType("Pelanggan"); setTargetId(selected?.id || ""); setBalanceOpen(true); setSelected(null); }}><WalletCards className="size-3.5" />Atur Saldo</button></>}><div className="grid grid-cols-[220px_1fr] gap-4"><div className="rounded-lg border border-[#e3e8ef] p-4"><span className="mx-auto grid size-16 place-items-center rounded-full bg-blue-50 text-xl font-black text-[#0769e9]">{selected?.name.charAt(0)}</span><strong className="mt-3 block text-center text-sm text-[#14213a]">{selected?.name}</strong><p className="text-center text-[9px] text-[#8190a5]">{selected?.email}</p><div className="mt-4 space-y-2 text-[9px]"><Info label="Level" value={selected?.level || ""} /><Info label="Saldo" value={`Rp ${(selected?.balance || 0).toLocaleString("id-ID")}`} /><Info label="Pesanan dibayar" value={String(selected?.orders || 0)} /><Info label="Total belanja" value={selected?.spent || ""} /></div></div><div className="rounded-lg border border-[#e3e8ef] p-4"><h3 className="text-[11px] font-extrabold text-[#14213a]">Ringkasan Akun</h3><p className="mt-2 text-[9px] leading-5 text-[#718198]">Perubahan saldo hanya dilakukan melalui tombol Atur Saldo. Backend menolak pengurangan yang membuat saldo negatif dan mencatat transaksi ke ledger.</p><div className="mt-4 grid grid-cols-2 gap-2"><Info label="Status" value={selected?.status || ""} /><Info label="Bergabung" value={selected?.joined || ""} /></div></div></div></Modal>
  </div>;
}

function Info({ label, value }: { label: string; value: string }) { return <div className="flex justify-between border-b border-[#edf0f4] pb-2"><span className="text-[#8190a5]">{label}</span><strong className="text-[#34445f]">{value}</strong></div>; }
