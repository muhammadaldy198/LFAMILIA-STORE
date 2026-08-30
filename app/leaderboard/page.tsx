import { Trophy } from "lucide-react";
import { LeaderboardBoard } from "@/components/leaderboard-board";
import { StoreLayout } from "@/components/store-layout";

export default function LeaderboardPage() {
  return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-5xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8"><div className="flex items-start gap-4"><span className="grid size-12 shrink-0 place-items-center rounded-2xl border border-amber-300/20 bg-amber-300/[0.08] text-amber-300"><Trophy className="size-6" /></span><div><p className="eyebrow">Komunitas LFAMILIA</p><h1 className="section-title">Leaderboard pelanggan</h1><p className="mt-3 max-w-2xl text-sm leading-6 text-white/42">Peringkat berdasarkan total transaksi berhasil. Email, nomor telepon, dan data game tidak pernah ditampilkan.</p></div></div><div className="mt-9"><LeaderboardBoard /></div></main></StoreLayout>;
}
