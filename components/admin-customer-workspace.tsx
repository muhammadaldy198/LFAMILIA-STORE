"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Clock3, Edit3, RefreshCw, Search, ShieldCheck, Trash2, Users, WalletCards } from "lucide-react";
import { AdminBalanceManager } from "@/components/admin-balance-manager";
import {
  Field,
  MetricCard,
  Modal,
  Panel,
  Status,
  WorkspaceHeader,
  buttonClass,
  inputClass,
  primaryButtonClass,
} from "@/components/admin-workspace-ui";

type Tier = "basic" | "gold" | "diamond" | "platinum";
type TierSetting = {
  tier: Tier;
  label: string;
  minSpend: number;
  discountPercent: number;
  benefits: string;
};
type Member = {
  id: string;
  name: string;
  email: string;
  phone: string;
  balance: number;
  isActive: boolean;
  createdAt: string;
  lifetimeSpend: number;
  paidOrders: number;
  tierProgress: number;
  tierProgressBonus: number;
  tierMode: "automatic" | "manual";
  tierOverride: Tier | null;
  tier: Tier;
  tierLabel: string;
};

type MemberPayload = {
  settings?: TierSetting[];
  members?: Member[];
  error?: string;
};

type CleanupSettings = {
  enabled: boolean;
  inactivityDays: number;
  lastRunAt: string | null;
  lastDeletedCount: number;
};

const tierOrder: Tier[] = ["basic", "gold", "diamond", "platinum"];
const tierTone: Record<Tier, "gray" | "amber" | "blue" | "violet"> = {
  basic: "gray",
  gold: "amber",
  diamond: "blue",
  platinum: "violet",
};

function rupiah(value: number) {
  return `Rp ${Number(value || 0).toLocaleString("id-ID")}`;
}

async function requestJson<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, init);
  const payload = await response.json().catch(() => ({})) as T & { error?: string };
  if (!response.ok) throw new Error(payload.error || "Permintaan gagal diproses.");
  return payload;
}

export function AdminCustomerWorkspace() {
  const [settings, setSettings] = useState<TierSetting[]>([]);
  const [members, setMembers] = useState<Member[]>([]);
  const [query, setQuery] = useState("");
  const [tier, setTier] = useState<"all" | Tier>("all");
  const [editing, setEditing] = useState<Member | null>(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [cleanupSettings, setCleanupSettings] = useState<CleanupSettings>({
    enabled: true,
    inactivityDays: 30,
    lastRunAt: null,
    lastDeletedCount: 0,
  });
  const [cleanupBusy, setCleanupBusy] = useState(false);

  const load = useCallback(async () => {
    const [payload, cleanup] = await Promise.all([
      requestJson<MemberPayload>("/api/panel/members", { cache: "no-store" }),
      requestJson<{ settings: CleanupSettings }>("/api/panel/customer-cleanup", { cache: "no-store" }),
    ]);
    setSettings(payload.settings ?? []);
    setMembers(payload.members ?? []);
    setCleanupSettings(cleanup.settings);
    setError("");
  }, []);

  useEffect(() => {
    let cancelled = false;
    void Promise.all([
      requestJson<MemberPayload>("/api/panel/members", { cache: "no-store" }),
      requestJson<{ settings: CleanupSettings }>("/api/panel/customer-cleanup", { cache: "no-store" }),
    ])
      .then(([payload, cleanup]) => {
        if (cancelled) return;
        setSettings(payload.settings ?? []);
        setMembers(payload.members ?? []);
        setCleanupSettings(cleanup.settings);
        setError("");
      })
      .catch((reason) => {
        if (cancelled) return;
        setError(reason instanceof Error ? reason.message : "Data member gagal dimuat.");
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return members.filter((member) => {
      const text = `${member.name} ${member.email} ${member.phone}`.toLowerCase();
      return (!needle || text.includes(needle)) && (tier === "all" || member.tier === tier);
    });
  }, [members, query, tier]);

  async function saveTierSettings() {
    setBusy(true); setError(""); setNotice("");
    try {
      const payload = await requestJson<{ settings: TierSetting[] }>("/api/panel/members", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          settings: tierOrder.map((key) => {
            const current = settings.find((item) => item.tier === key);
            return {
              tier: key,
              discountPercent: Number(current?.discountPercent ?? 0),
              benefits: current?.benefits ?? "",
            };
          }),
        }),
      });
      setSettings(payload.settings);
      setNotice("Pengaturan membership berhasil disimpan.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan membership gagal disimpan.");
    } finally { setBusy(false); }
  }

  async function saveMember(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editing) return;
    const data = new FormData(event.currentTarget);
    const role = String(data.get("role") || "automatic") as "automatic" | Tier;
    const addBalance = Math.max(0, Number(data.get("addBalance") || 0));
    const reason = String(data.get("reason") || "").trim();
    if (addBalance > 0 && reason.length < 3) {
      setError("Alasan minimal 3 karakter wajib diisi jika menambah saldo.");
      return;
    }
    setBusy(true); setError(""); setNotice("");
    try {
      const payload = await requestJson<{ members: Member[] }>("/api/panel/members", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ customerId: editing.id, role, addBalance, reason: reason || undefined }),
      });
      setMembers(payload.members);
      setEditing(null);
      setNotice("Member berhasil diperbarui.");
    } catch (reasonValue) {
      setError(reasonValue instanceof Error ? reasonValue.message : "Member gagal diperbarui.");
    } finally { setBusy(false); }
  }

  function updateSetting(key: Tier, patch: Partial<TierSetting>) {
    setSettings((current) => current.map((item) => item.tier === key ? { ...item, ...patch } : item));
  }

  async function saveCleanupSettings() {
    setCleanupBusy(true); setError(""); setNotice("");
    try {
      const payload = await requestJson<{ settings: CleanupSettings }>("/api/panel/customer-cleanup", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          enabled: cleanupSettings.enabled,
          inactivityDays: cleanupSettings.inactivityDays,
        }),
      });
      setCleanupSettings(payload.settings);
      setNotice("Pengaturan pembersihan akun berhasil disimpan.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan pembersihan akun gagal disimpan.");
    } finally { setCleanupBusy(false); }
  }

  async function runCleanupNow() {
    if (!window.confirm("Jalankan pembersihan sekarang? Hanya akun saldo Rp0, tanpa riwayat transaksi, dan tidak login sesuai batas hari yang akan dihapus.")) return;
    setCleanupBusy(true); setError(""); setNotice("");
    try {
      const payload = await requestJson<{ deleted: number; settings: CleanupSettings }>("/api/panel/customer-cleanup", { method: "POST" });
      setCleanupSettings(payload.settings);
      await load();
      setNotice(`Pembersihan selesai. ${payload.deleted} akun kosong dihapus permanen.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pembersihan akun gagal dijalankan.");
    } finally { setCleanupBusy(false); }
  }

  async function deleteMember(member: Member) {
    const confirmation = window.prompt(
      `Hapus permanen akun ${member.email}?\n\nHanya bisa jika saldo Rp0 dan belum pernah punya pesanan/top up/transaksi saldo.\nKetik HAPUS untuk melanjutkan.`,
    );
    if (confirmation !== "HAPUS") return;
    setBusy(true); setError(""); setNotice("");
    try {
      const payload = await requestJson<{ members: Member[] }>(`/api/panel/members?id=${encodeURIComponent(member.id)}`, { method: "DELETE" });
      setMembers(payload.members);
      if (editing?.id === member.id) setEditing(null);
      setNotice("Akun pelanggan kosong berhasil dihapus permanen.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Akun pelanggan gagal dihapus.");
    } finally { setBusy(false); }
  }

  return <div>
    <WorkspaceHeader
      title="Pelanggan"
      description="Kelola membership BASIC, GOLD, DIAMOND, PLATINUM, saldo, dan status pelanggan."
      actions={<button type="button" onClick={() => void load()} className={buttonClass}><RefreshCw className="size-3.5" />Refresh</button>}
    />
    {notice && <button type="button" onClick={() => setNotice("")} className="mb-3 w-full rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-left text-[9px] font-semibold text-emerald-700">{notice}</button>}
    {error && <button type="button" onClick={() => setError("")} className="mb-3 w-full rounded-md border border-red-200 bg-red-50 px-3 py-2 text-left text-[9px] font-semibold text-red-700">{error}</button>}

    <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
      <MetricCard icon={Users} label="Total Pelanggan" value={members.length.toLocaleString("id-ID")} detail="Akun customer" />
      <MetricCard icon={ShieldCheck} label="BASIC" value={String(members.filter((item) => item.tier === "basic").length)} detail="Tier awal" />
      <MetricCard icon={ShieldCheck} label="GOLD" value={String(members.filter((item) => item.tier === "gold").length)} detail="Member Gold" tone="amber" />
      <MetricCard icon={ShieldCheck} label="DIAMOND" value={String(members.filter((item) => item.tier === "diamond").length)} detail="Member Diamond" tone="blue" />
      <MetricCard icon={WalletCards} label="Total Saldo" value={rupiah(members.reduce((sum, item) => sum + item.balance, 0))} detail="Saldo pelanggan" tone="violet" />
    </div>

    <Panel title="Pengaturan Membership" description="Diskon dan benefit tier dipakai backend saat menghitung promo member." action={<button type="button" disabled={busy || settings.length !== 4} onClick={() => void saveTierSettings()} className={primaryButtonClass}>{busy ? "Menyimpan..." : "Simpan Membership"}</button>}>
      <div className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4">
        {tierOrder.map((key) => {
          const item = settings.find((entry) => entry.tier === key);
          if (!item) return <div key={key} className="h-28 rounded-md border border-dashed border-[#dfe5ed]" />;
          return <div key={key} className="rounded-md border border-[#e3e8ef] p-3">
            <div className="flex items-center justify-between"><strong className="text-[10px] text-[#14213a]">{item.label}</strong><Status tone={tierTone[key]}>{rupiah(item.minSpend)}+</Status></div>
            <label className="mt-3 block text-[8px] font-bold text-[#60718a]">Diskon (%)<input type="number" min="0" max="100" step="0.01" className={`${inputClass} mt-1`} value={item.discountPercent} onChange={(event) => updateSetting(key, { discountPercent: Number(event.target.value) })} /></label>
            <label className="mt-2 block text-[8px] font-bold text-[#60718a]">Benefit<textarea className={`${inputClass} mt-1 h-16 py-2`} value={item.benefits} onChange={(event) => updateSetting(key, { benefits: event.target.value })} placeholder="Benefit tier..." /></label>
          </div>;
        })}
      </div>
    </Panel>

    <Panel
      title="Pembersihan Akun Kosong"
      description="Akun tanpa transaksi tidak akan menumpuk. Scheduler harian hanya menghapus akun saldo Rp0, tanpa pesanan/top up/transaksi saldo, dan tidak login selama batas waktu."
      className="mt-4"
      action={<div className="flex gap-2"><button type="button" disabled={cleanupBusy} onClick={() => void runCleanupNow()} className={buttonClass}><Trash2 className="size-3.5" />Jalankan Sekarang</button><button type="button" disabled={cleanupBusy} onClick={() => void saveCleanupSettings()} className={primaryButtonClass}>{cleanupBusy ? "Memproses..." : "Simpan"}</button></div>}
    >
      <div className="grid grid-cols-1 gap-4 p-4 md:grid-cols-[1fr_220px_1fr]">
        <label className="flex items-center justify-between rounded-md border border-[#e3e8ef] px-3 py-3 text-[9px] font-semibold text-[#42516a]">
          <span><strong className="block text-[10px] text-[#14213a]">Hapus otomatis</strong><span className="mt-1 block text-[8px] text-[#8190a5]">Aktif untuk akun customer kosong.</span></span>
          <input type="checkbox" checked={cleanupSettings.enabled} onChange={(event) => setCleanupSettings((current) => ({ ...current, enabled: event.target.checked }))} className="size-4" />
        </label>
        <Field label="Tidak login selama">
          <div className="relative"><input type="number" min="7" max="365" value={cleanupSettings.inactivityDays} onChange={(event) => setCleanupSettings((current) => ({ ...current, inactivityDays: Math.min(365, Math.max(7, Number(event.target.value) || 30)) }))} className={inputClass} /><span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[8px] text-[#8190a5]">hari</span></div>
        </Field>
        <div className="flex items-center gap-3 rounded-md border border-[#e3e8ef] px-3 py-3">
          <Clock3 className="size-4 text-[#64748b]" />
          <div><strong className="block text-[9px] text-[#14213a]">Pembersihan terakhir</strong><span className="text-[8px] text-[#8190a5]">{cleanupSettings.lastRunAt ? new Date(cleanupSettings.lastRunAt).toLocaleString("id-ID") : "Belum pernah"} · {cleanupSettings.lastDeletedCount} akun dihapus</span></div>
        </div>
      </div>
      <div className="border-t border-[#edf0f4] px-4 py-3 text-[8px] text-[#718198]">Akun Admin/Staff/Super Admin selalu dikecualikan. Akun dengan riwayat transaksi tidak pernah dihapus otomatis.</div>
    </Panel>

    <Panel
      title="Daftar Pelanggan"
      description="Tier otomatis mengikuti total belanja; Super Admin dapat memberi override manual bila diperlukan."
      className="mt-4"
      action={<div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row"><div className="relative"><Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8190a5]" /><input value={query} onChange={(event) => setQuery(event.target.value)} className={`${inputClass} w-full pl-8 sm:w-60`} placeholder="Nama, email, atau nomor HP..." /></div><select className={`${inputClass} w-full sm:w-36`} value={tier} onChange={(event) => setTier(event.target.value as "all" | Tier)}><option value="all">Semua Tier</option><option value="basic">BASIC</option><option value="gold">GOLD</option><option value="diamond">DIAMOND</option><option value="platinum">PLATINUM</option></select></div>}
    >
      <div className="overflow-x-auto">
        <table className="w-full min-w-[980px] text-left">
          <thead className="bg-[#f6f8fb] text-[8px] uppercase text-[#718198]"><tr>{["Pelanggan", "Kontak", "Tier", "Mode", "Saldo", "Pesanan", "Total Belanja", "Status", "Aksi"].map((head) => <th key={head} className="px-3 py-2.5">{head}</th>)}</tr></thead>
          <tbody className="divide-y divide-[#edf0f4]">
            {filtered.map((member) => <tr key={member.id} className="text-[9px] text-[#42516a]">
              <td className="px-3 py-2.5"><strong className="text-[#23334e]">{member.name}</strong></td>
              <td className="px-3"><strong className="block font-semibold">{member.email}</strong><span className="text-[8px] text-[#8a98aa]">{member.phone}</span></td>
              <td className="px-3"><Status tone={tierTone[member.tier]}>{member.tierLabel}</Status></td>
              <td className="px-3">{member.tierMode === "manual" ? "Manual" : "Otomatis"}</td>
              <td className="px-3 font-bold text-[#23334e]">{rupiah(member.balance)}</td>
              <td className="px-3">{member.paidOrders}</td>
              <td className="px-3">{rupiah(member.lifetimeSpend)}</td>
              <td className="px-3"><Status tone={member.isActive ? "green" : "red"}>{member.isActive ? "Aktif" : "Suspend"}</Status></td>
              <td className="px-3"><div className="flex gap-1"><button type="button" className={buttonClass} onClick={() => setEditing(member)}><Edit3 className="size-3.5" />Kelola</button><button type="button" title="Hapus permanen akun kosong" className={`${buttonClass} px-2 text-rose-500`} onClick={() => void deleteMember(member)}><Trash2 className="size-3.5" /></button></div></td>
            </tr>)}
          </tbody>
        </table>
      </div>
      <div className="border-t border-[#edf0f4] px-4 py-3 text-[9px] text-[#718198]">Menampilkan {filtered.length} dari {members.length} pelanggan</div>
    </Panel>

    <div className="mt-4"><AdminBalanceManager /></div>

    <Modal open={Boolean(editing)} title={`Kelola Member — ${editing?.name ?? ""}`} description="Atur tier otomatis/manual dan tambah saldo pelanggan." onClose={() => setEditing(null)} footer={null}>
      {editing && <form onSubmit={saveMember}>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Role membership"><select name="role" defaultValue={editing.tierMode === "manual" ? editing.tierOverride ?? editing.tier : "automatic"} className={inputClass}><option value="automatic">Otomatis sesuai total belanja</option><option value="basic">BASIC</option><option value="gold">GOLD</option><option value="diamond">DIAMOND</option><option value="platinum">PLATINUM</option></select></Field>
          <Field label="Saldo saat ini"><input disabled value={rupiah(editing.balance)} className={inputClass} /></Field>
          <Field label="Tambah saldo"><input name="addBalance" type="number" min="0" max="100000000" defaultValue="0" className={inputClass} /></Field>
          <Field label="Alasan penambahan saldo"><input name="reason" className={inputClass} placeholder="Contoh: kompensasi CS" /></Field>
          <div className="col-span-2 rounded-md border border-blue-100 bg-blue-50 p-3 text-[9px] leading-4 text-blue-700">Tier otomatis memakai lifetime spend. Jika kembali ke otomatis, override manual dan progress bonus lama dibersihkan oleh backend.</div>
        </div>
        <div className="mt-4 flex justify-end gap-2"><button type="button" className={buttonClass} onClick={() => setEditing(null)}>Batal</button><button type="submit" disabled={busy} className={primaryButtonClass}>{busy ? "Menyimpan..." : "Simpan Member"}</button></div>
      </form>}
    </Modal>
  </div>;
}
