"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { WalletCards } from "lucide-react";
import {
  Field,
  Panel,
  buttonClass,
  inputClass,
  primaryButtonClass,
} from "@/components/admin-workspace-ui";

type CustomerBalance = {
  id: string;
  name: string;
  email: string;
  balance: number;
};

type AdminBalance = {
  id: string;
  name: string;
  email: string;
  role: string;
  balance: number;
};

type BalancePayload = {
  customers?: CustomerBalance[];
  admins?: AdminBalance[];
  error?: string;
};

async function requestJson<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, init);
  const payload = await response.json().catch(() => ({})) as T & { error?: string };
  if (!response.ok) throw new Error(payload.error || "Permintaan saldo gagal diproses.");
  return payload;
}

function rupiah(value: number) {
  return `Rp ${Number(value || 0).toLocaleString("id-ID")}`;
}

export function AdminBalanceManager() {
  const [customers, setCustomers] = useState<CustomerBalance[]>([]);
  const [admins, setAdmins] = useState<AdminBalance[]>([]);
  const [accountType, setAccountType] = useState<"Pelanggan" | "Admin">("Pelanggan");
  const [targetId, setTargetId] = useState("");
  const [operation, setOperation] = useState<"Tambah" | "Kurangi">("Tambah");
  const [amount, setAmount] = useState("100000");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    const payload = await requestJson<BalancePayload>("/api/panel/balances", { cache: "no-store" });
    const nextCustomers = payload.customers ?? [];
    const nextAdmins = payload.admins ?? [];
    setCustomers(nextCustomers);
    setAdmins(nextAdmins);
    setTargetId((current) => current || nextCustomers[0]?.id || nextAdmins[0]?.id || "");
  }, []);

  useEffect(() => {
    let cancelled = false;
    void requestJson<BalancePayload>("/api/panel/balances", { cache: "no-store" })
      .then((payload) => {
        if (cancelled) return;
        const nextCustomers = payload.customers ?? [];
        const nextAdmins = payload.admins ?? [];
        setCustomers(nextCustomers);
        setAdmins(nextAdmins);
        setTargetId((current) => current || nextCustomers[0]?.id || nextAdmins[0]?.id || "");
      })
      .catch((reasonValue) => {
        if (!cancelled) setError(reasonValue instanceof Error ? reasonValue.message : "Data saldo gagal dimuat.");
      });
    return () => { cancelled = true; };
  }, []);

  const targets = useMemo(
    () => accountType === "Pelanggan" ? customers : admins,
    [accountType, customers, admins],
  );

  function switchType(type: "Pelanggan" | "Admin") {
    setAccountType(type);
    setTargetId(type === "Pelanggan" ? customers[0]?.id || "" : admins[0]?.id || "");
  }

  async function adjustBalance() {
    const value = Math.trunc(Number(amount));
    setError("");
    setNotice("");
    if (!targetId || !Number.isFinite(value) || value < 1 || reason.trim().length < 3) {
      setError("Pilih akun, isi nominal, dan tulis alasan minimal 3 karakter.");
      return;
    }
    setBusy(true);
    try {
      const payload = await requestJson<{ balanceBefore: number; balanceAfter: number }>("/api/panel/balances", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          accountType: accountType === "Pelanggan" ? "customer" : "admin",
          targetId,
          operation: operation === "Tambah" ? "credit" : "debit",
          amount: value,
          reason: reason.trim(),
        }),
      });
      await load();
      setNotice(`${operation} saldo berhasil: ${rupiah(payload.balanceBefore)} → ${rupiah(payload.balanceAfter)}.`);
      setReason("");
    } catch (reasonValue) {
      setError(reasonValue instanceof Error ? reasonValue.message : "Saldo gagal diperbarui.");
    } finally {
      setBusy(false);
    }
  }

  return <Panel
    title="Atur Saldo"
    description="Super Admin dapat menambah atau mengurangi saldo pelanggan maupun akun admin. Semua perubahan masuk ledger dan audit log."
    action={<button type="button" onClick={() => void load().catch((reasonValue) => setError(reasonValue instanceof Error ? reasonValue.message : "Data saldo gagal dimuat."))} className={buttonClass}><WalletCards className="size-3.5" />Refresh Saldo</button>}
  >
    <div className="grid grid-cols-2 gap-4 p-4">
      {notice && <button type="button" onClick={() => setNotice("")} className="col-span-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-left text-[9px] font-semibold text-emerald-700">{notice}</button>}
      {error && <button type="button" onClick={() => setError("")} className="col-span-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-left text-[9px] font-semibold text-red-700">{error}</button>}
      <Field label="Jenis akun">
        <div className="grid grid-cols-2 gap-2">
          {(["Pelanggan", "Admin"] as const).map((type) => <button type="button" key={type} onClick={() => switchType(type)} className={`h-9 rounded-md border text-[9px] font-bold ${accountType === type ? "border-[#0769e9] bg-blue-50 text-[#0769e9]" : "border-[#dfe5ed] text-[#52627a]"}`}>{type}</button>)}
        </div>
      </Field>
      <Field label="Akun tujuan">
        <select className={inputClass} value={targetId} onChange={(event) => setTargetId(event.target.value)}>
          {targets.map((target) => <option key={target.id} value={target.id}>{target.name} — {rupiah(target.balance)}</option>)}
        </select>
      </Field>
      <Field label="Tindakan">
        <select className={inputClass} value={operation} onChange={(event) => setOperation(event.target.value as "Tambah" | "Kurangi")}><option>Tambah</option><option>Kurangi</option></select>
      </Field>
      <Field label="Nominal">
        <input className={inputClass} inputMode="numeric" value={amount} onChange={(event) => setAmount(event.target.value.replace(/\D/g, ""))} />
      </Field>
      <Field label="Alasan wajib" wide>
        <textarea className={`${inputClass} h-20 py-2`} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Contoh: kompensasi CS atau koreksi saldo..." />
      </Field>
      <div className="col-span-2 flex justify-end">
        <button type="button" disabled={busy} onClick={() => void adjustBalance()} className={primaryButtonClass}>{busy ? "Menyimpan..." : "Simpan Perubahan Saldo"}</button>
      </div>
    </div>
  </Panel>;
}
