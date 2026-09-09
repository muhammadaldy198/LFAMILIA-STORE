"use client";
/* eslint-disable @next/next/no-img-element */

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, LoaderCircle, Newspaper } from "lucide-react";
import { useParams } from "next/navigation";
import type { NewsRecord } from "@/lib/server/content";

export function NewsDetail() {
  const params = useParams<{ slug: string }>(); const slug = String(params?.slug ?? ""); const [article, setArticle] = useState<NewsRecord | null>(null); const [loading, setLoading] = useState(true);
  useEffect(() => { if (!slug) { setLoading(false); return; } void fetch(`/api/news?slug=${encodeURIComponent(slug)}`, { cache: "no-store" }).then(async (response) => response.ok ? response.json().catch(() => null) : null).then((data: { article?: NewsRecord } | null) => setArticle(data?.article ?? null)).catch(() => setArticle(null)).finally(() => setLoading(false)); }, [slug]);
  if (loading) return <div className="flex min-h-[55vh] items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat berita…</div>;
  if (!article) return <div className="grid min-h-[55vh] place-items-center text-center"><div><Newspaper className="mx-auto size-9 text-white/20" /><h1 className="mt-4 text-xl font-black">Berita tidak ditemukan</h1><Link href="/news" className="mt-4 inline-flex text-xs font-bold text-[#cfff72]">Kembali ke berita</Link></div></div>;
  return <article><Link href="/news" className="inline-flex items-center gap-2 text-xs font-bold text-white/42 hover:text-white"><ArrowLeft className="size-4" />Semua berita</Link>{article.coverUrl && <div className="mt-6 aspect-[16/7] overflow-hidden rounded-[28px] border border-white/10"><img src={article.coverUrl} alt={article.title} className="size-full object-cover" /></div>}<div className="mx-auto max-w-3xl py-8"><time className="text-[10px] font-bold uppercase tracking-[0.16em] text-[#cfff72]">{article.publishedAt ? new Date(article.publishedAt).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "Berita LFAMILIA"}</time><h1 className="mt-4 text-balance text-3xl font-black leading-tight tracking-[-0.04em] sm:text-5xl">{article.title}</h1>{article.summary && <p className="mt-5 text-base leading-7 text-white/52">{article.summary}</p>}<div className="mt-8 h-px bg-white/[0.08]" /><div className="mt-8 whitespace-pre-line text-sm leading-8 text-white/66">{article.body}</div></div></article>;
}
