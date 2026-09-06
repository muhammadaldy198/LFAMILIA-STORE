"use client";

const providers = [
  { name: "DigiFlazz", type: "Topup Provider", status: "Ready" },
  { name: "iPaymu", type: "Payment", status: "Ready" },
  { name: "Midtrans", type: "Payment", status: "Ready" },
  { name: "VIPPayment", type: "Topup Provider", status: "Ready" },
];

export function AdminProviderManager() {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {providers.map((provider) => (
        <div key={provider.name} className="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="font-bold">{provider.name}</p>
              <p className="text-xs text-white/40">{provider.type}</p>
            </div>
            <span className="rounded-full bg-green-400/10 px-2 py-1 text-[10px] text-green-300">{provider.status}</span>
          </div>
          <button className="mt-4 rounded-xl border border-white/10 px-3 py-2 text-xs">Pengaturan</button>
        </div>
      ))}
    </div>
  );
}
