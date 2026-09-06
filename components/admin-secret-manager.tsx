"use client";

const settings = [
  "DigiFlazz API Key",
  "iPaymu API Key",
  "Midtrans Server Key",
  "Midtrans Client Key",
  "Webhook Secret",
  "Encryption Key",
];

export function AdminSecretManager() {
  return (
    <div className="space-y-3">
      {settings.map((item) => (
        <div key={item} className="flex items-center justify-between rounded-2xl border border-white/10 bg-white/[0.03] p-4">
          <div>
            <p className="font-bold">{item}</p>
            <p className="text-xs text-white/40">Tersimpan aman</p>
          </div>
          <button className="rounded-xl border border-white/10 px-3 py-2 text-xs">Kelola</button>
        </div>
      ))}
    </div>
  );
}
