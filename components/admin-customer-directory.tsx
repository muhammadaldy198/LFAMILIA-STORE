"use client";

import { useEffect, useState } from "react";
import { Users } from "lucide-react";

type Customer = { id: string; name: string; email: string; phone: string; isActive: boolean; createdAt: string; paidOrders: number; tier: string; tierLabel: string };

export function AdminCustomerDirectory() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [error, setError] = useState("");
  useEffect(() => {
    void (async () => {
      const response = await fetch("/api/panel/members", { cache: "no-store" });
      const payload = await response.json().catch(() => ({})) as { members?: Customer[]; error?: string };
      if (!response.ok) { setError(payload.error || "Pelanggan gagal dimuat."); return; }
      setCustomers(payload.members || []);
    })();
  }, []);
  return <section className="rounded-lg border border-[#dfe6ef] bg-white p-5 text-[#14213a] shadow-[0_1px_4px_rgba(20,33,58,.04)]">
    <header className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg bg-blue-50 text-[#0875ed]"><Users className="size-5" /></span><div><h1 className="text-base font-extrabold">Pelanggan</h1><p className="text-xs text-[#718198]">Direktori operasional. Saldo dan data finansial hanya tersedia untuk Super Admin.</p></div></header>
    {error && <p className="mt-4 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-700">{error}</p>}
    <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[680px] text-left text-xs"><thead className="bg-[#f6f8fb] text-[#718198]"><tr><th className="p-3">Pelanggan</th><th className="p-3">Kontak</th><th className="p-3">Tier</th><th className="p-3">Pesanan dibayar</th><th className="p-3">Status</th><th className="p-3">Bergabung</th></tr></thead><tbody>{customers.map((customer) => <tr key={customer.id} className="border-t border-[#edf0f4]"><td className="p-3 font-semibold">{customer.name}</td><td className="p-3"><div>{customer.email}</div><div className="text-[#718198]">{customer.phone || "-"}</div></td><td className="p-3">{customer.tierLabel || customer.tier}</td><td className="p-3">{customer.paidOrders}</td><td className="p-3">{customer.isActive ? "Aktif" : "Suspend"}</td><td className="p-3">{customer.createdAt ? new Date(customer.createdAt).toLocaleDateString("id-ID") : "-"}</td></tr>)}</tbody></table></div>
  </section>;
}
