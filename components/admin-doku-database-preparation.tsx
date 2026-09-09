"use client";

import { useCallback, useEffect, useState } from "react";
import { CheckCircle2, Database, LoaderCircle, ShieldAlert } from "lucide-react";
import { Button } from "@/components/ui/button";

type Status = {
  completed: boolean;
  schemaReady: boolean;
  missingColumns: string[];
  safeToRun: boolean;
};

export function AdminDokuDatabasePreparation() {
  const [status, setStatus] = useState<Status | null>(null);
  const [loading, setLoading] = useState(true);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/doku-database", {
        cache: "no-store",
      });
      const data = await readJson(response);
      if (!response.ok) {
        throw new Error(String(data.error || "Status database gagal dimuat."));
      }
      setStatus(data as unknown as Status);
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Status database gagal dimuat.",
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  async function runPreparation() {
    setRunning(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/panel/doku-database", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: "{}",
      });
      const data = await readJson(response);
      if (!response.ok) {
        throw new Error(String(data.error || "Persiapan database gagal."));
      }
      setMessage(
        data.alreadyCompleted
          ? "Database DOKU sebelumnya sudah dipersiapkan."
          : "Database DOKU berhasil dipersiapkan tanpa menghapus transaksi, produk, atau akun.",
      );
      await load();
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Persiapan database gagal.",
      );
    } finally {
      setRunning(false);
    }
  }

  if (loading) {
    return (
      <div className="flex min-h-24 items-center justify-center text-xs text-white/35">
        <LoaderCircle className="mr-2 size-4 animate-spin" />
        Memeriksa database…
      </div>
    );
  }

  const completed = Boolean(status?.completed && status?.schemaReady);

  return (
    <div className="space-y-3">
      <div
        className={
          completed
            ? "rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] p-3"
            : "rounded-lg border border-amber-300/20 bg-amber-300/[0.05] p-3"
        }
      >
        <div className="flex items-start gap-2.5">
          {completed ? (
            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[#b9ff35]" />
          ) : (
            <Database className="mt-0.5 size-4 shrink-0 text-amber-200" />
          )}
          <div>
            <strong className="text-xs">
              {completed ? "Database DOKU siap" : "Database DOKU belum disiapkan"}
            </strong>
            <p className="mt-1 text-[10px] leading-4 text-white/42">
              {completed
                ? "Marker persiapan sudah tersimpan dan skema DOKU siap digunakan."
                : "Jalankan sekali sebelum mengaktifkan DOKU. Proses ini hanya menyiapkan skema dan tidak menghapus data."}
            </p>
          </div>
        </div>
      </div>

      {status?.missingColumns?.length ? (
        <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] p-3">
          <p className="text-[9px] font-bold uppercase tracking-wider text-white/35">
            Kolom yang akan ditambahkan
          </p>
          <p className="mt-1 break-words text-[9px] leading-4 text-white/45">
            {status.missingColumns.join(", ")}
          </p>
        </div>
      ) : null}

      {!completed && (
        <div className="flex items-start gap-2 rounded-lg border border-white/[0.08] bg-white/[0.02] p-3 text-[9px] leading-4 text-white/50">
          <ShieldAlert className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />
          Aman dijalankan: persiapan DOKU tidak menghapus transaksi, saldo, produk, akun, atau stok voucher.
        </div>
      )}

      {message && (
        <div className="rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] p-3 text-xs text-[#d8ff8d]">
          {message}
        </div>
      )}
      {error && (
        <div className="rounded-lg border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-200">
          {error}
        </div>
      )}

      <Button
        type="button"
        onClick={() => void runPreparation()}
        disabled={running || completed}
        className="h-9 bg-[#b9ff35] px-4 text-[10px] font-black text-[#091006]"
      >
        {running ? (
          <LoaderCircle className="mr-2 size-3.5 animate-spin" />
        ) : (
          <Database className="mr-2 size-3.5" />
        )}
        {completed
          ? "Database sudah siap"
          : running
            ? "Menyiapkan…"
            : "Persiapkan Database DOKU"}
      </Button>
    </div>
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
