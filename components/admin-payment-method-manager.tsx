"use client";
/* eslint-disable @next/next/no-img-element */

import { useCallback, useEffect, useState } from "react";
import { CreditCard, Edit3, LoaderCircle, Plus, RefreshCw, Save, Trash2 } from "lucide-react";
import { AdminMediaUpload } from "@/components/admin-media-upload";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { ManagedPaymentChannel } from "@/lib/server/payment-channels";

type Gateway = "doku";

export function AdminPaymentMethodManager() {
  const [items, setItems] = useState<ManagedPaymentChannel[]>([]);
  const [gateways, setGateways] = useState<Gateway[]>([]);
  const [editing, setEditing] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState("");
  const [syncing, setSyncing] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/payment-methods", { cache: "no-store" });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Metode pembayaran gagal dimuat."));
      setItems((data.channels as ManagedPaymentChannel[] | undefined) ?? []);
      const rawGateways = Array.isArray(data.gateways)
        ? data.gateways
        : [data.gateway];
      const nextGateways = rawGateways.reduce<Gateway[]>((result, item) => {
        if (item === "doku") result.push(item);
        return result;
      }, []);
      setGateways(nextGateways);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Metode pembayaran gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  function update(index: number, patch: Partial<ManagedPaymentChannel>) {
    setItems((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item));
  }

  function add() {
    setItems((current) => {
      const next = [...current, {
        id: null,
        method: "va" as const,
        channel: "",
        name: "",
        description: "",
        imageUrl: "",
        isActive: false,
        sortOrder: current.length,
      }];
      window.setTimeout(() => setEditing(next.length - 1), 0);
      return next;
    });
  }

  async function syncGateway() {
    setSyncing(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/panel/payment-methods", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "sync" }),
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Sinkron metode pembayaran gagal."));
      setMessage("Metode pembayaran DOKU berhasil disinkronkan.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Sinkron metode pembayaran gagal.");
    } finally {
      setSyncing(false);
    }
  }

  async function save(index: number) {
    const item = items[index];
    setSaving(`save-${index}`);
    setError("");
    try {
      const response = await fetch("/api/panel/payment-methods", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(item),
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Metode pembayaran gagal disimpan."));
      setMessage(`${item.name} berhasil disimpan.`);
      setEditing(null);
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Metode pembayaran gagal disimpan.");
    } finally {
      setSaving("");
    }
  }

  async function remove(index: number) {
    const item = items[index];
    if (!item.id || !window.confirm(`Hapus ${item.name}?`)) return;
    const response = await fetch(`/api/panel/payment-methods?id=${item.id}`, { method: "DELETE" });
    if (!response.ok) {
      setError("Metode pembayaran gagal dihapus.");
      return;
    }
    setEditing(null);
    await load();
  }

  if (loading) return <div className="flex min-h-40 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat metode pembayaran…</div>;

  const item = editing == null ? null : items[editing];
  const gatewayLabel = gateways.length ? "DOKU" : "gateway";

  return <div className="space-y-3">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <p className="text-[10px] text-white/35">Metode checkout mengikuti channel yang didukung DOKU. Pelanggan hanya memilih QRIS, E-Wallet, atau Virtual Account.</p>
      <div className="flex gap-2">
        <Button type="button" disabled={syncing || gateways.length === 0} onClick={() => void syncGateway()} variant="outline" size="sm" className="h-8 rounded-lg border-white/10 bg-white/[0.03] px-3 text-[9px] text-white">
          {syncing ? <LoaderCircle className="mr-1 size-3 animate-spin" /> : <RefreshCw className="mr-1 size-3" />}
          Sync {gatewayLabel}
        </Button>
        <Button type="button" onClick={add} size="sm" className="h-8 bg-[#b9ff35] px-3 text-[9px] font-black text-[#091006]"><Plus className="mr-1 size-3" />Tambah metode</Button>
      </div>
    </div>
    {gateways.length === 0 && <p className="rounded-lg border border-amber-300/20 bg-amber-300/[0.05] p-3 text-[10px] text-amber-100/65">Aktifkan DOKU untuk checkout sebelum melakukan sync.</p>}
    {message && <p className="rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] p-3 text-xs text-[#d8ff8d]">{message}</p>}
    {error && <p className="rounded-lg border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-200">{error}</p>}

    <div className="overflow-x-auto rounded-lg border border-white/[0.08]">
      <Table>
        <TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Metode</TableHead><TableHead className="text-[10px] text-white/35">Jenis</TableHead><TableHead className="text-[10px] text-white/35">Status</TableHead><TableHead className="text-right text-[10px] text-white/35">Aksi</TableHead></TableRow></TableHeader>
        <TableBody>{items.map((entry, index) => <TableRow key={entry.id ?? `${entry.channel}-${index}`} className="border-white/[0.07] hover:bg-white/[0.025]">
          <TableCell><div className="flex items-center gap-2.5">{entry.imageUrl ? <img src={entry.imageUrl} alt="" className="size-8 rounded-md bg-white object-contain p-1" /> : <span className="grid size-8 place-items-center rounded-md bg-white/[0.05]"><CreditCard className="size-3.5 text-white/35" /></span>}<div><strong className="text-xs">{entry.name || "Metode baru"}</strong><p className="text-[8px] text-white/25">{entry.description || entry.channel || "Belum diatur"}</p></div></div></TableCell>
          <TableCell className="text-[10px] text-white/45">{entry.method === "va" ? "Virtual Account" : entry.method === "ewallet" ? "E-Wallet" : "QRIS"}</TableCell>
          <TableCell><span className={entry.isActive ? "text-[9px] font-bold text-[#d8ff8d]" : "text-[9px] text-white/30"}>{entry.isActive ? "Aktif" : "Nonaktif"}</span></TableCell>
          <TableCell className="text-right"><Button type="button" onClick={() => setEditing(index)} variant="ghost" size="icon-sm" className="text-white/45 hover:text-white"><Edit3 className="size-3.5" /></Button></TableCell>
        </TableRow>)}</TableBody>
      </Table>
    </div>

    <Dialog open={editing != null} onOpenChange={(open) => !open && setEditing(null)}>
      {item && editing != null && <DialogContent className="w-[calc(100vw-1rem)] max-w-xl overflow-x-hidden border-white/10 bg-[#10141d] text-white">
        <DialogHeader><DialogTitle>Edit metode pembayaran</DialogTitle><DialogDescription className="text-white/38">Ubah detail hanya saat diperlukan.</DialogDescription></DialogHeader>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2"><AdminMediaUpload label="Logo pembayaran" value={item.imageUrl ?? ""} onChange={(imageUrl) => update(editing, { imageUrl })} help="Logo yang tampil di checkout." previewClassName="h-20" /></div>
          <Field label="Nama"><Input value={item.name} onChange={(e) => update(editing, { name: e.target.value })} className="admin-input" /></Field>
          <Field label="Jenis"><select value={item.method} onChange={(e) => update(editing, { method: e.target.value as ManagedPaymentChannel["method"] })} className="admin-input"><option value="va">Virtual Account</option><option value="ewallet">E-Wallet</option><option value="qris">QRIS</option></select></Field>
          <Field label="Kode channel"><Input value={item.channel} onChange={(e) => update(editing, { channel: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "") })} className="admin-input" /></Field>
          <Field label="Urutan"><Input type="number" min={0} value={item.sortOrder} onChange={(e) => update(editing, { sortOrder: Number(e.target.value) })} className="admin-input" /></Field>
          <Field label="Keterangan" wide><Input value={item.description} onChange={(e) => update(editing, { description: e.target.value })} className="admin-input" /></Field>
          <label className="flex items-center justify-between border-t border-white/[0.08] py-3 text-xs text-white/60 sm:col-span-2"><span>Aktif di checkout</span><Switch checked={item.isActive} onCheckedChange={(isActive) => update(editing, { isActive })} /></label>
        </div>
        <DialogFooter>{item.id && <Button type="button" variant="ghost" onClick={() => void remove(editing)} className="mr-auto text-red-300"><Trash2 className="mr-1 size-4" />Hapus</Button>}<Button type="button" variant="outline" onClick={() => setEditing(null)} className="border-white/10 bg-white/[0.03] text-white">Batal</Button><Button type="button" disabled={saving === `save-${editing}`} onClick={() => void save(editing)} className="bg-[#b9ff35] font-black text-[#091006]">{saving === `save-${editing}` ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <Save className="mr-2 size-4" />}Simpan</Button></DialogFooter>
      </DialogContent>}
    </Dialog>
  </div>;
}

function Field({ label, wide = false, children }: { label: string; wide?: boolean; children: React.ReactNode }) {
  return <label className={wide ? "sm:col-span-2" : ""}><span className="field-label">{label}</span>{children}</label>;
}
async function readJson(response: Response): Promise<Record<string, unknown>> {
  const raw = await response.text();
  try { return raw ? JSON.parse(raw) as Record<string, unknown> : { error: "Respons server kosong." }; }
  catch { return { error: "Respons server tidak valid." }; }
}
