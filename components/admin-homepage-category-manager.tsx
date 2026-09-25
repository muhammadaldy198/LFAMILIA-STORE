"use client";
/* eslint-disable react-hooks/set-state-in-effect */

import { useCallback, useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Plus, RefreshCw, Save, Trash2 } from "lucide-react";

type Category = {
  id: number | null;
  slug: string;
  name: string;
  icon: string;
  isActive: boolean;
  sortOrder: number;
};

const iconOptions = [
  ["gamepad", "Game"],
  ["ticket", "Voucher"],
  ["play", "Entertainment"],
  ["smartphone", "Pulsa"],
  ["zap", "PLN / Listrik"],
  ["grid", "Umum"],
];

export function AdminHomepageCategoryManager() {
  const [categories, setCategories] = useState<Category[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setError("");
    const response = await fetch("/api/panel/categories", { cache: "no-store" });
    const payload = await response.json().catch(() => ({})) as { categories?: Category[]; error?: string };
    if (!response.ok) {
      setError(payload.error || "Kategori homepage gagal dimuat.");
      return;
    }
    setCategories((payload.categories || []).sort((a, b) => a.sortOrder - b.sortOrder || a.name.localeCompare(b.name)));
  }, []);

  useEffect(() => { void load(); }, [load]);

  function update(index: number, patch: Partial<Category>) {
    setCategories((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item));
  }

  function move(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= categories.length) return;
    setCategories((current) => {
      const next = [...current];
      [next[index], next[target]] = [next[target], next[index]];
      return next.map((item, sortOrder) => ({ ...item, sortOrder }));
    });
  }

  function add() {
    const suffix = categories.length + 1;
    setCategories((current) => [
      ...current,
      { id: null, slug: `kategori-${suffix}`, name: `Kategori ${suffix}`, icon: "grid", isActive: true, sortOrder: current.length },
    ]);
  }

  async function saveAll() {
    setBusy(true); setError(""); setMessage("");
    try {
      const normalized = categories.map((item, sortOrder) => ({
        ...item,
        slug: item.slug.trim().toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""),
        name: item.name.trim(),
        sortOrder,
      }));
      if (normalized.some((item) => !item.slug || item.name.length < 2)) throw new Error("Nama dan slug kategori wajib valid.");
      if (new Set(normalized.map((item) => item.slug)).size !== normalized.length) throw new Error("Slug kategori tidak boleh duplikat.");

      for (const item of normalized) {
        const response = await fetch("/api/panel/categories", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(item),
        });
        const payload = await response.json().catch(() => ({})) as { error?: string };
        if (!response.ok) throw new Error(payload.error || `Kategori ${item.name} gagal disimpan.`);
      }
      setMessage("Kategori homepage berhasil disimpan. Urutan, nama, ikon, dan status langsung dipakai frontend customer.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Kategori homepage gagal disimpan.");
    } finally { setBusy(false); }
  }

  async function remove(index: number) {
    const item = categories[index];
    if (!item) return;
    if (!item.id) {
      setCategories((current) => current.filter((_, itemIndex) => itemIndex !== index).map((entry, sortOrder) => ({ ...entry, sortOrder })));
      return;
    }
    if (!window.confirm(`Hapus kategori "${item.name}"? Kategori yang masih dipakai produk akan ditolak backend.`)) return;
    setBusy(true); setError(""); setMessage("");
    try {
      const response = await fetch(`/api/panel/categories?id=${item.id}`, { method: "DELETE" });
      const payload = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(payload.error || "Kategori gagal dihapus.");
      setMessage(`${item.name} dihapus.`);
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Kategori gagal dihapus.");
    } finally { setBusy(false); }
  }

  return (
    <section className="mt-5 overflow-hidden rounded-lg border border-[#dfe6ef] bg-white text-[#14213a] shadow-[0_1px_4px_rgba(20,33,58,.04)]">
      <header className="flex flex-col gap-3 border-b border-[#e7ebf0] p-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-[14px] font-extrabold">Kategori Homepage</h2>
          <p className="mt-1 text-[9px] leading-4 text-[#718198]">Atur tombol kategori di atas daftar produk customer. Bisa tambah, ubah nama/ikon, aktif/nonaktif, hapus, dan ubah urutan.</p>
        </div>
        <div className="flex gap-2">
          <button type="button" onClick={() => void load()} disabled={busy} className="inline-flex h-8 items-center gap-1.5 rounded border border-[#dce3eb] px-3 text-[8px] font-bold"><RefreshCw className="size-3" />Refresh</button>
          <button type="button" onClick={add} disabled={busy} className="inline-flex h-8 items-center gap-1.5 rounded border border-[#cfe0f2] bg-[#f7fbff] px-3 text-[8px] font-bold text-[#0875ed]"><Plus className="size-3" />Tambah</button>
          <button type="button" onClick={() => void saveAll()} disabled={busy} className="inline-flex h-8 items-center gap-1.5 rounded bg-[#0875ed] px-3 text-[8px] font-bold text-white disabled:opacity-50"><Save className="size-3" />{busy ? "Menyimpan..." : "Simpan"}</button>
        </div>
      </header>

      {message && <p className="m-3 rounded border border-emerald-200 bg-emerald-50 p-2.5 text-[8px] text-emerald-700">{message}</p>}
      {error && <p className="m-3 rounded border border-rose-200 bg-rose-50 p-2.5 text-[8px] text-rose-700">{error}</p>}

      <div className="overflow-x-auto">
        <table className="w-full min-w-[760px] text-left">
          <thead className="bg-[#f6f8fb] text-[7px] uppercase text-[#718198]">
            <tr><th className="px-3 py-2">#</th><th className="px-3">Nama</th><th className="px-3">Slug</th><th className="px-3">Ikon</th><th className="px-3">Tampil</th><th className="px-3">Urutan</th><th className="px-3">Aksi</th></tr>
          </thead>
          <tbody className="divide-y divide-[#edf0f4]">
            {categories.map((item, index) => (
              <tr key={item.id ?? `new-${index}`} className="text-[8px] text-[#34465e]">
                <td className="px-3 py-2.5 font-bold">{index + 1}</td>
                <td className="px-3"><input value={item.name} onChange={(event) => update(index, { name: event.target.value })} className="h-8 w-full rounded border border-[#dce3eb] px-2" /></td>
                <td className="px-3"><input value={item.slug} onChange={(event) => update(index, { slug: event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "") })} className="h-8 w-full rounded border border-[#dce3eb] px-2 font-mono" /></td>
                <td className="px-3"><select value={item.icon} onChange={(event) => update(index, { icon: event.target.value })} className="h-8 w-full rounded border border-[#dce3eb] bg-white px-2">{iconOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></td>
                <td className="px-3"><button type="button" onClick={() => update(index, { isActive: !item.isActive })} className={`relative h-[20px] w-[36px] rounded-full transition ${item.isActive ? "bg-[#0875ed]" : "bg-[#cbd5e1]"}`}><span className={`absolute top-[2px] size-4 rounded-full bg-white shadow transition ${item.isActive ? "left-[18px]" : "left-[2px]"}`} /></button></td>
                <td className="px-3"><div className="flex gap-1"><button type="button" disabled={index === 0} onClick={() => move(index, -1)} className="grid size-7 place-items-center rounded border border-[#dce3eb] disabled:opacity-30" aria-label="Naik"><ArrowUp className="size-3" /></button><button type="button" disabled={index === categories.length - 1} onClick={() => move(index, 1)} className="grid size-7 place-items-center rounded border border-[#dce3eb] disabled:opacity-30" aria-label="Turun"><ArrowDown className="size-3" /></button></div></td>
                <td className="px-3"><button type="button" onClick={() => void remove(index)} disabled={busy} className="inline-flex h-7 items-center gap-1 rounded border border-rose-200 bg-rose-50 px-2 text-[7px] font-bold text-rose-600"><Trash2 className="size-3" />Hapus</button></td>
              </tr>
            ))}
            {!categories.length && <tr><td colSpan={7} className="p-6 text-center text-[9px] text-[#718198]">Belum ada kategori.</td></tr>}
          </tbody>
        </table>
      </div>
      <p className="border-t border-[#edf0f4] bg-[#fafbfd] px-4 py-3 text-[8px] leading-4 text-[#65758a]">Staff, Admin, dan Super Admin dapat mengatur tampilan kategori. Kategori yang masih dipakai produk tidak dapat dihapus agar produk tidak kehilangan klasifikasi.</p>
    </section>
  );
}
