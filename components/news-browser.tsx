"use client";
/* eslint-disable @next/next/no-img-element */

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowRight, LoaderCircle, Newspaper } from "lucide-react";
import type { NewsRecord } from "@/lib/server/content";

export function NewsBrowser() {
  const [articles, setArticles] = useState<NewsRecord[]>([]); const [loading, setLoading] = useState(true);
  useEffect(() => { void fetch("/api/news").then((response) => response.json().catch(() => ({}))).then((data: { articles?: NewsRecord[] }) => setArticles(data.articles ?? [])).catch(() => setArticles([])).finally(() => setLoading(false)); }, []);
  if (loading) return <div className="flex min-h-44 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat berita…</div>;
  if (!articles.length) return <div className="rounded-[26px] border border-dashed border-white/10 py-16 text-center"><Newspaper className="mx-auto size-9 text-white/18" /><h2 className="mt-4 font-bold text-white/50">Belum ada berita</h2><p className="mt-2 text-xs text-white/30">Info produk, promo, dan pengumuman akan tampil di sini.</p></div>;
  return <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{articles.map((article) => <Link key={article.id ?? article.slug} href={`/news/${article.slug}`} className="group overflow-hidden rounded-[24px] border border-white/[0.09] bg-[#0d1019] transition hover:-translate-y-1 hover:border-[#b9ff35]/30"><div className="relative aspect-[16/9] overflow-hidden bg-gradient-to-br from-[#253650] to-[#10131b]">{article.coverUrl ? <img src={article.coverUrl} alt={article.title} className="size-full object-cover transition duration-500 group-hover:scale-105" /> : <div className="grid size-full place-items-center"><Newspaper className="size-10 text-white/15" /></div>}<div className="absolute inset-0 bg-gradient-to-t from-black/45 to-transparent" /></div><div className="p-5"><time className="text-[9px] uppercase tracking-wider text-[#cfff72]">{article.publishedAt ? new Date(article.publishedAt).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "Berita"}</time><h2 className="mt-3 line-clamp-2 text-lg font-black leading-6">{article.title}</h2><p className="mt-3 line-clamp-3 text-xs leading-5 text-white/38">{article.summary}</p><span className="mt-5 inline-flex items-center gap-2 text-xs font-bold text-[#cfff72]">Baca selengkapnya<ArrowRight className="size-4" /></span></div></Link>)}</div>;
}
