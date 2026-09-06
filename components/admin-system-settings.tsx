"use client";

import { ShieldCheck, KeyRound, CreditCard, Boxes } from "lucide-react";

const settings = [
  { title: "Provider API", items: ["DigiFlazz", "VIPPayment"], icon: Boxes },
  { title: "Payment Gateway", items: ["iPaymu", "Midtrans"], icon: CreditCard },
  { title: "Secret Manager", items: ["API Key", "Webhook Secret", "Encryption Key"], icon: KeyRound },
  { title: "Security", items: ["Owner only", "Audit Log", "Encrypted Storage"], icon: ShieldCheck },
];

export function AdminSystemSettings() {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {settings.map((section) => {
        const Icon = section.icon;
        return (
          <div key={section.title} className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4">
            <div className="flex items-center gap-2 text-sm font-bold text-white">
              <Icon className="size-4 text-[#b9ff35]" />
              {section.title}
            </div>
            <div className="mt-3 space-y-2">
              {section.items.map((item) => (
                <div key={item} className="rounded-lg bg-black/20 px-3 py-2 text-xs text-white/50">
                  {item}
                </div>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}
