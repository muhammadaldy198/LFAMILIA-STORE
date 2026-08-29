"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Edit3, Flame, LoaderCircle, Plus, Save, TicketPercent, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { formatRupiah } from "@/lib/store-data";
import type { DiscountVoucher, FlashSale } from "@/lib/server/promotions";
import type { ManagedProduct } from "@/lib/server/products";

type VoucherDraft = Omit<DiscountVoucher, "usedCount" | "startsAt" | "endsAt"> & { startsAt: string; endsAt: string };
type FlashDraft = Pick<FlashSale, "id" | "productSlug" | "packageSku" | "salePrice" | "badge" | "stockLimit" | "isActive"> & { startsAt: string; endsAt: string };

function initialDates() { const start = new Date(); const end = new Date(start.getTime() + 7 * 86400000); return { startsAt: localDate(start), endsAt: localDate(end) }; }
function emptyVoucher(): VoucherDraft { return { id: 0, code: "", name: "", description: "", discountType: "fixed", discountValue: 10000, minPurchase: 50000, maxDiscount: null, usageLimit: null, isActive: true, ...initialDates() }; }
function emptyFlash(): FlashDraft { return { id: 0, productSlug: "", packageSku: "", salePrice: 0, badge: "Flash Sale", stockLimit: null, isActive: true, ...initialDates() }; }

export function AdminPromotionManager({ role }: { role: "owner" | "staff" }) {
  const [vouchers, setVouchers] = useState<DiscountVoucher[]>([]);
  const [flashSales, setFlashSales] = useState<FlashSale[]>([]);
  const [products, setProducts] = useState<ManagedProduct[]>([]);
  const [voucher, setVoucher] = useState<VoucherDraft>(emptyVoucher());
  const [flash, setFlash] = useState<FlashDraft>(emptyFlash());
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const packages = useMemo(() => products.find((item) => item.slug === flash.productSlug)?.packages ?? [], [flash.productSlug, products]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [promotionResponse, productResponse] = await Promise.all([fetch("/api/admin/promotions", { cache: "no-store" }), fetch("/api/admin/products", { cache: "no-store" })]);
      const [promotionData, productData] = await Promise.all([promotionResponse.json(), productResponse.json()]);
      if (!promotionResponse.ok) throw new Error(promotionData.error);
      setVouchers(promotionData.vouchers ?? []); setFlashSales(promotionData.flashSales ?? []); setProducts(productData.products ?? []);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Promo gagal dimuat."); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, [load]);

  async function saveVoucher() {
    setSaving("voucher"); setError("");
    try {
      const payload = { ...voucher, id: voucher.id || undefined, kind: "voucher", startsAt: new Date(voucher.startsAt).toISOString(), endsAt: new Date(voucher.endsAt).toISOString() };
      const response = await fetch("/api/admin/promotions", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(payload) });
      const data = await response.json(); if (!response.ok) throw new Error(data.error);
      setVoucher(emptyVoucher()); setMessage("Voucher diskon berhasil disimpan."); await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Voucher gagal disimpan."); }
    finally { setSaving(""); }
  }

  async function saveFlash() {
    setSaving("flash"); setError("");
    try {
      const payload = { ...flash, id: flash.id || undefined, kind: "flash", startsAt: new Date(flash.startsAt).toISOString(), endsAt: new Date(flash.endsAt).toISOString() };
      const response = await fetch("/api/admin/promotions", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(payload) });
      const data = await response.json(); if (!response.ok) throw new Error(data.error);
      setFlash(emptyFlash()); setMessage("Flash sale berhasil disimpan."); await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Flash sale gagal disimpan."); }
    finally { setSaving(""); }
  }

  async function remove(kind: "voucher" | "flash", id: number) { const response = await fetch(`/api/admin/promotions?kind=${kind}&id=${id}`, { method: "DELETE" }); const data = await response.json(); if (!response.ok) { setError(data.error); return; } setMessage("Promo berhasil dihapus."); await load(); }

  if (loading) return <div className="flex min-h-56 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat promo…</div>;
  return <div className="space-y-6">{message && <div className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">{message}</div>}{error && <div className="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
    {role === "owner" && <div className="grid gap-5 xl:grid-cols-2"><section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4"><h3 className="flex items-center gap-2 font-bold"><TicketPercent className="size-4 text-[#b9ff35]" />Voucher diskon</h3><div className="mt-4 grid gap-3 sm:grid-cols-2"><Field label="Kode"><Input value={voucher.code} onChange={(event) => setVoucher({ ...voucher, code: event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, "") })} className="admin-input font-mono" placeholder="HEMAT10" /></Field><Field label="Nama promo"><Input value={voucher.name} onChange={(event) => setVoucher({ ...voucher, name: event.target.value })} className="admin-input" /></Field><Field label="Jenis"><select value={voucher.discountType} onChange={(event) => setVoucher({ ...voucher, discountType: event.target.value as "fixed" | "percentage" })} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs"><option value="fixed">Potongan rupiah</option><option value="percentage">Persentase</option></select></Field><Field label={voucher.discountType === "fixed" ? "Nilai potongan" : "Persentase"}><Input type="number" min={1} value={voucher.discountValue} onChange={(event) => setVoucher({ ...voucher, discountValue: Number(event.target.value) })} className="admin-input" /></Field><Field label="Minimum transaksi"><Input type="number" min={0} value={voucher.minPurchase} onChange={(event) => setVoucher({ ...voucher, minPurchase: Number(event.target.value) })} className="admin-input" /></Field><Field label="Maks. potongan"><Input type="number" min={1} value={voucher.maxDiscount ?? ""} onChange={(event) => setVoucher({ ...voucher, maxDiscount: event.target.value ? Number(event.target.value) : null })} className="admin-input" placeholder="Tanpa batas" /></Field><Field label="Kuota"><Input type="number" min={1} value={voucher.usageLimit ?? ""} onChange={(event) => setVoucher({ ...voucher, usageLimit: event.target.value ? Number(event.target.value) : null })} className="admin-input" placeholder="Tanpa batas" /></Field><label className="flex items-center justify-between rounded-xl border border-white/[0.08] px-3 text-xs text-white/55"><span>Aktif</span><Switch checked={voucher.isActive} onCheckedChange={(checked) => setVoucher({ ...voucher, isActive: checked })} /></label><Field label="Mulai"><Input type="datetime-local" value={voucher.startsAt} onChange={(event) => setVoucher({ ...voucher, startsAt: event.target.value })} className="admin-input" /></Field><Field label="Berakhir"><Input type="datetime-local" value={voucher.endsAt} onChange={(event) => setVoucher({ ...voucher, endsAt: event.target.value })} className="admin-input" /></Field><Field label="Deskripsi" wide><Textarea value={voucher.description} onChange={(event) => setVoucher({ ...voucher, description: event.target.value })} className="min-h-20 rounded-xl border-white/10 bg-white/[0.025] text-xs text-white" /></Field></div><div className="mt-4 flex justify-end gap-2">{voucher.id > 0 && <Button variant="outline" onClick={() => setVoucher(emptyVoucher())} className="border-white/10 bg-white/[0.03] text-white">Batal</Button>}<Button onClick={() => void saveVoucher()} disabled={saving === "voucher"} className="bg-[#b9ff35] font-black text-[#091006]"><Save className="mr-2 size-4" />Simpan voucher</Button></div></section>
    <section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4"><h3 className="flex items-center gap-2 font-bold"><Flame className="size-4 text-red-300" />Flash sale</h3><div className="mt-4 grid gap-3 sm:grid-cols-2"><Field label="Produk" wide><select value={flash.productSlug} onChange={(event) => setFlash({ ...flash, productSlug: event.target.value, packageSku: "" })} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs"><option value="">Pilih produk</option>{products.map((item) => <option key={item.slug} value={item.slug}>{item.name}</option>)}</select></Field><Field label="Nominal" wide><select value={flash.packageSku} onChange={(event) => setFlash({ ...flash, packageSku: event.target.value })} className="h-10 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-xs"><option value="">Pilih nominal</option>{packages.map((item) => <option key={item.id} value={item.id}>{item.label} — {formatRupiah(item.price)}</option>)}</select></Field><Field label="Harga flash sale"><Input type="number" min={1} value={flash.salePrice || ""} onChange={(event) => setFlash({ ...flash, salePrice: Number(event.target.value) })} className="admin-input" /></Field><Field label="Badge"><Input value={flash.badge} onChange={(event) => setFlash({ ...flash, badge: event.target.value })} className="admin-input" /></Field><Field label="Stok promo"><Input type="number" min={1} value={flash.stockLimit ?? ""} onChange={(event) => setFlash({ ...flash, stockLimit: event.target.value ? Number(event.target.value) : null })} className="admin-input" placeholder="Tanpa batas" /></Field><label className="flex items-center justify-between rounded-xl border border-white/[0.08] px-3 text-xs text-white/55"><span>Aktif</span><Switch checked={flash.isActive} onCheckedChange={(checked) => setFlash({ ...flash, isActive: checked })} /></label><Field label="Mulai"><Input type="datetime-local" value={flash.startsAt} onChange={(event) => setFlash({ ...flash, startsAt: event.target.value })} className="admin-input" /></Field><Field label="Berakhir"><Input type="datetime-local" value={flash.endsAt} onChange={(event) => setFlash({ ...flash, endsAt: event.target.value })} className="admin-input" /></Field></div><div className="mt-4 flex justify-end gap-2">{flash.id > 0 && <Button variant="outline" onClick={() => setFlash(emptyFlash())} className="border-white/10 bg-white/[0.03] text-white">Batal</Button>}<Button onClick={() => void saveFlash()} disabled={saving === "flash"} className="bg-[#b9ff35] font-black text-[#091006]"><Save className="mr-2 size-4" />Simpan flash sale</Button></div></section></div>}
    <div className="grid gap-5 xl:grid-cols-2"><section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4"><div className="flex items-center justify-between"><h3 className="font-bold">Daftar voucher</h3><span className="text-[10px] text-white/30">{vouchers.length} voucher</span></div><div className="mt-4 space-y-2">{vouchers.length ? vouchers.map((item) => <div key={item.id} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3"><div className="flex items-start justify-between gap-3"><div><strong className="font-mono text-xs text-[#d8ff8d]">{item.code}</strong><p className="mt-1 text-[10px] text-white/35">{item.name} • terpakai {item.usedCount}{item.usageLimit ? `/${item.usageLimit}` : ""}</p></div>{role === "owner" && <div className="flex"><Button size="icon-sm" variant="ghost" onClick={() => setVoucher({ ...item, startsAt: localDate(new Date(item.startsAt)), endsAt: localDate(new Date(item.endsAt)) })} className="text-white/50"><Edit3 className="size-3.5" /></Button><Button size="icon-sm" variant="ghost" onClick={() => void remove("voucher", item.id)} className="text-red-300"><Trash2 className="size-3.5" /></Button></div>}</div></div>) : <Empty />}</div></section><section className="rounded-2xl border border-white/[0.08] bg-white/[0.015] p-4"><div className="flex items-center justify-between"><h3 className="font-bold">Daftar flash sale</h3><span className="text-[10px] text-white/30">{flashSales.length} promo</span></div><div className="mt-4 space-y-2">{flashSales.length ? flashSales.map((item) => <div key={item.id} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3"><div className="flex items-start justify-between gap-3"><div><strong className="text-xs">{item.productName}</strong><p className="mt-1 text-[10px] text-white/35">{item.packageLabel} • {formatRupiah(item.salePrice)}</p></div>{role === "owner" && <div className="flex"><Button size="icon-sm" variant="ghost" onClick={() => setFlash({ id: item.id, productSlug: item.productSlug, packageSku: item.packageSku, salePrice: item.salePrice, badge: item.badge, stockLimit: item.stockLimit, isActive: item.isActive, startsAt: localDate(new Date(item.startsAt)), endsAt: localDate(new Date(item.endsAt)) })} className="text-white/50"><Edit3 className="size-3.5" /></Button><Button size="icon-sm" variant="ghost" onClick={() => void remove("flash", item.id)} className="text-red-300"><Trash2 className="size-3.5" /></Button></div>}</div></div>) : <Empty />}</div></section></div>
  </div>;
}

function Field({ label, wide = false, children }: { label: string; wide?: boolean; children: React.ReactNode }) { return <label className={wide ? "sm:col-span-2" : ""}><span className="field-label">{label}</span>{children}</label>; }
function Empty() { return <div className="rounded-xl border border-dashed border-white/10 py-7 text-center text-[10px] text-white/28"><Plus className="mx-auto mb-2 size-4" />Belum ada data.</div>; }
function localDate(date: Date) { const offset = date.getTimezoneOffset() * 60000; return new Date(date.getTime() - offset).toISOString().slice(0, 16); }
