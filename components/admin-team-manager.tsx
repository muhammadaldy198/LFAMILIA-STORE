"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import { Edit3, KeyRound, LoaderCircle, Plus, ShieldCheck, Trash2, UserCog } from "lucide-react";
import { Button } from "@/components/ui/button";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger } from "@/components/ui/alert-dialog";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";

type TeamUser = {
  id: number;
  username: string;
  name: string;
  role: "owner" | "staff";
  isActive: boolean;
  password?: string;
  created_at?: string;
};

const empty: TeamUser = { id: 0, username: "", name: "", role: "staff", isActive: true, password: "" };

export function AdminTeamManager() {
  const [users, setUsers] = useState<TeamUser[]>([]);
  const [draft, setDraft] = useState<TeamUser>(empty);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await fetch("/api/panel/team", { cache: "no-store" });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error);
      setUsers(data.users ?? []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Tim admin gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  async function save(event: FormEvent) {
    event.preventDefault();
    setSaving(true); setError(""); setMessage("");
    try {
      const response = await fetch("/api/panel/team", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ ...draft, id: draft.id || undefined }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error);
      setOpen(false);
      setMessage(draft.id ? "Akun admin berhasil diperbarui." : "Akun admin baru berhasil dibuat.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Admin gagal disimpan.");
    } finally {
      setSaving(false);
    }
  }

  async function remove(id: number) {
    const response = await fetch(`/api/panel/team?id=${id}`, { method: "DELETE" });
    const data = await response.json();
    if (!response.ok) { setError(data.error); return; }
    setMessage("Admin berhasil dihapus.");
    await load();
  }

  if (loading) return <div className="flex min-h-48 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat tim…</div>;

  return (
    <>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><p className="text-xs font-semibold">{users.length} akun admin</p><p className="mt-1 text-[10px] text-white/30">Setiap Pemilik dan Staff masuk menggunakan ID serta password masing-masing.</p></div>
        <Button onClick={() => { setDraft(empty); setOpen(true); }} className="rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006]"><Plus className="mr-2 size-4" />Tambah admin</Button>
      </div>
      {message && <div className="mb-4 rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">{message}</div>}
      {error && <div className="mb-4 rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
      <div className="space-y-2">
        {users.map((item) => (
          <div key={item.id} className="flex items-center justify-between gap-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3">
            <div className="flex min-w-0 items-center gap-3">
              <span className={`grid size-9 shrink-0 place-items-center rounded-xl ${item.role === "owner" ? "bg-[#b9ff35]/12 text-[#b9ff35]" : "bg-blue-400/10 text-blue-300"}`}>{item.role === "owner" ? <ShieldCheck className="size-4" /> : <UserCog className="size-4" />}</span>
              <div className="min-w-0"><strong className="block truncate text-xs">{item.name}</strong><p className="mt-1 truncate text-[9px] text-white/32">ID: {item.username} • {item.role === "owner" ? "Pemilik" : "Staff"} • {item.isActive ? "Aktif" : "Nonaktif"}</p></div>
            </div>
            <div className="flex">
              <Button size="icon-sm" variant="ghost" onClick={() => { setDraft({ ...item, password: "" }); setOpen(true); }} className="text-white/50"><Edit3 className="size-3.5" /></Button>
              <AlertDialog>
                <AlertDialogTrigger asChild><Button size="icon-sm" variant="ghost" className="text-red-300"><Trash2 className="size-3.5" /></Button></AlertDialogTrigger>
                <AlertDialogContent className="border-white/10 bg-[#10141d] text-white"><AlertDialogHeader><AlertDialogTitle>Hapus akses {item.name}?</AlertDialogTitle><AlertDialogDescription className="text-white/42">Akun dan seluruh sesi admin ini akan dihapus. Toko harus tetap memiliki setidaknya satu Pemilik aktif.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel className="border-white/10 bg-white/[0.03] text-white">Batal</AlertDialogCancel><AlertDialogAction onClick={() => void remove(item.id)} className="bg-red-500 text-white hover:bg-red-400">Hapus akses</AlertDialogAction></AlertDialogFooter></AlertDialogContent>
              </AlertDialog>
            </div>
          </div>
        ))}
      </div>
      <div className="mt-5 rounded-xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.045] p-4 text-[10px] leading-5 text-white/40"><strong className="flex items-center gap-2 text-[#d8ff8d]"><KeyRound className="size-3.5" />Password dilindungi</strong><p className="mt-1">Password disimpan dalam bentuk hash dan tidak dapat dilihat kembali. Pemilik dapat menggantinya dari tombol edit.</p></div>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="border-white/10 bg-[#10141d] text-white">
          <form onSubmit={save}>
            <DialogHeader><DialogTitle>{draft.id ? "Edit admin" : "Tambah admin"}</DialogTitle><DialogDescription className="text-white/38">Pemilik memiliki akses penuh. Staff hanya menangani pesanan, produk aman, dan konten yang diizinkan.</DialogDescription></DialogHeader>
            <div className="space-y-4 py-5">
              <label><span className="field-label">Nama</span><Input required value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} className="admin-input" /></label>
              <label><span className="field-label">ID admin</span><Input required minLength={3} maxLength={32} autoComplete="off" value={draft.username} onChange={(event) => setDraft({ ...draft, username: event.target.value.toLowerCase().replace(/[^a-z0-9._-]/g, "") })} placeholder="contoh: staff01" className="admin-input" /></label>
              <label><span className="field-label">{draft.id ? "Password baru (opsional)" : "Password sementara"}</span><Input required={!draft.id} minLength={draft.id ? undefined : 10} maxLength={72} type="password" autoComplete="new-password" value={draft.password ?? ""} onChange={(event) => setDraft({ ...draft, password: event.target.value })} placeholder={draft.id ? "Kosongkan jika tidak diganti" : "Minimal 10 karakter"} className="admin-input" /></label>
              <label><span className="field-label">Peran</span><select value={draft.role} onChange={(event) => setDraft({ ...draft, role: event.target.value as "owner" | "staff" })} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs"><option value="staff">Staff</option><option value="owner">Pemilik</option></select></label>
              <label className="flex items-center justify-between rounded-xl border border-white/[0.08] p-3 text-xs text-white/55"><span>Akun aktif</span><Switch checked={draft.isActive} onCheckedChange={(checked) => setDraft({ ...draft, isActive: checked })} /></label>
            </div>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)} className="border-white/10 bg-white/[0.03] text-white">Batal</Button><Button disabled={saving} className="bg-[#b9ff35] font-black text-[#091006]">{saving && <LoaderCircle className="mr-2 size-4 animate-spin" />}Simpan admin</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}
