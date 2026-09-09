"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { BadgeCheck, LoaderCircle, MessageSquareText, Star } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import type { CustomerSession } from "@/lib/server/customer-auth";
import type { ProductReview } from "@/lib/server/reviews";

export function ProductReviews({ productSlug }: { productSlug: string }) {
  const [reviews, setReviews] = useState<ProductReview[]>([]);
  const [customer, setCustomer] = useState<CustomerSession | null>(null);
  const [rating, setRating] = useState(5);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const response = await fetch(`/api/reviews?product=${encodeURIComponent(productSlug)}`, { cache: "no-store" });
      const data = await response.json().catch(() => ({})) as { reviews?: ProductReview[]; customer?: CustomerSession | null };
      setReviews(response.ok ? data.reviews ?? [] : []);
      setCustomer(response.ok ? data.customer ?? null : null);
    } catch {
      setReviews([]);
      setCustomer(null);
    }
  }, [productSlug]);

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, [load]);
  const average = useMemo(() => reviews.length ? reviews.reduce((sum, item) => sum + item.rating, 0) / reviews.length : 0, [reviews]);

  async function submit(event: FormEvent) {
    event.preventDefault(); setSaving(true); setError(""); setMessage("");
    try {
      const response = await fetch("/api/reviews", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ productSlug, rating, title, body }) });
      const data = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Ulasan gagal disimpan.");
      setBody(""); setTitle(""); setMessage("Ulasanmu berhasil ditampilkan."); await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Ulasan gagal disimpan."); }
    finally { setSaving(false); }
  }

  return (
    <section className="mt-8 rounded-[26px] border border-white/[0.09] bg-[#0d1019] p-5 sm:p-7">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p className="eyebrow">Penilaian pelanggan</p><h2 className="text-2xl font-black">Ulasan & rating</h2><p className="mt-2 text-xs text-white/38">Ulasan hanya dapat dibuat oleh akun yang pernah membeli produk ini.</p></div><div className="rounded-2xl border border-amber-300/15 bg-amber-300/[0.05] px-5 py-3"><span className="flex items-center gap-2 text-xl font-black text-amber-300"><Star className="size-5 fill-current" />{reviews.length ? average.toFixed(1) : "–"}</span><span className="mt-1 block text-[9px] text-white/35">{reviews.length} ulasan</span></div></div>
      <div className="mt-6 grid gap-5 lg:grid-cols-[.82fr_1.18fr]">
        <div>{customer ? <form onSubmit={submit} className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4"><h3 className="text-sm font-bold">Bagikan pengalamanmu</h3><div className="mt-4 flex gap-1">{[1, 2, 3, 4, 5].map((value) => <button key={value} type="button" onClick={() => setRating(value)} aria-label={`${value} bintang`} className={value <= rating ? "text-amber-300" : "text-white/18"}><Star className="size-6 fill-current" /></button>)}</div><Input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Judul singkat (opsional)" className="checkout-input mt-4" /><Textarea required minLength={5} value={body} onChange={(event) => setBody(event.target.value)} placeholder="Ceritakan kecepatan proses dan pengalamanmu..." className="mt-3 min-h-28 rounded-xl border-white/10 bg-white/[0.03] text-sm text-white" />{message && <p className="mt-3 text-xs text-[#cfff72]">{message}</p>}{error && <p className="mt-3 text-xs leading-5 text-red-200">{error}</p>}<Button disabled={saving} className="mt-4 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006]">{saving && <LoaderCircle className="mr-2 size-4 animate-spin" />}Simpan ulasan</Button></form> : <div className="rounded-2xl border border-dashed border-white/10 p-6 text-center"><MessageSquareText className="mx-auto size-7 text-white/20" /><p className="mt-3 text-xs text-white/40">Masuk untuk memberi ulasan setelah pembelian.</p><Button asChild variant="outline" size="sm" className="mt-4 border-white/10 bg-white/[0.03] text-white"><Link href="/login">Masuk / Daftar</Link></Button></div>}</div>
        <div className="space-y-3">{reviews.length ? reviews.map((review) => <article key={review.id} className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4"><div className="flex items-start justify-between gap-4"><div><strong className="text-sm">{review.customerName}</strong>{review.isVerifiedPurchase && <span className="ml-2 inline-flex items-center gap-1 rounded-full bg-[#b9ff35]/10 px-2 py-0.5 text-[8px] font-bold text-[#d8ff8d]"><BadgeCheck className="size-3" />Pembelian terverifikasi</span>}</div><span className="flex items-center gap-1 text-xs font-bold text-amber-300"><Star className="size-3.5 fill-current" />{review.rating}</span></div>{review.title && <h3 className="mt-3 text-xs font-bold">{review.title}</h3>}<p className="mt-2 whitespace-pre-line text-xs leading-6 text-white/48">{review.body}</p><time className="mt-3 block text-[9px] text-white/25">{new Date(review.createdAt).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" })}</time></article>) : <div className="rounded-2xl border border-dashed border-white/10 px-5 py-10 text-center text-xs text-white/32">Belum ada ulasan untuk produk ini.</div>}</div>
      </div>
    </section>
  );
}
