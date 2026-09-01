"use client";

import { useCallback, useEffect, useState } from "react";
import {
  CheckCircle2,
  ExternalLink,
  LoaderCircle,
  Save,
  WalletCards,
  XCircle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { formatRupiah } from "@/lib/store-data";
import type { WalletSettings } from "@/lib/server/wallet";

type TopupRow = {
  id: string;
  amount: number;
  sender_name: string;
  payment_method: string;
  proof_url: string;
  status: "pending" | "approved" | "rejected";
  admin_notes: string | null;
  reviewed_by: string | null;
  reviewed_at: string | null;
  created_at: string;
  customer_name: string;
  customer_email: string;
  customer_balance: number;
};

const fallback: WalletSettings = {
  isEnabled: false,
  methodName: "Transfer Bank",
  accountName: "",
  accountNumber: "",
  instructions: "Kirim sesuai nominal lalu unggah bukti pembayaran.",
  minTopup: 10_000,
  manualQrisEnabled: false,
  manualQrisName: "QRIS Manual",
  manualQrisImageUrl: "",
  ipaymuTopupEnabled: false,
  ipaymuCheckoutEnabled: false,
};

export function AdminWalletManager({
  view = "topups",
}: {
  view?: "topups" | "checkout";
}) {
  const [settings, setSettings] = useState<WalletSettings>(fallback);
  const [topups, setTopups] = useState<TopupRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/wallet", { cache: "no-store" });
      const data = await readJson(response);
      if (!response.ok)
        throw new Error(data.error || "Saldo pelanggan gagal dimuat.");
      setSettings(data.settings ?? fallback);
      setTopups(data.topups ?? []);
    } catch (reason) {
      setError(
        reason instanceof Error
          ? reason.message
          : "Pengaturan pembayaran gagal dimuat.",
      );
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  async function saveSettings() {
    setSaving("settings");
    setError("");
    try {
      const response = await fetch("/api/panel/wallet", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(settings),
      });
      const data = await readJson(response);
      if (!response.ok)
        throw new Error(data.error || "Pengaturan saldo gagal disimpan.");
      setMessage("Pengaturan top up saldo berhasil disimpan.");
    } catch (reason) {
      setError(
        reason instanceof Error
          ? reason.message
          : "Pengaturan saldo gagal disimpan.",
      );
    } finally {
      setSaving("");
    }
  }

  async function review(item: TopupRow, decision: "approved" | "rejected") {
    const notes =
      window.prompt(
        decision === "approved"
          ? "Catatan persetujuan (opsional)"
          : "Alasan penolakan (opsional)",
        "",
      ) ?? undefined;
    setSaving(item.id);
    setError("");
    try {
      const response = await fetch("/api/panel/wallet", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ id: item.id, decision, notes }),
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(data.error || "Top up gagal ditinjau.");
      setMessage(
        decision === "approved"
          ? "Top up disetujui dan saldo pelanggan bertambah."
          : "Top up ditolak.",
      );
      await load();
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Top up gagal ditinjau.",
      );
    } finally {
      setSaving("");
    }
  }

  if (loading)
    return (
      <div className="flex min-h-56 items-center justify-center text-xs text-white/35">
        <LoaderCircle className="mr-2 size-4 animate-spin" />
        Memuat pengaturan…
      </div>
    );
  return (
    <div className="space-y-6">
      {message && (
        <div className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">
          {message}
        </div>
      )}
      {error && (
        <div className="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">
          {error}
        </div>
      )}
      {view === "checkout" && (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5">
          <div className="flex items-start justify-between gap-4">
            <div className="flex items-start gap-3">
              <WalletCards className="mt-0.5 size-5 text-[#cfff72]" />
              <div>
                <h3 className="font-bold">Checkout manual & gateway</h3>
                <p className="mt-1 max-w-xl text-[10px] leading-5 text-white/30">
                  Atur QRIS manual, transfer bank, dan kapan checkout otomatis
                  Midtrans ditampilkan ke pelanggan.
                </p>
              </div>
            </div>
            <Button
              type="button"
              disabled={saving === "settings"}
              onClick={() => void saveSettings()}
              className="bg-[#b9ff35] text-xs font-black text-[#091006]"
            >
              {saving === "settings" ? (
                <LoaderCircle className="size-4 animate-spin" />
              ) : (
                <Save className="size-4" />
              )}
              Simpan
            </Button>
          </div>
          <div className="mt-5 grid gap-4 sm:grid-cols-2">
            <label className="flex items-center justify-between rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 text-xs text-white/60 sm:col-span-2">
              <span>Aktifkan transfer bank manual di checkout</span>
              <Switch
                checked={settings.isEnabled}
                onCheckedChange={(checked) =>
                  setSettings((current) => ({ ...current, isEnabled: checked }))
                }
              />
            </label>
            <Field label="Nama bank/metode">
              <Input
                value={settings.methodName}
                onChange={(event) =>
                  setSettings((current) => ({
                    ...current,
                    methodName: event.target.value,
                  }))
                }
                className="admin-input"
                placeholder="BCA / Transfer Bank"
              />
            </Field>
            <Field label="Minimal top up">
              <Input
                type="number"
                min={1000}
                value={settings.minTopup}
                onChange={(event) =>
                  setSettings((current) => ({
                    ...current,
                    minTopup: Number(event.target.value),
                  }))
                }
                className="admin-input"
              />
            </Field>
            <Field label="Nama pemilik rekening">
              <Input
                value={settings.accountName}
                onChange={(event) =>
                  setSettings((current) => ({
                    ...current,
                    accountName: event.target.value,
                  }))
                }
                className="admin-input"
              />
            </Field>
            <Field label="Nomor rekening / akun">
              <Input
                value={settings.accountNumber}
                onChange={(event) =>
                  setSettings((current) => ({
                    ...current,
                    accountNumber: event.target.value,
                  }))
                }
                className="admin-input"
              />
            </Field>
            <label className="flex items-center justify-between rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 text-xs text-white/60 sm:col-span-2">
              <span>Aktifkan QRIS manual</span>
              <Switch
                checked={settings.manualQrisEnabled}
                onCheckedChange={(checked) =>
                  setSettings((current) => ({
                    ...current,
                    manualQrisEnabled: checked,
                  }))
                }
              />
            </label>
            <Field label="Nama QRIS">
              <Input
                value={settings.manualQrisName}
                onChange={(event) =>
                  setSettings((current) => ({
                    ...current,
                    manualQrisName: event.target.value,
                  }))
                }
                className="admin-input"
                placeholder="QRIS LFAMILIA"
              />
            </Field>
            <Field label="URL gambar QRIS">
              <Input
                value={settings.manualQrisImageUrl}
                onChange={(event) =>
                  setSettings((current) => ({
                    ...current,
                    manualQrisImageUrl: event.target.value,
                  }))
                }
                className="admin-input"
                placeholder="https://..."
              />
            </Field>
            <label className="flex items-center justify-between rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.04] p-3 text-xs text-white/70 sm:col-span-2">
              <span>
                <strong className="block">
                  Aktifkan top up saldo otomatis iPaymu
                </strong>
                <span className="mt-1 block text-[10px] text-white/35">
                  Hanya aktif jika Secret iPaymu dan callback sudah benar.
                </span>
              </span>
              <Switch
                checked={settings.ipaymuTopupEnabled}
                onCheckedChange={(checked) =>
                  setSettings((current) => ({
                    ...current,
                    ipaymuTopupEnabled: checked,
                  }))
                }
              />
            </label>
            <label className="flex items-center justify-between rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.04] p-3 text-xs text-white/70 sm:col-span-2">
              <span>
                <strong className="block">
                  Aktifkan checkout otomatis Midtrans
                </strong>
                <span className="mt-1 block text-[10px] text-white/35">
                  Nyalakan setelah Midtrans Production siap; matikan QRIS manual
                  bila sudah tidak dipakai.
                </span>
              </span>
              <Switch
                checked={settings.ipaymuCheckoutEnabled}
                onCheckedChange={(checked) =>
                  setSettings((current) => ({
                    ...current,
                    ipaymuCheckoutEnabled: checked,
                  }))
                }
              />
            </label>
            <Field label="Instruksi pelanggan" wide>
              <Textarea
                value={settings.instructions}
                onChange={(event) =>
                  setSettings((current) => ({
                    ...current,
                    instructions: event.target.value,
                  }))
                }
                className="min-h-24 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white"
              />
            </Field>
          </div>
        </section>
      )}
      {view === "topups" && (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4 sm:p-5">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="font-bold">Permintaan top up</h3>
              <p className="mt-1 text-[10px] text-white/30">
                Periksa nominal, pengirim, dan bukti sebelum menyetujui.
              </p>
            </div>
            <span className="rounded-full bg-white/[0.05] px-2.5 py-1 text-[9px] text-white/40">
              {topups.filter((item) => item.status === "pending").length}{" "}
              menunggu
            </span>
          </div>
          <div className="mt-4 space-y-3">
            {topups.length ? (
              topups.map((item) => (
                <div
                  key={item.id}
                  className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4"
                >
                  <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                      <div className="flex flex-wrap items-center gap-2">
                        <strong className="text-sm">
                          {item.customer_name}
                        </strong>
                        <Status value={item.status} />
                      </div>
                      <p className="mt-1 text-[10px] text-white/35">
                        {item.customer_email} • pengirim {item.sender_name}
                      </p>
                      <strong className="mt-3 block text-lg text-[#d8ff8d]">
                        {formatRupiah(item.amount)}
                      </strong>
                      <p className="mt-1 text-[10px] text-white/35">
                        {item.payment_method} •{" "}
                        {new Date(item.created_at).toLocaleString("id-ID")}
                      </p>
                      <a
                        href={`/api/panel/wallet/proof?key=${encodeURIComponent(proofKey(item.proof_url))}`}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-blue-300 hover:text-blue-200"
                      >
                        Lihat bukti pembayaran
                        <ExternalLink className="size-3.5" />
                      </a>
                      {item.admin_notes && (
                        <p className="mt-3 rounded-lg bg-white/[0.03] px-3 py-2 text-[10px] text-white/38">
                          Catatan: {item.admin_notes}
                        </p>
                      )}
                    </div>
                    {item.status === "pending" && (
                      <div className="flex gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          disabled={saving === item.id}
                          onClick={() => void review(item, "rejected")}
                          className="border-red-400/20 bg-red-400/[0.05] text-red-200"
                        >
                          <XCircle className="size-4" />
                          Tolak
                        </Button>
                        <Button
                          type="button"
                          disabled={saving === item.id}
                          onClick={() => void review(item, "approved")}
                          className="bg-[#b9ff35] font-black text-[#091006]"
                        >
                          {saving === item.id ? (
                            <LoaderCircle className="size-4 animate-spin" />
                          ) : (
                            <CheckCircle2 className="size-4" />
                          )}
                          Setujui
                        </Button>
                      </div>
                    )}
                  </div>
                </div>
              ))
            ) : (
              <div className="rounded-xl border border-dashed border-white/10 py-10 text-center text-xs text-white/28">
                Belum ada permintaan top up saldo.
              </div>
            )}
          </div>
        </section>
      )}
    </div>
  );
}

function Field({
  label,
  wide = false,
  children,
}: {
  label: string;
  wide?: boolean;
  children: React.ReactNode;
}) {
  return (
    <label className={wide ? "sm:col-span-2" : ""}>
      <span className="field-label">{label}</span>
      {children}
    </label>
  );
}
function Status({ value }: { value: TopupRow["status"] }) {
  const style =
    value === "approved"
      ? "bg-[#b9ff35]/10 text-[#d8ff8d]"
      : value === "rejected"
        ? "bg-red-400/10 text-red-200"
        : "bg-amber-300/10 text-amber-200";
  return (
    <span
      className={`rounded-full px-2 py-0.5 text-[8px] font-black uppercase ${style}`}
    >
      {value === "approved"
        ? "Disetujui"
        : value === "rejected"
          ? "Ditolak"
          : "Menunggu"}
    </span>
  );
}
function proofKey(value: string) {
  return value.split("/").pop() || value;
}
async function readJson(response: Response): Promise<Record<string, unknown>> {
  const raw = await response.text();
  if (!raw)
    return { error: "Server mengembalikan respons kosong. Coba muat ulang." };
  try {
    return JSON.parse(raw) as Record<string, unknown>;
  } catch {
    return { error: "Server mengembalikan respons tidak valid." };
  }
}
