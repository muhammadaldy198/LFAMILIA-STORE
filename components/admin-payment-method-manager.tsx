"use client";
/* eslint-disable @next/next/no-img-element */

import { useCallback, useEffect, useState } from "react";
import { CreditCard, LoaderCircle, Plus, Save, Trash2 } from "lucide-react";
import { AdminMediaUpload } from "@/components/admin-media-upload";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import type { ManagedPaymentChannel } from "@/lib/server/payment-channels";

const fallback: ManagedPaymentChannel[] = [
  ["va", "bca", "BCA"],
  ["va", "mandiri", "Mandiri"],
  ["va", "bni", "BNI"],
  ["va", "bri", "BRI"],
  ["ewallet", "dana", "DANA"],
  ["ewallet", "shopeepay", "ShopeePay"],
  ["qris", "mpm", "QRIS"],
].map(([method, channel, name], sortOrder) => ({
  id: null,
  method: method as ManagedPaymentChannel["method"],
  channel,
  name,
  description: "",
  imageUrl: "",
  isActive: true,
  sortOrder,
}));

export function AdminPaymentMethodManager() {
  const [items, setItems] = useState<ManagedPaymentChannel[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await fetch("/api/panel/payment-methods", {
        cache: "no-store",
      });
      const data = await readJson(response);
      if (!response.ok)
        throw new Error(
          String(data.error || "Metode pembayaran gagal dimuat."),
        );
      setItems(
        (data.channels as ManagedPaymentChannel[] | undefined) ?? fallback,
      );
    } catch (reason) {
      setError(
        reason instanceof Error
          ? reason.message
          : "Metode pembayaran gagal dimuat.",
      );
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    void load();
  }, [load]);
  function update(index: number, patch: Partial<ManagedPaymentChannel>) {
    setItems((current) =>
      current.map((item, itemIndex) =>
        itemIndex === index ? { ...item, ...patch } : item,
      ),
    );
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
      if (!response.ok)
        throw new Error(
          String(data.error || "Metode pembayaran gagal disimpan."),
        );
      setMessage(`${item.name} berhasil disimpan.`);
      await load();
    } catch (reason) {
      setError(
        reason instanceof Error
          ? reason.message
          : "Metode pembayaran gagal disimpan.",
      );
    } finally {
      setSaving("");
    }
  }
  async function remove(index: number) {
    const item = items[index];
    if (!item.id || !window.confirm(`Hapus ${item.name}?`)) return;
    const response = await fetch(`/api/panel/payment-methods?id=${item.id}`, {
      method: "DELETE",
    });
    if (!response.ok) {
      setError("Metode pembayaran gagal dihapus.");
      return;
    }
    await load();
  }
  if (loading)
    return (
      <div className="flex min-h-48 items-center justify-center text-xs text-white/35">
        <LoaderCircle className="mr-2 size-4 animate-spin" />
        Memuat metode pembayaran…
      </div>
    );
  return (
    <div className="space-y-4">
      {message && (
        <p className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">
          {message}
        </p>
      )}
      {error && (
        <p className="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">
          {error}
        </p>
      )}
      <p className="text-xs leading-5 text-white/38">
        Aktifkan hanya metode yang benar-benar tersedia di Midtrans. Gambar
        ditampilkan pada checkout pelanggan.
      </p>
      {items.map((item, index) => (
        <section
          key={item.id ?? `${item.channel}-${index}`}
          className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4"
        >
          <div className="grid gap-4 lg:grid-cols-[180px_1fr]">
            <AdminMediaUpload
              label="Logo pembayaran"
              value={item.imageUrl ?? ""}
              onChange={(imageUrl) => update(index, { imageUrl })}
              help="Unggah logo resmi BCA, DANA, QRIS, dan lainnya."
              previewClassName="aspect-[3/2]"
            />
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Nama">
                <Input
                  value={item.name}
                  onChange={(event) =>
                    update(index, { name: event.target.value })
                  }
                  className="admin-input"
                />
              </Field>
              <Field label="Jenis">
                <select
                  value={item.method}
                  onChange={(event) =>
                    update(index, {
                      method: event.target
                        .value as ManagedPaymentChannel["method"],
                    })
                  }
                  className="admin-input"
                >
                  <option value="va">Virtual Account</option>
                  <option value="ewallet">E-Wallet</option>
                  <option value="qris">QRIS</option>
                </select>
              </Field>
              <Field label="Kode Midtrans">
                <Input
                  value={item.channel}
                  onChange={(event) =>
                    update(index, {
                      channel: event.target.value
                        .toLowerCase()
                        .replace(/[^a-z0-9_]/g, ""),
                    })
                  }
                  className="admin-input"
                />
              </Field>
              <Field label="Urutan">
                <Input
                  type="number"
                  min={0}
                  value={item.sortOrder}
                  onChange={(event) =>
                    update(index, { sortOrder: Number(event.target.value) })
                  }
                  className="admin-input"
                />
              </Field>
              <Field label="Keterangan" wide>
                <Input
                  value={item.description}
                  onChange={(event) =>
                    update(index, { description: event.target.value })
                  }
                  className="admin-input"
                  placeholder="Keterangan singkat"
                />
              </Field>
              <label className="flex items-center justify-between rounded-xl border border-white/[0.08] px-3 text-xs text-white/55 sm:col-span-2">
                <span>Aktif di checkout</span>
                <Switch
                  checked={item.isActive}
                  onCheckedChange={(isActive) => update(index, { isActive })}
                />
              </label>
            </div>
          </div>
          <div className="mt-4 flex justify-end gap-2">
            {item.id && (
              <Button
                type="button"
                variant="ghost"
                onClick={() => void remove(index)}
                className="text-red-300"
              >
                <Trash2 className="size-4" />
                Hapus
              </Button>
            )}
            <Button
              type="button"
              disabled={saving === `save-${index}`}
              onClick={() => void save(index)}
              className="bg-[#b9ff35] text-xs font-black text-[#091006]"
            >
              {saving === `save-${index}` ? (
                <LoaderCircle className="size-4 animate-spin" />
              ) : (
                <Save className="size-4" />
              )}
              Simpan
            </Button>
          </div>
        </section>
      ))}
      <Button
        type="button"
        variant="outline"
        onClick={() =>
          setItems((current) => [
            ...current,
            {
              id: null,
              method: "va",
              channel: "",
              name: "",
              description: "",
              imageUrl: "",
              isActive: false,
              sortOrder: current.length,
            },
          ])
        }
        className="border-white/10 bg-white/[0.03] text-white"
      >
        <Plus className="size-4" />
        Tambah metode
      </Button>
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
async function readJson(response: Response): Promise<Record<string, unknown>> {
  const raw = await response.text();
  try {
    return raw
      ? (JSON.parse(raw) as Record<string, unknown>)
      : { error: "Respons server kosong." };
  } catch {
    return { error: "Respons server tidak valid." };
  }
}
