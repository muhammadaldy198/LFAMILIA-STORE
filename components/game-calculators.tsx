"use client";

import { useMemo, useState } from "react";
import { Calculator, Disc3, Sparkles, Trophy } from "lucide-react";
import { Input } from "@/components/ui/input";

const inputClass = "mt-2 h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/20";

export function WinRateCalculator() {
  const [matches, setMatches] = useState(100);
  const [winRate, setWinRate] = useState(50);
  const [target, setTarget] = useState(60);
  const result = useMemo(() => {
    const played = Math.max(0, matches || 0);
    const current = Math.min(100, Math.max(0, winRate || 0)) / 100;
    const desired = Math.min(99.99, Math.max(0, target || 0)) / 100;
    if (!played || current >= desired) return { wins: 0, finalMatches: played, reached: true };
    const wins = Math.max(0, Math.ceil(((desired - current) * played) / (1 - desired)));
    return { wins, finalMatches: played + wins, reached: false };
  }, [matches, target, winRate]);

  return (
    <CalculatorPanel>
      <div className="grid gap-4 sm:grid-cols-3">
        <NumberField label="Total pertandingan" value={matches} min={0} onChange={setMatches} />
        <NumberField label="Win rate sekarang (%)" value={winRate} min={0} max={100} step="0.01" onChange={setWinRate} />
        <NumberField label="Target win rate (%)" value={target} min={0} max={99.99} step="0.01" onChange={setTarget} />
      </div>
      <ResultCard icon={Trophy} label={result.reached ? "Target sudah tercapai" : "Kemenangan beruntun yang dibutuhkan"} value={`${result.wins} win`} detail={`Perkiraan total pertandingan setelah target: ${result.finalMatches.toLocaleString("id-ID")}.`} />
    </CalculatorPanel>
  );
}

export function ZodiacCalculator() {
  const [points, setPoints] = useState(40);
  const [cost, setCost] = useState(20);
  const [average, setAverage] = useState(2);
  const result = useMemo(() => {
    const remaining = Math.max(0, 100 - Math.min(100, Math.max(0, points || 0)));
    const perDraw = Math.min(5, Math.max(1, average || 1));
    const price = Math.max(0, cost || 0);
    return {
      remaining,
      draws: Math.ceil(remaining / perDraw),
      diamonds: Math.ceil(remaining / perDraw) * price,
      best: Math.ceil(remaining / 5) * price,
      worst: remaining * price,
    };
  }, [average, cost, points]);

  return (
    <CalculatorPanel>
      <div className="grid gap-4 sm:grid-cols-3">
        <NumberField label="Poin Zodiac sekarang" value={points} min={0} max={100} onChange={setPoints} />
        <NumberField label="Diamond per summon" value={cost} min={0} onChange={setCost} />
        <NumberField label="Rata-rata poin per summon" value={average} min={1} max={5} step="0.1" onChange={setAverage} />
      </div>
      <ResultCard icon={Sparkles} label="Estimasi diamond yang dibutuhkan" value={`${result.diamonds.toLocaleString("id-ID")} diamond`} detail={`${result.remaining} poin tersisa • sekitar ${result.draws} summon. Rentang terbaik–terburuk: ${result.best.toLocaleString("id-ID")}–${result.worst.toLocaleString("id-ID")} diamond.`} />
    </CalculatorPanel>
  );
}

export function MagicWheelCalculator() {
  const [points, setPoints] = useState(80);
  const [singleCost, setSingleCost] = useState(60);
  const [fiveCost, setFiveCost] = useState(270);
  const result = useMemo(() => {
    const remaining = Math.max(0, 200 - Math.min(200, Math.max(0, points || 0)));
    const setCost = Math.max(0, fiveCost || 0);
    const oneCost = Math.max(0, singleCost || 0);
    let bestCost = Number.POSITIVE_INFINITY;
    let bestSets = 0;
    let bestSingles = 0;
    for (let sets = 0; sets <= Math.ceil(remaining / 5); sets += 1) {
      const singles = Math.max(0, remaining - sets * 5);
      const total = sets * setCost + singles * oneCost;
      if (total < bestCost) {
        bestCost = total;
        bestSets = sets;
        bestSingles = singles;
      }
    }
    return { remaining, bestCost: Number.isFinite(bestCost) ? bestCost : 0, bestSets, bestSingles };
  }, [fiveCost, points, singleCost]);

  return (
    <CalculatorPanel>
      <div className="grid gap-4 sm:grid-cols-3">
        <NumberField label="Magic Point sekarang" value={points} min={0} max={200} onChange={setPoints} />
        <NumberField label="Harga 1 draw" value={singleCost} min={0} onChange={setSingleCost} />
        <NumberField label="Harga 5 draw" value={fiveCost} min={0} onChange={setFiveCost} />
      </div>
      <ResultCard icon={Disc3} label="Estimasi diamond termurah" value={`${result.bestCost.toLocaleString("id-ID")} diamond`} detail={`${result.remaining} Magic Point tersisa • ${result.bestSets}× paket 5 draw dan ${result.bestSingles}× single draw.`} />
    </CalculatorPanel>
  );
}

function CalculatorPanel({ children }: { children: React.ReactNode }) {
  return <section className="rounded-[26px] border border-white/[0.09] bg-[#10131b] p-5 shadow-2xl shadow-black/20 sm:p-8">{children}<p className="mt-5 text-[10px] leading-5 text-white/25">Hasil merupakan estimasi. Harga, promo, dan mekanisme di dalam game dapat berubah.</p></section>;
}

function NumberField({ label, value, onChange, min, max, step = "1" }: { label: string; value: number; onChange: (value: number) => void; min?: number; max?: number; step?: string }) {
  return <label><span className="field-label">{label}</span><Input type="number" inputMode="decimal" value={value} min={min} max={max} step={step} onChange={(event) => onChange(Number(event.target.value))} className={inputClass} /></label>;
}

function ResultCard({ icon: Icon, label, value, detail }: { icon: typeof Calculator; label: string; value: string; detail: string }) {
  return <div className="mt-6 overflow-hidden rounded-2xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.07] p-5 sm:p-6"><div className="flex items-start gap-4"><span className="grid size-11 shrink-0 place-items-center rounded-xl bg-[#b9ff35] text-[#091006]"><Icon className="size-5" /></span><div><p className="text-[10px] font-bold uppercase tracking-[0.17em] text-[#cfff72]">{label}</p><strong className="mt-2 block text-2xl font-black tracking-tight text-white sm:text-3xl">{value}</strong><p className="mt-2 text-xs leading-5 text-white/40">{detail}</p></div></div></div>;
}
