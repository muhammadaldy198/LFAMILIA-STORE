"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Gamepad2, LoaderCircle, Pencil, Plus, Save, Trash2, X } from "lucide-react";
import { useStoreProducts } from "@/hooks/use-store-products";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

type SavedValue = { id: string; label: string; value: string };
type SavedAccount = {
  id: string;
  productSlug: string;
  productName: string;
  label: string;
  nickname: string | null;
  values: SavedValue[];
  updatedAt: string;
};

export function CustomerGameAccounts() {
  const { products } = useStoreProducts();
  const gameProducts = useMemo(() => products.filter((item) => item.category.trim().toLowerCase() === "game"), [products]);
  const [items, setItems] = useState<SavedAccount[]>([]);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [productSlug, setProductSlug] = useState("");
  const [label, setLabel] = useState("");
  const [nickname, setNickname] = useState("");
  const [values, setValues] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const selectedProduct = gameProducts.find((item) => item.slug === productSlug) ?? null;
  const fields = selectedProduct?.inputFields ?? [];

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await fetch("/api/account/game-accounts", { cache: "no-store" });
      const data = await readJson(response) as { accounts?: SavedAccount[]; error?: string };
      if (!response.ok) throw new Error(data.error || "Akun game gagal dimuat.");
      setItems(data.accounts ?? []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Akun game gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  function reset() {
    setEditingId(null); setProductSlug(""); setLabel(""); setNickname(""); setValues({}); setError("");
  }

  function edit(item: SavedAccount) {
    setEditingId(item.id);
    setProductSlug(item.productSlug);
    setLabel(item.label);
    setNickname(item.nickname ?? "");
    setValues(Object.fromEntries(item.values.map((value) => [value.id, value.value])));
    setMessage(""); setError("");
  }

  async function save() {
    if (!selectedProduct) { setError("Pilih game terlebih dahulu."); return; }
    setSaving(true); setError(""); setMessage("");
    try {
      const body = {
        ...(editingId ? { id: editingId } : {}),
        productSlug,
        label,
        nickname,
        values: fields.map((field) => ({ id: field.id, value: values[field.id] ?? "" })),
      };
      const response = await fetch("/api/account/game-accounts", {
        method: editingId ? "PATCH" : "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(body),
      });
      const data = await readJson(response) as { error?: string };
      if (!response.ok) throw new Error(data.error || "Akun game gagal disimpan.");
      setMessage(editingId ? "Akun game diperbarui." : "Akun game tersimpan.");
      reset();
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Akun game gagal disimpan.");
    } finally {
      setSaving(false);
    }
  }

  async function remove(id: string) {
    setError(""); setMessage("");
    const response = await fetch(`/api/account/game-accounts?id=${encodeURIComponent(id)}`, { method: "DELETE" });
    const data = await response.json() as { error?: string };
    if (!response.ok) { setError(data.error || "Akun game gagal dihapus."); return; }
    if (editingId === id) reset();
    setMessage("Akun game dihapus.");
    await load();
  }

  return <div className="space-y-4">
    {message && <p className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] p-3 text-xs text-[#d8ff8d]">{message}</p>}
    {error && <p className="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</p>}

    <section className="rounded-2xl border border-white/[0.08] bg-[#0d1019] p-4">
      <div className="flex items-start justify-between gap-3">
        <div><h2 className="text-sm font-bold">Simpan akun game</h2><p className="mt-1 text-[10px] leading-4 text-white/35">User ID dan server dapat dipilih kembali saat checkout.</p></div>
        {editingId && <Button type="button" size="sm" variant="ghost" onClick={reset} className="text-white/50"><X className="mr-1 size-3.5" />Batal</Button>}
      </div>
      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        <label><span className="field-label">Game</span><select value={productSlug} onChange={(event) => { setProductSlug(event.target.value); setValues({}); }} className="admin-select h-10 w-full text-xs"><option value="">Pilih game</option>{gameProducts.map((product) => <option key={product.slug} value={product.slug}>{product.name}</option>)}</select></label>
        <label><span className="field-label">Nama akun</span><Input value={label} onChange={(event) => setLabel(event.target.value)} className="checkout-input" placeholder="Contoh: ML Main" /></label>
        {fields.map((field) => <label key={field.id}><span className="field-label">{field.label}</span><Input value={values[field.id] ?? ""} onChange={(event) => setValues((current) => ({ ...current, [field.id]: event.target.value }))} className="checkout-input" placeholder={field.placeholder ?? ""} /></label>)}
        <label><span className="field-label">Nickname (opsional)</span><Input value={nickname} onChange={(event) => setNickname(event.target.value)} className="checkout-input" placeholder="Nama karakter" /></label>
      </div>
      <Button type="button" onClick={() => void save()} disabled={saving} className="mt-4 h-10 rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006]">{saving ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : editingId ? <Save className="mr-2 size-4" /> : <Plus className="mr-2 size-4" />}{editingId ? "Simpan perubahan" : "Tambah akun"}</Button>
    </section>

    <section className="rounded-2xl border border-white/[0.08] bg-[#0d1019] p-4">
      <h2 className="text-sm font-bold">Akun tersimpan</h2>
      {loading ? <div className="flex min-h-24 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat…</div> :
      items.length === 0 ? <p className="mt-4 rounded-xl border border-dashed border-white/10 p-5 text-center text-xs text-white/32">Belum ada akun game tersimpan.</p> :
      <div className="mt-3 space-y-2">{items.map((item) => <div key={item.id} className="flex items-start gap-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#d8ff8d]"><Gamepad2 className="size-4" /></span>
        <div className="min-w-0 flex-1"><p className="text-xs font-bold">{item.label} <span className="font-normal text-white/35">· {item.productName}</span></p><p className="mt-1 truncate text-[10px] text-white/40">{item.values.map((value) => `${value.label}: ${value.value}`).join(" · ")}{item.nickname ? ` · ${item.nickname}` : ""}</p></div>
        <Button type="button" size="icon-sm" variant="ghost" onClick={() => edit(item)} className="text-white/60"><Pencil className="size-3.5" /></Button>
        <Button type="button" size="icon-sm" variant="ghost" onClick={() => void remove(item.id)} className="text-red-300"><Trash2 className="size-3.5" /></Button>
      </div>)}</div>}
    </section>
  </div>;
}


async function readJson(response: Response): Promise<Record<string, unknown>> {
  const raw = await response.text();
  if (!raw) return { error: "Respons server kosong." };
  try { return JSON.parse(raw) as Record<string, unknown>; }
  catch { return { error: "Respons server tidak valid." }; }
}
