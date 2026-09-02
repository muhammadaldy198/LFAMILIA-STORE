"use client";

import { useEffect, useState } from "react";
import { LoaderCircle, Save, Ticket, UserRound, Wrench } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";

type DeliveryMode = "direct" | "voucher" | "manual";

type DeliveryProduct = {
  slug: string;
  name: string;
  category: string;
  mode: DeliveryMode;
  inputLabel: string;
  inputPlaceholder: string;
  needsServer: boolean;
  targetTemplate: string;
  isActive: boolean;
};

const modeMeta = {
  direct: {
    label: "Direct ke akun",
    description: "Butuh tujuan seperti User ID, nomor HP, email, atau Server/Zone. Provider mengirim langsung ke tujuan tersebut.",
    Icon: UserRound,
  },
  voucher: {
    label: "Kode voucher",
    description: "Tidak meminta data akun. Setelah pembayaran lunas, kode ditampilkan di website dan akun member.",
    Icon: Ticket,
  },
  manual: {
    label: "Manual",
    description: "Pesanan masuk antrean admin. Data tujuan tetap dapat diminta sesuai kebutuhan produk.",
    Icon: Wrench,
  },
} satisfies Record<DeliveryMode, { label: string; description: string; Icon: typeof UserRound }>;

export function AdminProductDeliveryManager() {
  const [items, setItems] = useState<DeliveryProduct[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<string | null>(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  async function load() {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/product-delivery-modes", { cache: "no-store" });
      const data = await response.json() as { products?: DeliveryProduct[]; error?: string };
      if (!response.ok) throw new Error(data.error || "Tipe pengiriman gagal dimuat.");
      setItems(data.products ?? []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Tipe pengiriman gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  function update(slug: string, patch: Partial<DeliveryProduct>) {
    setItems((current) => current.map((item) => item.slug === slug ? { ...item, ...patch } : item));
  }

  async function save(item: DeliveryProduct) {
    setSaving(item.slug);
    setMessage("");
    setError("");
    try {
      const response = await fetch("/api/panel/product-delivery-modes", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          slug: item.slug,
          mode: item.mode,
          inputLabel: item.inputLabel,
          inputPlaceholder: item.inputPlaceholder,
          needsServer: item.needsServer,
          targetTemplate: item.targetTemplate,
        }),
      });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error || "Tipe pengiriman gagal disimpan.");
      setMessage(`${item.name}: tipe pengiriman berhasil disimpan.`);
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Tipe pengiriman gagal disimpan.");
    } finally {
      setSaving(null);
    }
  }

  if (loading) {
    return <div className="flex min-h-28 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" /> Memuat tipe pengiriman…</div>;
  }

  return (
    <div className="mb-5 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 sm:p-4">
      <div className="mb-4">
        <h3 className="text-sm font-black">Tipe Pengiriman Produk</h3>
        <p className="mt-1 text-[10px] leading-5 text-white/35">Berlaku per produk dan tidak bergantung pada kategori. Direct meminta tujuan, Kode Voucher tidak meminta data akun, dan Manual masuk antrean admin.</p>
      </div>

      {message && <div className="mb-3 rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-2.5 text-[10px] text-[#d8ff8d]">{message}</div>}
      {error && <div className="mb-3 rounded-lg border border-red-400/20 bg-red-400/[0.06] p-2.5 text-[10px] text-red-200">{error}</div>}

      <div className="space-y-2.5">
        {items.map((item) => {
          const meta = modeMeta[item.mode];
          const Icon = meta.Icon;
          const hasDestination = item.mode !== "voucher";
          return (
            <div key={item.slug} className="rounded-xl border border-white/[0.08] bg-[#11151e] p-3">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-white/[0.06] text-[#cfff72]"><Icon className="size-3.5" /></span>
                    <div className="min-w-0"><strong className="block truncate text-xs">{item.name}</strong><span className="text-[9px] text-white/28">{item.category} • {item.isActive ? "Aktif" : "Nonaktif"}</span></div>
                  </div>
                </div>
                <select
                  value={item.mode}
                  onChange={(event) => update(item.slug, { mode: event.target.value as DeliveryMode, needsServer: event.target.value === "voucher" ? false : item.needsServer })}
                  className="h-9 min-w-44 rounded-lg border border-white/10 bg-[#171c27] px-2.5 text-[10px] font-bold text-white"
                >
                  <option value="direct">Direct ke akun</option>
                  <option value="voucher">Kode voucher</option>
                  <option value="manual">Manual</option>
                </select>
              </div>

              <p className="mt-2 text-[9px] leading-4 text-white/35">{meta.description}</p>

              {hasDestination ? (
                <div className="mt-3 grid gap-2.5 border-t border-white/[0.07] pt-3 sm:grid-cols-2">
                  <label className="text-[9px] text-white/40"><span className="mb-1 block font-bold text-white/55">Label tujuan</span><Input value={item.inputLabel} onChange={(event) => update(item.slug, { inputLabel: event.target.value })} className="admin-input" placeholder="User ID / Nomor HP / Email" /></label>
                  <label className="text-[9px] text-white/40"><span className="mb-1 block font-bold text-white/55">Contoh / placeholder</span><Input value={item.inputPlaceholder} onChange={(event) => update(item.slug, { inputPlaceholder: event.target.value })} className="admin-input" placeholder="Masukkan User ID" /></label>
                  <label className="text-[9px] text-white/40 sm:col-span-2"><span className="mb-1 block font-bold text-white/55">Format tujuan provider</span><Input value={item.targetTemplate} onChange={(event) => update(item.slug, { targetTemplate: event.target.value })} className="admin-input font-mono" placeholder="{{destination}}{{server}}" /></label>
                  <label className="flex items-center justify-between gap-3 rounded-lg border border-white/[0.07] bg-white/[0.025] px-3 py-2 text-[10px] text-white/55 sm:col-span-2"><span><strong className="block text-white/70">Butuh Server / Zone ID</strong><span className="text-[8px] text-white/30">Aktifkan untuk game yang membutuhkan User ID + Server/Zone.</span></span><Switch checked={item.needsServer} onCheckedChange={(checked) => update(item.slug, { needsServer: checked })} /></label>
                </div>
              ) : (
                <div className="mt-3 rounded-lg border border-[#b9ff35]/15 bg-[#b9ff35]/[0.045] p-2.5 text-[9px] leading-4 text-white/45">Panel Data Akun otomatis disembunyikan. Sistem memakai tujuan internal dan kode hanya ditampilkan setelah pembayaran berstatus lunas.</div>
              )}

              <div className="mt-3 flex justify-end">
                <Button type="button" onClick={() => void save(item)} disabled={saving === item.slug} className="h-9 rounded-lg bg-[#b9ff35] px-3 text-[10px] font-black text-[#091006] hover:bg-[#d0ff75]">
                  {saving === item.slug ? <LoaderCircle className="mr-1.5 size-3.5 animate-spin" /> : <Save className="mr-1.5 size-3.5" />}
                  Simpan tipe
                </Button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
