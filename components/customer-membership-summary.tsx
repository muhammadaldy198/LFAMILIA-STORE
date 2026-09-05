"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Crown, Sparkles } from "lucide-react";
import { formatRupiah } from "@/lib/store-data";

type MemberTier = "basic" | "gold" | "diamond" | "platinum";

type Membership = {
  tier: MemberTier;
  label: string;
  lifetimeSpend: number;
  nextTier: MemberTier | null;
  nextTierLabel: string | null;
  nextTarget: number | null;
  remainingToNextTier: number;
  setting: {
    tier: MemberTier;
    label: string;
    minSpend: number;
    discountPercent: number;
    benefits: string;
  };
};

const tierClass: Record<MemberTier, string> = {
  basic: "border-white/10 bg-white/[0.03] text-white/70",
  gold: "border-amber-300/20 bg-amber-300/[0.07] text-amber-200",
  diamond: "border-cyan-300/20 bg-cyan-300/[0.07] text-cyan-200",
  platinum: "border-violet-300/20 bg-violet-300/[0.07] text-violet-200",
};

export function CustomerMembershipSummary() {
  const [membership, setMembership] = useState<Membership | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await fetch("/api/account/membership", { cache: "no-store" });
      if (!response.ok) {
        setMembership(null);
        return;
      }
      const data = (await response.json()) as { membership?: Membership };
      setMembership(data.membership ?? null);
    } catch {
      setMembership(null);
    }
  }, []);

  useEffect(() => {
    const initial = window.setTimeout(() => void load(), 0);
    const interval = window.setInterval(() => void load(), 10_000);
    const onFocus = () => void load();
    window.addEventListener("focus", onFocus);
    return () => {
      window.clearTimeout(initial);
      window.clearInterval(interval);
      window.removeEventListener("focus", onFocus);
    };
  }, [load]);

  const progress = useMemo(() => {
    if (!membership?.nextTarget) return 100;
    const start = membership.setting.minSpend;
    const span = Math.max(1, membership.nextTarget - start);
    return Math.max(0, Math.min(100, ((membership.lifetimeSpend - start) / span) * 100));
  }, [membership]);

  if (!membership) return null;

  return (
    <section className="mb-5 rounded-2xl border border-white/[0.08] bg-[#0d1019] p-4 sm:p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#cfff72]">
            <Crown className="size-5" />
          </span>
          <div>
            <p className="text-[9px] font-black uppercase tracking-[0.18em] text-white/30">LFAMILIA Member</p>
            <div className="mt-1 flex flex-wrap items-center gap-2">
              <h2 className="text-lg font-black">Tier {membership.label}</h2>
              <span className={`rounded-full border px-2 py-1 text-[9px] font-black ${tierClass[membership.tier]}`}>
                {membership.label}
              </span>
            </div>
            <p className="mt-1 text-[10px] text-white/38">
              Lifetime belanja {formatRupiah(membership.lifetimeSpend)} dari seluruh order yang sudah lunas.
            </p>
          </div>
        </div>

        <div className="rounded-xl border border-white/[0.08] bg-white/[0.025] px-4 py-3 sm:min-w-44">
          <span className="text-[9px] uppercase tracking-wider text-white/30">Diskon member</span>
          <strong className="mt-1 block text-xl font-black text-[#d8ff8d]">
            {membership.setting.discountPercent}%
          </strong>
          <span className="text-[9px] text-white/30">sesuai pengaturan Pemilik</span>
        </div>
      </div>

      <div className="mt-5">
        <div className="flex items-center justify-between gap-3 text-[10px]">
          <span className="font-semibold text-white/60">
            {membership.nextTierLabel ? `Menuju ${membership.nextTierLabel}` : "Tier tertinggi tercapai"}
          </span>
          <span className="text-white/35">
            {membership.nextTierLabel
              ? `${formatRupiah(membership.remainingToNextTier)} lagi`
              : formatRupiah(membership.lifetimeSpend)}
          </span>
        </div>
        <div className="mt-2 h-2 overflow-hidden rounded-full bg-white/[0.06]">
          <div className="h-full rounded-full bg-[#b9ff35] transition-[width]" style={{ width: `${progress}%` }} />
        </div>
        {membership.nextTarget && (
          <div className="mt-2 flex justify-between text-[9px] text-white/25">
            <span>{formatRupiah(membership.setting.minSpend)}</span>
            <span>{formatRupiah(membership.nextTarget)}</span>
          </div>
        )}
      </div>

      {membership.setting.benefits.trim() && (
        <div className="mt-4 flex items-start gap-2 rounded-xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.04] p-3">
          <Sparkles className="mt-0.5 size-4 shrink-0 text-[#cfff72]" />
          <div>
            <strong className="block text-[10px] text-[#d8ff8d]">Privilege {membership.label}</strong>
            <p className="mt-1 whitespace-pre-line text-[10px] leading-5 text-white/42">{membership.setting.benefits}</p>
          </div>
        </div>
      )}
    </section>
  );
}
