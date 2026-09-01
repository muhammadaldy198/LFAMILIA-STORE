"use client";
/* eslint-disable @next/next/no-img-element */

import Link from "next/link";
import { ArrowRight, Newspaper } from "lucide-react";
import { useEffect, useState } from "react";
import type { NewsRecord } from "@/lib/server/content";

async function readNews() {
  const response = await fetch("/api/news", { cache: "no-store" });
  const raw = await response.text();
  if (!response.ok || !raw) return [];
  try {
    const data = JSON.parse(raw) as { articles?: NewsRecord[] };
    return data.articles ?? [];
  } catch {
    return [];
  }
}

export function HomeNewsPreview() {
  const [articles, setArticles] = useState<NewsRecord[]>([]);

  useEffect(() => { void readNews().then(setArticles); }, []);

  if (!articles.length) return null;
  const shown = articles.slice(0, 3);

  return (
    <section className="mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
      <div className="max-w-3xl">
        <p className="eyebrow">LFAMILIA News</p>
        <h2 className="mt-3 text-balance text-2xl font-black uppercase leading-tight tracking-tight sm:text-4xl">Info gaming, promo, dan update terbaru buat kamu.</h2>
        <p className="mt-4 text-sm leading-6 text-white/45 sm:text-base">Temukan info produk, pembaruan game, pengumuman layanan, dan promo LFAMILIA yang sedang berjalan.</p>
      </div>

      <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 sm:gap-5">
        {shown.map((article) => (
          <Link key={article.id ?? article.slug} href={`/news/${article.slug}`} className="group relative min-h-80 overflow-hidden rounded-[26px] border border-white/[0.09] bg-[#0d1019] p-5 sm:min-h-96 sm:p-6">
            {article.coverUrl ? <img src={article.coverUrl} alt="" className="absolute inset-0 size-full object-cover transition duration-500 group-hover:scale-105" /> : <div className="absolute inset-0 bg-gradient-to-br from-[#243651] to-[#0d1019]" />}
            <div className="absolute inset-0 bg-gradient-to-t from-black via-black/55 to-black/10" />
            <div className="relative flex h-full flex-col justify-end">
              <span className="mb-auto grid size-10 place-items-center rounded-xl border border-white/10 bg-black/35 text-[#cfff72] backdrop-blur"><Newspaper className="size-4" /></span>
              <time className="text-[10px] font-bold uppercase tracking-[0.14em] text-[#d8ff8d]">{article.publishedAt ? new Date(article.publishedAt).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" }) : "Berita LFAMILIA"}</time>
              <h3 className="mt-3 line-clamp-3 text-xl font-black leading-7 sm:text-2xl sm:leading-8">{article.title}</h3>
              <p className="mt-3 line-clamp-2 text-sm leading-6 text-white/70">{article.summary}</p>
              <span className="mt-5 inline-flex items-center gap-2 text-xs font-bold text-[#d8ff8d]">Baca artikel <ArrowRight className="size-4 transition group-hover:translate-x-1" /></span>
            </div>
          </Link>
        ))}
      </div>

      <Link href="/news" className="mt-7 inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.035] px-5 py-3 text-xs font-black text-white transition hover:border-[#b9ff35]/35 hover:bg-[#b9ff35]/10 hover:text-[#d8ff8d]">Lihat semua artikel <ArrowRight className="size-4" /></Link>
    </section>
  );
}
