"use client";
import { useEffect, useState } from "react";
import { LoaderCircle, RefreshCw, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";

type Settings = { isAutoSync: boolean };

export function AdminDigiflazzPricing() {
  const [settings, setSettings] = useState<Settings>({ isAutoSync: true });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    void fetch("/api/panel/digiflazz-pricing", { cache: "no-store" })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok) throw new Error(data.error);
        setSettings(data.settings);
      })
      .catch((reason) =>
        setError(reason instanceof Error ? reason.message : "Gagal memuat."),
      );
  }, []);

  async function save(syncNow = false) {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/panel/digiflazz-pricing", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ ...settings, syncNow }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error);
      setMessage(
        syncNow
          ? `${data.result?.updated ?? 0} harga nominal diperbarui memakai margin masing-masing.`
          : "Pengaturan sinkron otomatis disimpan.",
      );
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Gagal menyimpan.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="max-w-2xl rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5">
      <h3 className="font-bold">Sinkron harga DigiFlazz</h3>
      <p className="mt-2 text-xs leading-5 text-white/40">
        Margin tidak diatur di sini. Setiap nominal DigiFlazz memakai margin
        Rupiah/Persen miliknya sendiri di menu Produk.
      </p>

      <label className="mt-5 flex items-center justify-between rounded-xl border border-white/[0.08] p-3 text-xs">
        <span>
          <strong className="block">Sinkron otomatis</strong>
          <span className="mt-1 block text-[10px] text-white/35">
            Perbarui harga modal supplier dan hitung ulang harga jual memakai
            margin masing-masing nominal.
          </span>
        </span>
        <Switch
          checked={settings.isAutoSync}
          onCheckedChange={(value) =>
            setSettings({ ...settings, isAutoSync: value })
          }
        />
      </label>

      {message && <p className="mt-4 text-xs text-[#cfff72]">{message}</p>}
      {error && <p className="mt-4 text-xs text-red-200">{error}</p>}

      <div className="mt-5 flex flex-wrap gap-2">
        <Button
          disabled={busy}
          onClick={() => void save(false)}
          className="rounded-xl bg-[#b9ff35] font-black text-[#091006]"
        >
          {busy ? (
            <LoaderCircle className="mr-2 size-4 animate-spin" />
          ) : (
            <Save className="mr-2 size-4" />
          )}
          Simpan
        </Button>
        <Button
          disabled={busy}
          onClick={() => void save(true)}
          variant="outline"
          className="rounded-xl border-white/10 bg-white/[0.03] text-white"
        >
          <RefreshCw className="mr-2 size-4" />
          Sinkronkan sekarang
        </Button>
      </div>
    </div>
  );
}
