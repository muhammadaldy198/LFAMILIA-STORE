"use client";

import { useCallback, useEffect, useState } from "react";
import { Edit3, LoaderCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { formatRupiah } from "@/lib/store-data";
import type { MemberTier, MemberTierSetting } from "@/lib/server/member-tiers";

type MemberRow = {
  id: string; name: string; email: string; phone: string; balance: number; isActive: boolean;
  createdAt: string; lifetimeSpend: number; paidOrders: number; tierProgress: number;
  tierProgressBonus: number; tierMode: "automatic" | "manual"; tierOverride: MemberTier | null;
  tier: MemberTier; tierLabel: string;
};

export function AdminMemberManager({
  view = "all",
}: {
  view?: "all" | "privileges" | "customers";
}) {
  const [settings, setSettings] = useState<MemberTierSetting[]>([]);
  const [members, setMembers] = useState<MemberRow[]>([]);
  const [tierEdit, setTierEdit] = useState<MemberTier | null>(null);
  const [memberEdit, setMemberEdit] = useState<MemberRow | null>(null);
  const [role, setRole] = useState<"automatic" | MemberTier>("automatic");
  const [addBalance, setAddBalance] = useState("");
  const [reason, setReason] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/panel/members", { cache: "no-store" });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Data member gagal dimuat.");
      setSettings(data.settings ?? []); setMembers(data.members ?? []);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Data member gagal dimuat."); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  function updateTier(tier: MemberTier, patch: Partial<MemberTierSetting>) {
    setSettings((current) => current.map((item) => item.tier === tier ? { ...item, ...patch } : item));
  }

  async function savePrivileges() {
    setSaving(true); setError("");
    try {
      const response = await fetch("/api/panel/members", {
        method: "PUT", headers: { "content-type": "application/json" },
        body: JSON.stringify({ settings: settings.map((item) => ({ tier: item.tier, discountPercent: Number(item.discountPercent) || 0, benefits: item.benefits })) }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Privilege member gagal disimpan.");
      setSettings(data.settings ?? settings); setTierEdit(null); setMessage("Privilege member berhasil disimpan.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Privilege member gagal disimpan."); }
    finally { setSaving(false); }
  }

  function openMember(member: MemberRow) {
    setMemberEdit(member);
    setRole(member.tierMode === "manual" && member.tierOverride ? member.tierOverride : "automatic");
    setAddBalance(""); setReason("");
  }

  async function saveMember() {
    if (!memberEdit) return;
    setSaving(true); setError("");
    try {
      const response = await fetch("/api/panel/members", {
        method: "PATCH", headers: { "content-type": "application/json" },
        body: JSON.stringify({ customerId: memberEdit.id, role, addBalance: Number(addBalance) || 0, reason: reason.trim() || undefined }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Member gagal diperbarui.");
      setMembers(data.members ?? members); setMemberEdit(null); setMessage("Role dan saldo member berhasil diperbarui.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Member gagal diperbarui."); }
    finally { setSaving(false); }
  }

  if (loading) return <div className="flex min-h-48 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat member…</div>;
  const editingTier = settings.find((item) => item.tier === tierEdit) ?? null;

  return <div className="space-y-4">
    {message && <div className="rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] p-3 text-xs text-[#d8ff8d]">{message}</div>}
    {error && <div className="rounded-lg border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-200">{error}</div>}

    {view !== "customers" && <section>
      <div className="mb-2"><h3 className="text-xs font-bold">Role & privilege</h3><p className="mt-1 text-[9px] text-white/30">Klik pensil untuk mengatur diskon atau benefit.</p></div>
      <div className="overflow-x-auto rounded-lg border border-white/[0.08]"><table className="w-full min-w-[620px] text-left text-xs"><thead className="bg-white/[0.025] text-[9px] uppercase text-white/35"><tr><th className="px-3 py-2">Role</th><th className="px-3 py-2">Minimal progres</th><th className="px-3 py-2">Diskon</th><th className="px-3 py-2">Benefit</th><th className="px-3 py-2 text-right">Aksi</th></tr></thead><tbody>{settings.map((item)=><tr key={item.tier} className="border-t border-white/[0.06]"><td className="px-3 py-2 font-black text-[#d8ff8d]">{item.label}</td><td className="px-3 py-2 text-white/45">{item.minSpend ? formatRupiah(item.minSpend) : "Default"}</td><td className="px-3 py-2 text-white/55">{item.discountPercent}%</td><td className="max-w-64 truncate px-3 py-2 text-white/35">{item.benefits || "-"}</td><td className="px-3 py-2 text-right"><Button type="button" variant="ghost" size="icon-sm" onClick={()=>setTierEdit(item.tier)} className="text-white/45 hover:text-white"><Edit3 className="size-3.5"/></Button></td></tr>)}</tbody></table></div>
    </section>}

    {view !== "privileges" && <section>
      <div className="mb-2 flex items-center justify-between"><div><h3 className="text-xs font-bold">Daftar customer</h3><p className="mt-1 text-[9px] text-white/30">Role otomatis memakai progres transaksi + progres yang pernah diberikan Admin.</p></div><span className="text-[9px] text-white/35">{members.length} customer</span></div>
      <div className="overflow-x-auto rounded-lg border border-white/[0.08]"><table className="w-full min-w-[920px] text-left text-xs"><thead className="bg-white/[0.025] text-[9px] uppercase text-white/35"><tr><th className="px-3 py-2">Nama</th><th className="px-3 py-2">Email</th><th className="px-3 py-2">WhatsApp</th><th className="px-3 py-2">Saldo</th><th className="px-3 py-2">Role</th><th className="px-3 py-2">Total transaksi</th><th className="px-3 py-2 text-right">Aksi</th></tr></thead><tbody>{members.map((member)=><tr key={member.id} className="border-t border-white/[0.06]"><td className="px-3 py-2"><strong>{member.name}</strong></td><td className="px-3 py-2 text-white/45">{member.email}</td><td className="px-3 py-2 text-white/45">{member.phone}</td><td className="px-3 py-2 font-semibold text-[#d8ff8d]">{formatRupiah(member.balance)}</td><td className="px-3 py-2"><span className="font-black text-[#d8ff8d]">{member.tierLabel}</span><span className="ml-1 text-[8px] text-white/25">{member.tierMode === "manual" ? "Admin" : "Otomatis"}</span></td><td className="px-3 py-2 text-white/55">{formatRupiah(member.lifetimeSpend)}<span className="ml-1 text-[8px] text-white/25">• {member.paidOrders} order</span></td><td className="px-3 py-2 text-right"><Button type="button" variant="ghost" size="icon-sm" onClick={()=>openMember(member)} className="text-white/45 hover:text-white"><Edit3 className="size-3.5"/></Button></td></tr>)}{!members.length&&<tr><td colSpan={7} className="px-3 py-8 text-center text-xs text-white/30">Belum ada customer.</td></tr>}</tbody></table></div>
    </section>}

    <Dialog open={tierEdit!==null} onOpenChange={(open)=>!open&&setTierEdit(null)}>{editingTier&&<DialogContent className="border-white/10 bg-[#10141d] text-white sm:max-w-lg"><DialogHeader><DialogTitle>Edit privilege {editingTier.label}</DialogTitle><DialogDescription className="text-white/38">Batas progres role tetap mengikuti sistem.</DialogDescription></DialogHeader><div className="space-y-3"><label><span className="field-label">Diskon member (%)</span><Input type="number" min={0} max={100} step="0.01" value={editingTier.discountPercent} onChange={(e)=>updateTier(editingTier.tier,{discountPercent:Number(e.target.value)})} className="admin-input"/></label><label><span className="field-label">Privilege / benefit</span><Textarea value={editingTier.benefits} onChange={(e)=>updateTier(editingTier.tier,{benefits:e.target.value})} className="min-h-24 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white"/></label></div><DialogFooter><Button type="button" variant="outline" onClick={()=>setTierEdit(null)} className="border-white/10 bg-white/[0.03] text-white">Batal</Button><Button type="button" disabled={saving} onClick={()=>void savePrivileges()} className="bg-[#b9ff35] font-black text-[#091006]">{saving&&<LoaderCircle className="mr-2 size-4 animate-spin"/>}Simpan</Button></DialogFooter></DialogContent>}</Dialog>

    <Dialog open={memberEdit!==null} onOpenChange={(open)=>!open&&setMemberEdit(null)}>{memberEdit&&<DialogContent className="border-white/10 bg-[#10141d] text-white sm:max-w-lg"><DialogHeader><DialogTitle>Edit {memberEdit.name}</DialogTitle><DialogDescription className="text-white/38">{memberEdit.email} • {memberEdit.phone}</DialogDescription></DialogHeader><div className="space-y-4"><div className="grid grid-cols-2 gap-3 text-[10px]"><div><span className="text-white/30">Transaksi nyata</span><strong className="mt-1 block text-white/70">{formatRupiah(memberEdit.lifetimeSpend)}</strong></div><div><span className="text-white/30">Progres role</span><strong className="mt-1 block text-[#d8ff8d]">{formatRupiah(memberEdit.tierProgress)}</strong></div></div><label><span className="field-label">Role customer</span><select value={role} onChange={(e)=>setRole(e.target.value as "automatic"|MemberTier)} className="admin-input"><option value="automatic">Otomatis</option><option value="basic">BASIC</option><option value="gold">GOLD</option><option value="diamond">DIAMOND</option><option value="platinum">PLATINUM</option></select><p className="mt-1.5 text-[9px] leading-4 text-white/30">Jika Admin memberikan role lebih tinggi, progres minimum role tersebut disimpan. Saat kembali ke Otomatis, progres itu tidak hilang.</p></label><label><span className="field-label">Tambah saldo (opsional)</span><Input type="number" min={0} max={100000000} value={addBalance} onChange={(e)=>setAddBalance(e.target.value)} className="admin-input" placeholder="Contoh: 50000"/></label><label><span className="field-label">Alasan penambahan saldo</span><Input value={reason} onChange={(e)=>setReason(e.target.value)} className="admin-input" placeholder="Bonus / kompensasi / koreksi"/></label></div><DialogFooter><Button type="button" variant="outline" onClick={()=>setMemberEdit(null)} className="border-white/10 bg-white/[0.03] text-white">Batal</Button><Button type="button" disabled={saving} onClick={()=>void saveMember()} className="bg-[#b9ff35] font-black text-[#091006]">{saving&&<LoaderCircle className="mr-2 size-4 animate-spin"/>}Simpan perubahan</Button></DialogFooter></DialogContent>}</Dialog>
  </div>;
}
