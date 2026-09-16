"use client";

import { useMemo, useState } from "react";
import { CheckCircle2, Copy, ExternalLink, RefreshCw, Search, ShieldCheck, XCircle } from "lucide-react";
import {
  Field,
  Panel,
  Status,
  TabBar,
  WorkspaceHeader,
  buttonClass,
  inputClass,
  primaryButtonClass,
} from "@/components/admin-workspace-ui";

const tabs = ["Cek Game", "Region MLBB", "PLN", "Kode Game"] as const;
type Tab = (typeof tabs)[number];

type LookupResult = {
  nickname?: string | null;
  region?: string | null;
  customerName?: string | null;
};

const docs = [
  { label: "Cek nickname game", endpoint: "/check-nick-game", href: "https://api.kokinpay.com/docs/check-nick-game" },
  { label: "Cek region MLBB", endpoint: "/check-region-mlbb", href: "https://api.kokinpay.com/docs/check-region-mlbb" },
  { label: "Cek nama PLN", endpoint: "/check-nick-pln", href: "https://api.kokinpay.com/docs/check-nick-pln" },
] as const;

const gameCodes = [
  ["Mobile Legends", "mobile-legends", true],
  ["Free Fire", "free-fire", false],
  ["PUBG Mobile", "pubg-mobile", false],
  ["Call of Duty Mobile", "call-of-duty-mobile", false],
  ["Valorant", "valorant", false],
  ["Genshin Impact", "genshin-impact", true],
  ["Honor of Kings", "honor-of-kings", false],
  ["League of Legends: Wild Rift", "league-of-legends-wild-rift", false],
  ["Arena of Valor", "arena-of-valor", false],
  ["Point Blank", "point-blank", false],
  ["Free Fire Max", "free-fire-max", false],
  ["Whiteout Survival", "whiteout-survival", false],
  ["Honkai Impact 3", "honkai-impact-3", false],
  ["Honkai: Star Rail", "honkai-star-rail", true],
  ["Eggy Party", "eggy-party", true],
  ["Undawn", "undawn", false],
  ["Growtopia", "growtopia", false],
  ["League of Legends PC", "league-of-legends-pc", false],
  ["FC Mobile", "fc-mobile", false],
  ["Super Sus", "super-sus", false],
  ["Harry Potter: Magic Awakened", "harry-potter-magic-awakened", true],
  ["Revelation: Infinite Journey", "revelation-infinite-journey", false],
  ["MU Origin 3", "mu-origin-3", false],
  ["Sausage Man", "sausage-man", false],
  ["Speed Drifters", "speed-drifters", false],
  ["Tom and Jerry: Chase", "tom-and-jerry-chase", true],
  ["Teamfight Tactics Mobile", "teamfight-tactics-mobile", false],
  ["LifeAfter", "lifeafter", true],
  ["Laplace M", "laplace-m", false],
  ["Arena Breakout", "arena-breakout", false],
  ["Zenless Zone Zero", "zenless-zone-zero", true],
  ["AFK Journey", "afk-journey", false],
  ["Magic Chess Go Go", "magic-chess-go-go", true],
  ["Love and Deepspace", "love-and-deepspace", false],
  ["Pokemon Unite", "pokemon-unite", false],
  ["Dragon Raja", "dragon-raja", false],
  ["Football Master 2", "football-master-2", false],
  ["Garena Shell", "garena-shell", false],
  ["Goddess of Victory: Nikke", "goddess-of-victory-nikke", true],
  ["Metal Slug: Awakening", "metal-slug-awakening", false],
  ["Ragnarok M: Eternal Love", "ragnarok-m-eternal-love", true],
] as const;

export function AdminKokinpayWorkspace() {
  const [tab, setTab] = useState<Tab>("Cek Game");
  const [gameCode, setGameCode] = useState("");
  const [userId, setUserId] = useState("");
  const [server, setServer] = useState("");
  const [customerNumber, setCustomerNumber] = useState("");
  const [query, setQuery] = useState("");
  const [result, setResult] = useState<LookupResult | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const filteredCodes = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return gameCodes;
    return gameCodes.filter(([name, code]) => name.toLowerCase().includes(needle) || code.includes(needle));
  }, [query]);

  async function runCheck() {
    setError("");
    setResult(null);
    setBusy(true);
    try {
      const action = tab === "Cek Game" ? "game" : tab === "Region MLBB" ? "region" : "pln";
      const body = action === "game"
        ? { action, gameCode: gameCode.trim(), userId: userId.trim(), ...(server.trim() ? { server: server.trim() } : {}) }
        : action === "region"
          ? { action, userId: userId.trim(), server: server.trim() }
          : { action, customerNumber: customerNumber.trim() };
      const response = await fetch("/api/panel/nickname-tools", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const payload = await response.json().catch(() => ({})) as LookupResult & { error?: string };
      if (!response.ok) throw new Error(payload.error || "Pemeriksaan tidak berhasil.");
      setResult(payload);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pemeriksaan tidak berhasil.");
    } finally {
      setBusy(false);
    }
  }

  const gameIsMlbb = tab === "Cek Game" && gameCode.trim() === "mobile-legends";

  return <div>
    <WorkspaceHeader
      title="Validasi Akun"
      description="Cek nickname game, region Mobile Legends, dan nama pelanggan PLN melalui KokinPay tanpa mengekspos API key ke browser."
      actions={<Status tone="blue">Server-side</Status>}
    />

    <Panel title="KokinPay Tools" description="Tampilan mengikuti komponen, jarak, warna, dan tipografi panel Admin LFAMILIA yang lain.">
      <div className="border-b border-[#edf0f4] px-3 pt-1 sm:px-4">
        <TabBar tabs={[...tabs]} active={tab} onChange={(value) => { setTab(value as Tab); setError(""); setResult(null); }} />
      </div>

      {tab !== "Kode Game" ? <div className="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="grid content-start grid-cols-1 gap-4 sm:grid-cols-2">
          {tab === "Cek Game" && <Field label="Game Code" help="Isi kode yang sama dengan kolom Kode Game Nickname pada Produk → Input Customer.">
            <input list="kokinpay-game-codes" value={gameCode} onChange={(event) => setGameCode(event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ""))} className={inputClass} placeholder="mobile-legends" />
            <datalist id="kokinpay-game-codes">{gameCodes.map(([name, code]) => <option key={code} value={code}>{name}</option>)}</datalist>
          </Field>}
          {tab !== "PLN" && <Field label="User ID"><input value={userId} onChange={(event) => setUserId(event.target.value)} className={inputClass} placeholder="Masukkan User ID" /></Field>}
          {tab !== "PLN" && <Field label="Server / Zone" help={tab === "Region MLBB" || gameIsMlbb ? "Wajib untuk Mobile Legends. Nickname dan region harus sama-sama berhasil." : "Isi jika game memerlukan Server / Zone ID."}><input value={server} onChange={(event) => setServer(event.target.value)} className={inputClass} placeholder={tab === "Region MLBB" || gameIsMlbb ? "Wajib diisi" : "Opsional"} /></Field>}
          {tab === "PLN" && <Field label="Nomor Meter / ID Pelanggan PLN" wide><input value={customerNumber} onChange={(event) => setCustomerNumber(event.target.value.replace(/\D/g, "").slice(0, 12))} className={inputClass} placeholder="11–12 angka" inputMode="numeric" /></Field>}
          {gameIsMlbb && <div className="sm:col-span-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-[9px] leading-4 text-blue-800">Mobile Legends divalidasi dengan dua endpoint: <strong>/check-nick-game</strong> untuk nickname dan <strong>/check-region-mlbb</strong> untuk region. Keduanya wajib berhasil sebelum akun dianggap valid.</div>}
          <div className="sm:col-span-2 flex flex-wrap items-center gap-2 border-t border-[#edf0f4] pt-4">
            <button type="button" disabled={busy} onClick={() => void runCheck()} className={primaryButtonClass}><RefreshCw className={`size-3.5 ${busy ? "animate-spin" : ""}`} />{busy ? "Memeriksa..." : "Cek Data"}</button>
            <button type="button" disabled={busy} onClick={() => { setGameCode(""); setUserId(""); setServer(""); setCustomerNumber(""); setResult(null); setError(""); }} className={buttonClass}>Bersihkan</button>
          </div>
        </div>

        <aside className="space-y-3">
          <div className="rounded-md border border-[#e1e6ed] bg-[#f8fafc] p-3">
            <div className="flex items-center justify-between gap-2"><strong className="text-[10px] text-[#34445f]">Endpoint aktif</strong><Status tone="gray">KokinPay</Status></div>
            <code className="mt-2 block break-all rounded border border-[#e5eaf1] bg-white px-2.5 py-2 text-[9px] text-[#1769e8]">{tab === "Cek Game" ? (gameIsMlbb ? "/check-nick-game + /check-region-mlbb" : "/check-nick-game") : tab === "Region MLBB" ? "/check-nick-game + /check-region-mlbb" : "/check-nick-pln"}</code>
          </div>
          {error && <ResultBox tone="error" text={error} />}
          {result && <ResultBox tone="success" text={tab === "PLN" ? `Nama pelanggan: ${result.customerName || "-"}` : result.region ? `Nickname: ${result.nickname || "-"} · Region: ${result.region}` : `Nickname: ${result.nickname || "-"}`} />}
          <div className="rounded-md border border-[#e1e6ed] bg-white p-3">
            <div className="flex items-start gap-2 text-[9px] leading-4 text-[#52627a]"><ShieldCheck className="mt-0.5 size-3.5 shrink-0 text-emerald-600" /><span>API key tetap tersimpan terenkripsi di backend dan tidak dikirim ke frontend pelanggan.</span></div>
          </div>
        </aside>
      </div> : <div className="p-4">
        <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div><h3 className="text-[11px] font-extrabold text-[#243653]">Daftar Kode Game</h3><p className="mt-0.5 text-[9px] text-[#8190a5]">Gunakan kode ini pada Produk → Input Customer → Kode Game Nickname.</p></div>
          <div className="relative w-full sm:w-[280px]"><Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[#8a98aa]" /><input value={query} onChange={(event) => setQuery(event.target.value)} className={`${inputClass} pl-8`} placeholder="Cari game atau kode..." /></div>
        </div>
        <div className="overflow-x-auto rounded-md border border-[#e1e6ed]">
          <table className="min-w-[620px] w-full text-left text-[9px]">
            <thead className="bg-[#f8fafc] text-[#607089]"><tr><th className="px-3 py-2.5 font-bold">Game</th><th className="px-3 py-2.5 font-bold">Game Code</th><th className="px-3 py-2.5 font-bold">Server / Zone</th><th className="px-3 py-2.5 text-right font-bold">Aksi</th></tr></thead>
            <tbody className="divide-y divide-[#edf0f4] bg-white">{filteredCodes.map(([name, code, needsServer]) => <tr key={code}><td className="px-3 py-2.5 font-semibold text-[#34445f]">{name}</td><td className="px-3 py-2.5 font-mono text-[#1769e8]">{code}</td><td className="px-3 py-2.5"><Status tone={needsServer ? "amber" : "gray"}>{needsServer ? "Perlu" : "Tidak"}</Status></td><td className="px-3 py-2.5 text-right"><button type="button" className={buttonClass} onClick={() => void navigator.clipboard.writeText(code)}><Copy className="size-3.5" />Salin</button></td></tr>)}</tbody>
          </table>
        </div>
      </div>}

      <div className="grid gap-2 border-t border-[#edf0f4] bg-[#fbfcfe] p-4 sm:grid-cols-3">
        {docs.map((item) => <a key={item.href} href={item.href} target="_blank" rel="noreferrer" className="flex items-center justify-between gap-2 rounded-md border border-[#e1e6ed] bg-white px-3 py-2.5 text-[9px] font-semibold text-[#42516a] transition hover:border-[#aac8ef] hover:text-[#1769e8]"><span className="min-w-0"><span className="block truncate">{item.label}</span><code className="mt-0.5 block truncate text-[8px] font-normal text-[#8a98aa]">{item.endpoint}</code></span><ExternalLink className="size-3.5 shrink-0" /></a>)}
      </div>
    </Panel>
  </div>;
}

function ResultBox({ tone, text }: { tone: "success" | "error"; text: string }) {
  const success = tone === "success";
  return <div className={`flex items-start gap-2 rounded-md border px-3 py-2.5 text-[9px] font-semibold leading-4 ${success ? "border-emerald-200 bg-emerald-50 text-emerald-700" : "border-rose-200 bg-rose-50 text-rose-700"}`}>{success ? <CheckCircle2 className="mt-0.5 size-3.5 shrink-0" /> : <XCircle className="mt-0.5 size-3.5 shrink-0" />}<span>{text}</span></div>;
}
