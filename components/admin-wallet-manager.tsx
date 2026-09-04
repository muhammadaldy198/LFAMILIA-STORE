"use client";

import { useCallback, useEffect, useState } from "react";
import { Edit3, LoaderCircle, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatRupiah } from "@/lib/store-data";
import type { WalletSettings } from "@/lib/server/wallet";

type TopupRow = {
  id: string;
  amount: number;
  sender_name: string;
  payment_method: string;
  proof_url: string;
  status: string;
  admin_notes: string | null;
  created_at: string;
  customer_name: string;
  customer_email: string;
};

type EditTarget = "midtrans" | "ipaymu" | "manual-bank" | "manual-qris";

const fallback: WalletSettings = {
  isEnabled: false, methodName: "Transfer Bank", accountName: "", accountNumber: "",
  instructions: "Ikuti instruksi pembayaran yang tampil.", minTopup: 10_000,
  manualQrisEnabled: false, manualQrisName: "QRIS Manual", manualQrisImageUrl: "",
  midtransTopupEnabled: false, midtransCheckoutEnabled: false,
  ipaymuTopupEnabled: false, ipaymuCheckoutEnabled: false,
};

export function AdminWalletManager({ view = "topups" }: { view?: "topups" | "checkout" }) {
  const [settings, setSettings] = useState<WalletSettings>(fallback);
  const [topups, setTopups] = useState<TopupRow[]>([]);
  const [editing, setEditing] = useState<EditTarget | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/panel/wallet", { cache: "no-store" });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Pengaturan pembayaran gagal dimuat."));
      setSettings((data.settings as WalletSettings | undefined) ?? fallback);
      setTopups((data.topups as TopupRow[] | undefined) ?? []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan pembayaran gagal dimuat.");
    } finally { setLoading(false); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  async function saveSettings() {
    setSaving(true); setError("");
    try {
      const response = await fetch("/api/panel/wallet", { method: "PUT", headers: { "content-type": "application/json" }, body: JSON.stringify(settings) });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Pengaturan gagal disimpan."));
      setMessage("Pengaturan pembayaran berhasil disimpan.");
      setEditing(null);
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan gagal disimpan.");
    } finally { setSaving(false); }
  }

  if (loading) return <div className="flex min-h-40 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat pengaturan…</div>;

  if (view === "topups") {
    return <div className="space-y-3">
      <div><strong className="text-xs">Riwayat top up saldo</strong><p className="mt-1 text-[9px] text-white/30">Top up baru diproses otomatis oleh payment gateway. Tidak ada persetujuan manual.</p></div>
      {error && <div className="rounded-lg border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-200">{error}</div>}
      <div className="overflow-x-auto rounded-lg border border-white/[0.08]">
        <Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Pelanggan</TableHead><TableHead className="text-[10px] text-white/35">Nominal</TableHead><TableHead className="text-[10px] text-white/35">Pembayaran</TableHead><TableHead className="text-[10px] text-white/35">Status</TableHead><TableHead className="text-[10px] text-white/35">Waktu</TableHead></TableRow></TableHeader><TableBody>{topups.length ? topups.map((item) => <TableRow key={item.id} className="border-white/[0.07]"><TableCell><strong className="text-xs">{item.customer_name}</strong><p className="text-[8px] text-white/25">{item.customer_email}</p></TableCell><TableCell className="text-xs font-bold text-[#d8ff8d]">{formatRupiah(item.amount)}</TableCell><TableCell className="text-[10px] text-white/45">{item.payment_method || "Gateway otomatis"}</TableCell><TableCell><TopupStatus value={item.status} /></TableCell><TableCell className="text-[9px] text-white/30">{new Date(item.created_at).toLocaleString("id-ID")}</TableCell></TableRow>) : <TableRow><TableCell colSpan={5} className="py-8 text-center text-xs text-white/28">Belum ada riwayat top up.</TableCell></TableRow>}</TableBody></Table>
      </div>
    </div>;
  }

  const rows: Array<{ key: EditTarget; name: string; type: string; active: boolean; detail: string }> = [
    { key: "midtrans", name: "Midtrans", type: "Payment Gateway", active: settings.midtransCheckoutEnabled || settings.midtransTopupEnabled, detail: `Checkout ${settings.midtransCheckoutEnabled ? "ON" : "OFF"} • Top up ${settings.midtransTopupEnabled ? "ON" : "OFF"}` },
    { key: "ipaymu", name: "iPaymu", type: "Payment Gateway", active: settings.ipaymuCheckoutEnabled || settings.ipaymuTopupEnabled, detail: `Checkout ${settings.ipaymuCheckoutEnabled ? "ON" : "OFF"} • Top up ${settings.ipaymuTopupEnabled ? "ON" : "OFF"}` },
    { key: "manual-bank", name: settings.methodName || "Transfer Bank", type: "Pembayaran Manual", active: settings.isEnabled, detail: settings.accountNumber ? `${settings.accountName} • ${settings.accountNumber}` : "Rekening belum diisi" },
    { key: "manual-qris", name: settings.manualQrisName || "QRIS Manual", type: "Pembayaran Manual", active: settings.manualQrisEnabled, detail: settings.manualQrisImageUrl ? "QRIS sudah dipasang" : "Gambar QRIS belum dipasang" },
  ];

  return <div className="space-y-3">
    {message && <div className="rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] p-3 text-xs text-[#d8ff8d]">{message}</div>}
    {error && <div className="rounded-lg border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-200">{error}</div>}
    <div className="overflow-x-auto rounded-lg border border-white/[0.08]">
      <Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Pembayaran</TableHead><TableHead className="text-[10px] text-white/35">Jenis</TableHead><TableHead className="text-[10px] text-white/35">Status</TableHead><TableHead className="text-right text-[10px] text-white/35">Aksi</TableHead></TableRow></TableHeader><TableBody>{rows.map((row) => <TableRow key={row.key} className="border-white/[0.07]"><TableCell><strong className="text-xs">{row.name}</strong><p className="mt-0.5 text-[8px] text-white/28">{row.detail}</p></TableCell><TableCell className="text-[10px] text-white/45">{row.type}</TableCell><TableCell><span className={row.active ? "text-[9px] font-bold text-[#d8ff8d]" : "text-[9px] text-white/30"}>{row.active ? "Aktif" : "Nonaktif"}</span></TableCell><TableCell className="text-right"><Button type="button" onClick={() => setEditing(row.key)} variant="ghost" size="icon-sm" className="text-white/45 hover:text-white"><Edit3 className="size-3.5" /></Button></TableCell></TableRow>)}</TableBody></Table>
    </div>

    <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
      <DialogContent className="border-white/10 bg-[#10141d] text-white sm:max-w-lg">
        <DialogHeader><DialogTitle>{editing === "midtrans" ? "Midtrans" : editing === "ipaymu" ? "iPaymu" : editing === "manual-bank" ? "Transfer bank manual" : "QRIS manual"}</DialogTitle><DialogDescription className="text-white/38">Ubah pengaturan lalu simpan.</DialogDescription></DialogHeader>
        <div className="space-y-3">
          {editing === "midtrans" && <><Toggle label="Aktif untuk checkout" value={settings.midtransCheckoutEnabled} onChange={(value) => setSettings((current) => ({ ...current, midtransCheckoutEnabled: value }))} /><Toggle label="Aktif untuk top up saldo" value={settings.midtransTopupEnabled} onChange={(value) => setSettings((current) => ({ ...current, midtransTopupEnabled: value }))} /><MinTopup settings={settings} setSettings={setSettings} /></>}
          {editing === "ipaymu" && <><Toggle label="Aktif untuk checkout" value={settings.ipaymuCheckoutEnabled} onChange={(value) => setSettings((current) => ({ ...current, ipaymuCheckoutEnabled: value }))} /><Toggle label="Aktif untuk top up saldo" value={settings.ipaymuTopupEnabled} onChange={(value) => setSettings((current) => ({ ...current, ipaymuTopupEnabled: value }))} /><MinTopup settings={settings} setSettings={setSettings} /></>}
          {editing === "manual-bank" && <><Toggle label="Aktif di checkout" value={settings.isEnabled} onChange={(value) => setSettings((current) => ({ ...current, isEnabled: value }))} /><Field label="Nama bank / metode"><Input value={settings.methodName} onChange={(e) => setSettings((c) => ({ ...c, methodName: e.target.value }))} className="admin-input" /></Field><Field label="Nama pemilik rekening"><Input value={settings.accountName} onChange={(e) => setSettings((c) => ({ ...c, accountName: e.target.value }))} className="admin-input" /></Field><Field label="Nomor rekening"><Input value={settings.accountNumber} onChange={(e) => setSettings((c) => ({ ...c, accountNumber: e.target.value }))} className="admin-input" /></Field><Field label="Instruksi"><Textarea value={settings.instructions} onChange={(e) => setSettings((c) => ({ ...c, instructions: e.target.value }))} className="min-h-20 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" /></Field></>}
          {editing === "manual-qris" && <><Toggle label="Aktif di checkout" value={settings.manualQrisEnabled} onChange={(value) => setSettings((current) => ({ ...current, manualQrisEnabled: value }))} /><Field label="Nama QRIS"><Input value={settings.manualQrisName} onChange={(e) => setSettings((c) => ({ ...c, manualQrisName: e.target.value }))} className="admin-input" /></Field><Field label="URL gambar QRIS"><Input value={settings.manualQrisImageUrl} onChange={(e) => setSettings((c) => ({ ...c, manualQrisImageUrl: e.target.value }))} className="admin-input" /></Field></>}
        </div>
        <DialogFooter><Button type="button" variant="outline" onClick={() => setEditing(null)} className="border-white/10 bg-white/[0.03] text-white">Batal</Button><Button type="button" disabled={saving} onClick={() => void saveSettings()} className="bg-[#b9ff35] font-black text-[#091006]">{saving ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <Save className="mr-2 size-4" />}Simpan</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  </div>;
}

function Toggle({ label, value, onChange }: { label: string; value: boolean; onChange(value: boolean): void }) {
  return <label className="flex items-center justify-between border-b border-white/[0.07] py-3 text-xs text-white/60"><span>{label}</span><Switch checked={value} onCheckedChange={onChange} /></label>;
}
function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label><span className="field-label">{label}</span>{children}</label>; }
function MinTopup({ settings, setSettings }: { settings: WalletSettings; setSettings: React.Dispatch<React.SetStateAction<WalletSettings>> }) {
  return <Field label="Minimal top up saldo"><Input type="number" min={1000} value={settings.minTopup} onChange={(e) => setSettings((c) => ({ ...c, minTopup: Number(e.target.value) }))} className="admin-input" /></Field>;
}
function TopupStatus({ value }: { value: string }) {
  const paid = value === "approved" || value === "paid";
  const failed = value === "rejected" || value === "failed" || value === "expired";
  return <span className={paid ? "text-[9px] font-bold text-[#d8ff8d]" : failed ? "text-[9px] font-bold text-red-200" : "text-[9px] font-bold text-amber-200"}>{paid ? "Berhasil" : failed ? "Gagal" : "Menunggu"}</span>;
}
async function readJson(response: Response): Promise<Record<string, unknown>> {
  const raw = await response.text();
  try { return raw ? JSON.parse(raw) as Record<string, unknown> : { error: "Respons server kosong." }; }
  catch { return { error: "Respons server tidak valid." }; }
}
