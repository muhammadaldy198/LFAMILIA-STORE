"use client";

import { useCallback, useEffect, useState } from "react";
import { LoaderCircle, Save, ShieldCheck, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { formatRupiah } from "@/lib/store-data";
import type { MemberTier, MemberTierSetting } from "@/lib/server/member-tiers";

type MemberRow = {
  id: string;
  name: string;
  email: string;
  phone: string;
  balance: number;
  isActive: boolean;
  createdAt: string;
  lifetimeSpend: number;
  paidOrders: number;
  tier: MemberTier;
  tierLabel: string;
};

const tierDescriptions: Record<MemberTier, string> = {
  basic: "Akun baru dan member dengan total belanja di bawah Rp1 juta.",
  gold: "Otomatis mulai total belanja Rp1 juta.",
  diamond: "Otomatis mulai total belanja Rp10 juta.",
  platinum: "Otomatis mulai total belanja Rp50 juta.",
};

export function AdminMemberManager() {
  const [settings, setSettings] = useState<MemberTierSetting[]>([]);
  const [members, setMembers] = useState<MemberRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/members", { cache: "no-store" });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Data member gagal dimuat.");
      setSettings(data.settings ?? []);
      setMembers(data.members ?? []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Data member gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function save() {
    setSaving(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/panel/members", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          settings: settings.map((item) => ({
            tier: item.tier,
            discountPercent: Number(item.discountPercent) || 0,
            benefits: item.benefits,
          })),
        }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Privilege member gagal disimpan.");
      setSettings(data.settings ?? settings);
      setMessage("Privilege member berhasil disimpan.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Privilege member gagal disimpan.");
    } finally {
      setSaving(false);
    }
  }

  if (loading)
    return (
      <div className="flex min-h-56 items-center justify-center text-xs text-white/35">
        <LoaderCircle className="mr-2 size-4 animate-spin" />Memuat member…
      </div>
    );

  return (
    <div className="space-y-5">
      {message && <div className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">{message}</div>}
      {error && <div className="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}

      <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <div className="flex items-center gap-2"><ShieldCheck className="size-4 text-[#cfff72]" /><h3 className="font-bold">Role & privilege member</h3></div>
            <p className="mt-1 max-w-2xl text-[10px] leading-5 text-white/35">Tier dihitung otomatis dari total nilai order yang sudah lunas dari semua metode pembayaran. Diskon default 0% sehingga harga tidak berubah sampai Pemilik mengaturnya.</p>
          </div>
          <Button type="button" disabled={saving} onClick={() => void save()} className="bg-[#b9ff35] text-xs font-black text-[#091006]">
            {saving ? <LoaderCircle className="size-4 animate-spin" /> : <Save className="size-4" />}Simpan privilege
          </Button>
        </div>

        <div className="mt-5 grid gap-3 lg:grid-cols-2">
          {settings.map((item) => (
            <article key={item.tier} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <span className="text-[9px] font-black uppercase tracking-[0.16em] text-[#cfff72]">{item.label}</span>
                  <p className="mt-1 text-[10px] leading-5 text-white/35">{tierDescriptions[item.tier]}</p>
                </div>
                <span className="rounded-full bg-white/[0.05] px-2 py-1 text-[9px] text-white/45">{item.minSpend === 0 ? "Default" : `≥ ${formatRupiah(item.minSpend)}`}</span>
              </div>
              <div className="mt-4 grid gap-3 sm:grid-cols-[150px_1fr]">
                <label>
                  <span className="field-label">Diskon member (%)</span>
                  <Input type="number" min={0} max={100} step="0.01" value={item.discountPercent} onChange={(event) => setSettings((current) => current.map((setting) => setting.tier === item.tier ? { ...setting, discountPercent: Number(event.target.value) } : setting))} className="admin-input" />
                </label>
                <label>
                  <span className="field-label">Privilege / benefit</span>
                  <Textarea value={item.benefits} onChange={(event) => setSettings((current) => current.map((setting) => setting.tier === item.tier ? { ...setting, benefits: event.target.value } : setting))} className="min-h-20 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" placeholder="Contoh: prioritas support, bonus promo khusus, akses event…" />
                </label>
              </div>
            </article>
          ))}
        </div>
      </section>

      <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5">
        <div className="flex items-center justify-between gap-3">
          <div><div className="flex items-center gap-2"><Users className="size-4 text-[#cfff72]" /><h3 className="font-bold">Daftar member</h3></div><p className="mt-1 text-[10px] text-white/30">Role diperbarui otomatis dari lifetime spending order berstatus paid.</p></div>
          <span className="rounded-full bg-white/[0.05] px-2.5 py-1 text-[9px] text-white/40">{members.length} member</span>
        </div>
        <div className="mt-4 overflow-x-auto rounded-xl border border-white/[0.08]">
          <table className="w-full min-w-[760px] text-left text-xs">
            <thead className="bg-white/[0.03] text-[9px] uppercase tracking-wider text-white/35"><tr><th className="px-3 py-2.5">Member</th><th className="px-3 py-2.5">Role</th><th className="px-3 py-2.5">Lifetime belanja</th><th className="px-3 py-2.5">Order lunas</th><th className="px-3 py-2.5">Saldo</th><th className="px-3 py-2.5">Status</th></tr></thead>
            <tbody>
              {members.map((member) => (
                <tr key={member.id} className="border-t border-white/[0.06]">
                  <td className="px-3 py-3"><strong className="block text-xs">{member.name}</strong><span className="mt-1 block text-[9px] text-white/30">{member.email}</span></td>
                  <td className="px-3 py-3"><span className="rounded-full bg-[#b9ff35]/10 px-2 py-1 text-[9px] font-black text-[#d8ff8d]">{member.tierLabel}</span></td>
                  <td className="px-3 py-3 font-semibold">{formatRupiah(member.lifetimeSpend)}</td>
                  <td className="px-3 py-3 text-white/55">{member.paidOrders}</td>
                  <td className="px-3 py-3 text-white/55">{formatRupiah(member.balance)}</td>
                  <td className="px-3 py-3"><span className={member.isActive ? "text-[#d8ff8d]" : "text-red-300"}>{member.isActive ? "Aktif" : "Nonaktif"}</span></td>
                </tr>
              ))}
              {!members.length && <tr><td colSpan={6} className="px-3 py-8 text-center text-xs text-white/30">Belum ada member.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
