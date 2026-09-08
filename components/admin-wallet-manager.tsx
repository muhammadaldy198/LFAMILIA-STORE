"use client";

import { useCallback, useEffect, useState } from "react";
import { Edit3, LoaderCircle, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
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

type GatewayReadiness = {
  ready: boolean;
  reason: string | null;
  environment?: "sandbox" | "production" | null;
};

const fallback: WalletSettings = {
  minTopup: 10_000,
  dokuTopupEnabled: false,
  dokuCheckoutEnabled: false,
};

export function AdminWalletManager({ view = "topups" }: { view?: "topups" | "checkout" }) {
  const [settings, setSettings] = useState<WalletSettings>(fallback);
  const [topups, setTopups] = useState<TopupRow[]>([]);
  const [readiness, setReadiness] = useState<GatewayReadiness>({ ready: false, reason: "Belum diperiksa." });
  const [editing, setEditing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/wallet", { cache: "no-store" });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Pengaturan pembayaran gagal dimuat."));
      setSettings((data.settings as WalletSettings | undefined) ?? fallback);
      setTopups((data.topups as TopupRow[] | undefined) ?? []);
      const next = data.gatewayReadiness as { doku?: GatewayReadiness } | undefined;
      setReadiness(next?.doku ?? { ready: false, reason: "Status DOKU tidak tersedia." });
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan pembayaran gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  async function saveSettings() {
    setSaving(true);
    setError("");
    try {
      const response = await fetch("/api/panel/wallet", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(settings),
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Pengaturan gagal disimpan."));
      setMessage("Pengaturan DOKU berhasil disimpan.");
      setEditing(false);
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan gagal disimpan.");
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <div className="flex min-h-40 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat pengaturan…</div>;

  if (view === "topups") {
    return <div className="space-y-3">
      <div><strong className="text-xs">Riwayat top up saldo</strong><p className="mt-1 text-[9px] text-white/30">Top up diproses otomatis melalui DOKU.</p></div>
      {error && <div className="rounded-lg border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-200">{error}</div>}
      <div className="overflow-x-auto rounded-lg border border-white/[0.08]">
        <Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Pelanggan</TableHead><TableHead className="text-[10px] text-white/35">Nominal</TableHead><TableHead className="text-[10px] text-white/35">Pembayaran</TableHead><TableHead className="text-[10px] text-white/35">Status</TableHead><TableHead className="text-[10px] text-white/35">Waktu</TableHead></TableRow></TableHeader><TableBody>{topups.length ? topups.map((item) => <TableRow key={item.id} className="border-white/[0.07]"><TableCell><strong className="text-xs">{item.customer_name}</strong><p className="text-[8px] text-white/25">{item.customer_email}</p></TableCell><TableCell className="text-xs font-bold text-[#d8ff8d]">{formatRupiah(item.amount)}</TableCell><TableCell className="text-[10px] text-white/45">{item.payment_method || "DOKU"}</TableCell><TableCell><TopupStatus value={item.status} /></TableCell><TableCell className="text-[9px] text-white/30">{new Date(item.created_at).toLocaleString("id-ID")}</TableCell></TableRow>) : <TableRow><TableCell colSpan={5} className="py-8 text-center text-xs text-white/28">Belum ada riwayat top up.</TableCell></TableRow>}</TableBody></Table>
      </div>
    </div>;
  }

  const active = settings.dokuCheckoutEnabled || settings.dokuTopupEnabled;
  return <div className="space-y-3">
    <div className="rounded-lg border border-sky-300/15 bg-sky-300/[0.04] px-3 py-2 text-[9px] leading-4 text-white/45">
      DOKU adalah satu-satunya payment gateway. Pelanggan hanya memilih metode pembayaran, bukan gateway.
    </div>
    {message && <div className="rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] p-3 text-xs text-[#d8ff8d]">{message}</div>}
    {error && <div className="rounded-lg border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-200">{error}</div>}
    <div className="overflow-x-auto rounded-lg border border-white/[0.08]">
      <Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Payment gateway</TableHead><TableHead className="text-[10px] text-white/35">Status</TableHead><TableHead className="text-right text-[10px] text-white/35">Aksi</TableHead></TableRow></TableHeader><TableBody>
        <TableRow className="border-white/[0.07]"><TableCell><strong className="text-xs">DOKU Direct API</strong><p className="mt-0.5 text-[8px] text-white/28">{`Checkout ${settings.dokuCheckoutEnabled ? "ON" : "OFF"} • Top up ${settings.dokuTopupEnabled ? "ON" : "OFF"}`}</p>{active && !readiness.ready && <p className="mt-1 max-w-md text-[8px] leading-3 text-amber-200/75">{readiness.reason || "Konfigurasi DOKU belum lengkap."}</p>}</TableCell><TableCell><span className={active ? readiness.ready ? "text-[9px] font-bold text-[#d8ff8d]" : "text-[9px] font-bold text-amber-200" : "text-[9px] text-white/30"}>{active ? readiness.ready ? "Siap" : "Belum siap" : "Nonaktif"}</span></TableCell><TableCell className="text-right"><Button type="button" onClick={() => setEditing(true)} variant="ghost" size="icon-sm" className="text-white/45 hover:text-white"><Edit3 className="size-3.5" /></Button></TableCell></TableRow>
      </TableBody></Table>
    </div>

    <Dialog open={editing} onOpenChange={setEditing}>
      <DialogContent className="w-[calc(100vw-1rem)] max-w-lg overflow-x-hidden border-white/10 bg-[#10141d] text-white">
        <DialogHeader><DialogTitle>DOKU Direct API</DialogTitle><DialogDescription className="text-white/38">Aktifkan DOKU untuk checkout dan/atau top up saldo.</DialogDescription></DialogHeader>
        <div className="space-y-3">
          <Toggle label="Aktif untuk checkout" value={settings.dokuCheckoutEnabled} onChange={(value) => setSettings((current) => ({ ...current, dokuCheckoutEnabled: value }))} />
          <Toggle label="Aktif untuk top up saldo" value={settings.dokuTopupEnabled} onChange={(value) => setSettings((current) => ({ ...current, dokuTopupEnabled: value }))} />
          <MinTopup settings={settings} setSettings={setSettings} />
        </div>
        <DialogFooter><Button type="button" variant="outline" onClick={() => setEditing(false)} className="border-white/10 bg-white/[0.03] text-white">Batal</Button><Button type="button" disabled={saving} onClick={() => void saveSettings()} className="bg-[#b9ff35] font-black text-[#091006]">{saving ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <Save className="mr-2 size-4" />}Simpan</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  </div>;
}

function Toggle({ label, value, onChange }: { label: string; value: boolean; onChange(value: boolean): void }) {
  return <label className="flex items-center justify-between border-b border-white/[0.07] py-3 text-xs text-white/60"><span>{label}</span><Switch checked={value} onCheckedChange={onChange} /></label>;
}
function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label><span className="field-label">{label}</span>{children}</label>;
}
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
