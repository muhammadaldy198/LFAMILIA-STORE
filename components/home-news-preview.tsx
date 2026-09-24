"use client";
/* eslint-disable @next/next/no-img-element */

import Link from "next/link";
import { ArrowRight, Newspaper } from "lucide-react";
import { useEffect, useState } from "react";
import type { NewsRecord } from "@/lib/server/content";

async function readNews() {
  const response = await fetch("/api/news");
  const raw = await response.text();
  if (!response.ok || !raw) throw new Error("Berita gagal dimuat.");
  try {
    const data = JSON.parse(raw) as { articles?: NewsRecord[] };
    return data.articles ?? [];
  } catch {
    throw new Error("Respons berita tidak valid.");
  }
}

export function HomeNewsPreview() {
  const [articles, setArticles] = useState<NewsRecord[]>([]);
  const [loadError, setLoadError] = useState("");
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let active = true;
    void readNews()
      .then((items) => { if (active) { setArticles(items); setLoadError(""); } })
      .catch((reason) => { if (active) setLoadError(reason instanceof Error ? reason.message : "Berita gagal dimuat."); });
    return () => { active = false; };
  }, [reloadKey]);

  if (!articles.length) {
    if (!loadError) return null;
    return <section className="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8"><div className="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/[0.025] px-4 py-3 text-[10px] text-white/40"><span>{loadError}</span><button type="button" onClick={() => setReloadKey((value) => value + 1)} className="font-bold text-[#d8ff8d]">Muat ulang</button></div></section>;
  }
  const shown = articles.slice(0, 3);

  return (
    <section className="mx-auto max-w-7xl px-4 py-[34px] sm:px-6 sm:py-[44px] lg:px-8">
      <div className="max-w-3xl">
        <p className="eyebrow">LFAMILIA News</p>
        <h2 className="text-balance text-[22px] font-black leading-tight tracking-[-0.03em] sm:text-[28px]">
          Info gaming, promo, dan update terbaru buat kamu.
        </h2>
        <p className="mt-[7px] max-w-2xl text-[11px] leading-[1.55] text-white/42 sm:text-xs">
          Temukan info produk, pembaruan game, pengumuman layanan, dan promo LFAMILIA yang sedang berjalan.
        </p>
      </div>

      <div className="mt-[16px] grid gap-[10px] sm:grid-cols-2 lg:grid-cols-3 sm:gap-[12px]">
        {shown.map((article) => (
          <Link
            key={article.id ?? article.slug}
            href={`/news/${article.slug}`}
            className="group relative h-[205px] overflow-hidden rounded-[11px] border border-white/[0.09] bg-[#0d1019] sm:h-[225px] lg:h-[235px]"
          >
            {article.coverUrl ? (
              <img
                src={article.coverUrl}
                alt=""
                className="absolute inset-0 size-full object-cover transition duration-500 group-hover:scale-[1.025]"
              />
            ) : (
              <div className="absolute inset-0 bg-gradient-to-br from-[#243651] to-[#0d1019]" />
            )}
            <div className="absolute inset-0 bg-gradient-to-t from-black via-black/58 to-black/10" />

            <div className="relative flex h-full flex-col justify-end p-[12px] sm:p-[14px]">
              <span className="mb-auto grid size-[30px] place-items-center rounded-[7px] border border-white/10 bg-black/35 text-[#cfff72] backdrop-blur">
                <Newspaper className="size-[13px]" />
              </span>
              <time className="text-[8px] font-bold uppercase tracking-[0.12em] text-[#d8ff8d]">
                {article.publishedAt
                  ? new Date(article.publishedAt).toLocaleDateString("id-ID", {
                      day: "numeric",
                      month: "short",
                      year: "numeric",
                    })
                  : "Berita LFAMILIA"}
              </time>
              <h3 className="mt-[5px] line-clamp-2 text-[15px] font-black leading-[1.28] sm:text-[17px]">
                {article.title}
              </h3>
              <p className="mt-[5px] line-clamp-2 text-[10px] leading-[1.45] text-white/62 sm:text-[11px]">
                {article.summary}
              </p>
              <span className="mt-[8px] inline-flex items-center gap-[5px] text-[9px] font-bold text-[#d8ff8d]">
                Baca artikel
                <ArrowRight className="size-[12px] transition group-hover:translate-x-1" />
              </span>
            </div>
          </Link>
        ))}
      </div>

      <Link
        href="/news"
        className="mt-[14px] inline-flex h-[32px] items-center gap-[6px] rounded-[8px] border border-white/10 bg-white/[0.035] px-[12px] text-[9px] font-black text-white transition hover:border-[#b9ff35]/35 hover:bg-[#b9ff35]/10 hover:text-[#d8ff8d]"
      >
        Lihat semua artikel
        <ArrowRight className="size-[12px]" />
      </Link>
    </section>
  );
}
