"use client";

import { useCallback, useEffect, useState, type ReactNode } from "react";
import {
  CalendarClock,
  Edit3,
  LoaderCircle,
  Plus,
  Save,
  Sparkles,
  TicketPercent,
  Trash2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { formatRupiah } from "@/lib/store-data";
import type { DiscountVoucher, FlashSale } from "@/lib/server/promotions";

type VoucherDraft = Omit<DiscountVoucher, "usedCount" | "startsAt" | "endsAt"> & {
  startsAt: string;
  endsAt: string;
};

type FlashDraft = {
  id: number;
  productSlug: string;
  packageSku: string;
  salePrice: number;
  badge: string;
  startsAt: string;
  endsAt: string;
  stockLimit: number | null;
  isActive: boolean;
};

type CatalogProduct = {
  slug: string;
  name: string;
  isActive: boolean;
  packages: Array<{
    id: string;
    label: string;
    price: number;
    isActive: boolean;
  }>;
};

type PromotionPayload = {
  vouchers?: DiscountVoucher[];
  flashSales?: FlashSale[];
  error?: string;
};

type ProductsPayload = {
  products?: CatalogProduct[];
  error?: string;
};

function initialDates() {
  const start = new Date();
  const end = new Date(start.getTime() + 7 * 86_400_000);
  return { startsAt: localDate(start), endsAt: localDate(end) };
}

function emptyVoucher(): VoucherDraft {
  return {
    id: 0,
    code: "",
    name: "",
    description: "",
    discountType: "fixed",
    discountValue: 10_000,
    minPurchase: 50_000,
    maxDiscount: null,
    usageLimit: null,
    isActive: true,
    ...initialDates(),
  };
}

function emptyFlash(): FlashDraft {
  return {
    id: 0,
    productSlug: "",
    packageSku: "",
    salePrice: 0,
    badge: "Promo",
    stockLimit: null,
    isActive: true,
    ...initialDates(),
  };
}

export function AdminPromotionManager({ role }: { role: "owner" | "staff" }) {
  const [section, setSection] = useState<"voucher" | "flash">("voucher");
  const [vouchers, setVouchers] = useState<DiscountVoucher[]>([]);
  const [flashSales, setFlashSales] = useState<FlashSale[]>([]);
  const [products, setProducts] = useState<CatalogProduct[]>([]);
  const [voucher, setVoucher] = useState<VoucherDraft>(emptyVoucher());
  const [flash, setFlash] = useState<FlashDraft>(emptyFlash());
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<"voucher" | "flash" | null>(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [referenceTime, setReferenceTime] = useState(0);

  const selectedProduct = products.find((item) => item.slug === flash.productSlug);
  const selectablePackages = (selectedProduct?.packages ?? []).filter((item) => item.isActive);
  const selectedPackage = selectablePackages.find((item) => item.id === flash.packageSku);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [promotionResponse, productResponse] = await Promise.all([
        fetch("/api/panel/promotions", { cache: "no-store" }),
        fetch("/api/panel/products", { cache: "no-store" }),
      ]);
      const promotionData = await promotionResponse.json().catch(() => ({})) as PromotionPayload;
      const productData = await productResponse.json().catch(() => ({})) as ProductsPayload;
      if (!promotionResponse.ok) throw new Error(promotionData.error || "Promo gagal dimuat.");
      if (!productResponse.ok) throw new Error(productData.error || "Katalog produk gagal dimuat.");
      setVouchers(promotionData.vouchers ?? []);
      setFlashSales(promotionData.flashSales ?? []);
      setProducts((productData.products ?? []).filter((item) => item.isActive));
      setReferenceTime(Date.now());
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Promo gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  function selectFlashProduct(productSlug: string) {
    const product = products.find((item) => item.slug === productSlug);
    const packageItem = product?.packages.find((item) => item.isActive);
    setFlash((current) => ({
      ...current,
      productSlug,
      packageSku: packageItem?.id ?? "",
      salePrice: packageItem ? Math.max(1, packageItem.price - 1) : 0,
    }));
  }

  async function saveVoucher() {
    setSaving("voucher");
    setError("");
    setMessage("");
    try {
      const payload = {
        ...voucher,
        id: voucher.id || undefined,
        kind: "voucher",
        startsAt: new Date(voucher.startsAt).toISOString(),
        endsAt: new Date(voucher.endsAt).toISOString(),
      };
      const response = await fetch("/api/panel/promotions", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || "Voucher gagal disimpan.");
      setVoucher(emptyVoucher());
      setMessage("Voucher diskon berhasil disimpan.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Voucher gagal disimpan.");
    } finally {
      setSaving(null);
    }
  }

  async function saveFlash() {
    setSaving("flash");
    setError("");
    setMessage("");
    try {
      const payload = {
        ...flash,
        id: flash.id || undefined,
        kind: "flash",
        startsAt: new Date(flash.startsAt).toISOString(),
        endsAt: new Date(flash.endsAt).toISOString(),
      };
      const response = await fetch("/api/panel/promotions", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || "Promo harga gagal disimpan.");
      setFlash(emptyFlash());
      setMessage("Promo harga terjadwal berhasil disimpan.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Promo harga gagal disimpan.");
    } finally {
      setSaving(null);
    }
  }

  async function remove(kind: "voucher" | "flash", id: number) {
    const label = kind === "voucher" ? "voucher" : "promo harga";
    if (!window.confirm("Hapus " + label + " ini?")) return;
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/panel/promotions?kind=" + kind + "&id=" + id, {
        method: "DELETE",
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || "Promo gagal dihapus.");
      setMessage((kind === "voucher" ? "Voucher" : "Promo harga") + " berhasil dihapus.");
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Promo gagal dihapus.");
    }
  }

  function editVoucher(item: DiscountVoucher) {
    setSection("voucher");
    setVoucher({
      ...item,
      startsAt: localDate(new Date(item.startsAt)),
      endsAt: localDate(new Date(item.endsAt)),
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function editFlash(item: FlashSale) {
    setSection("flash");
    setFlash({
      id: item.id,
      productSlug: item.productSlug,
      packageSku: item.packageSku,
      salePrice: item.salePrice,
      badge: item.badge,
      startsAt: localDate(new Date(item.startsAt)),
      endsAt: localDate(new Date(item.endsAt)),
      stockLimit: item.stockLimit,
      isActive: item.isActive,
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  if (loading) {
    return (
      <div className="flex min-h-56 items-center justify-center text-xs text-white/35">
        <LoaderCircle className="mr-2 size-4 animate-spin" />
        Memuat promo…
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-1 rounded-md border border-white/[0.08] bg-white/[0.02] p-1">
        <SectionButton active={section === "voucher"} onClick={() => setSection("voucher")} icon={<TicketPercent className="size-3.5" />}>
          Voucher diskon
        </SectionButton>
        <SectionButton active={section === "flash"} onClick={() => setSection("flash")} icon={<Sparkles className="size-3.5" />}>
          Promo harga
        </SectionButton>
      </div>

      {message && <div className="rounded-md border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] px-3 py-2 text-[10px] text-[#d8ff8d]">{message}</div>}
      {error && <div className="rounded-md border border-red-400/20 bg-red-400/[0.06] px-3 py-2 text-[10px] text-red-200">{error}</div>}

      {section === "voucher" ? (
        <>
          {role === "owner" && (
            <section className="rounded-lg border border-white/[0.08] bg-white/[0.015] p-4">
              <div className="flex items-start gap-2">
                <span className="grid size-7 place-items-center rounded-md bg-[#b9ff35]/10 text-[#d8ff8d]">
                  <TicketPercent className="size-3.5" />
                </span>
                <div>
                  <h3 className="text-xs font-bold">{voucher.id ? "Ubah voucher diskon" : "Buat voucher diskon"}</h3>
                  <p className="mt-0.5 text-[10px] text-white/35">Kode diskon dapat dibatasi waktu, kuota, minimum transaksi, dan nilai maksimum.</p>
                </div>
              </div>

              <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <Field label="Kode voucher">
                  <Input value={voucher.code} onChange={(event) => setVoucher({ ...voucher, code: event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, "") })} className="admin-input font-mono" placeholder="HEMAT10" />
                </Field>
                <Field label="Nama promo">
                  <Input value={voucher.name} onChange={(event) => setVoucher({ ...voucher, name: event.target.value })} className="admin-input" placeholder="Promo pelanggan baru" />
                </Field>
                <Field label="Jenis diskon">
                  <select value={voucher.discountType} onChange={(event) => setVoucher({ ...voucher, discountType: event.target.value as "fixed" | "percentage" })} className="admin-select">
                    <option value="fixed">Potongan rupiah</option>
                    <option value="percentage">Persentase</option>
                  </select>
                </Field>
                <Field label={voucher.discountType === "fixed" ? "Nilai potongan" : "Persentase diskon"}>
                  <Input type="number" min={1} value={voucher.discountValue} onChange={(event) => setVoucher({ ...voucher, discountValue: Number(event.target.value) })} className="admin-input" />
                </Field>
                <Field label="Minimum transaksi">
                  <Input type="number" min={0} value={voucher.minPurchase} onChange={(event) => setVoucher({ ...voucher, minPurchase: Number(event.target.value) })} className="admin-input" />
                </Field>
                <Field label="Maksimum potongan">
                  <Input type="number" min={1} value={voucher.maxDiscount ?? ""} onChange={(event) => setVoucher({ ...voucher, maxDiscount: event.target.value ? Number(event.target.value) : null })} className="admin-input" placeholder="Tanpa batas" />
                </Field>
                <Field label="Kuota penggunaan">
                  <Input type="number" min={1} value={voucher.usageLimit ?? ""} onChange={(event) => setVoucher({ ...voucher, usageLimit: event.target.value ? Number(event.target.value) : null })} className="admin-input" placeholder="Tanpa batas" />
                </Field>
                <SwitchField label="Voucher aktif" checked={voucher.isActive} onCheckedChange={(isActive) => setVoucher({ ...voucher, isActive })} />
                <Field label="Mulai">
                  <Input type="datetime-local" value={voucher.startsAt} onChange={(event) => setVoucher({ ...voucher, startsAt: event.target.value })} className="admin-input" />
                </Field>
                <Field label="Berakhir">
                  <Input type="datetime-local" value={voucher.endsAt} onChange={(event) => setVoucher({ ...voucher, endsAt: event.target.value })} className="admin-input" />
                </Field>
                <Field label="Deskripsi" wide>
                  <Textarea value={voucher.description} onChange={(event) => setVoucher({ ...voucher, description: event.target.value })} className="min-h-20 rounded-md border-white/10 bg-white/[0.025] text-xs text-white" placeholder="Keterangan singkat untuk admin atau pelanggan." />
                </Field>
              </div>

              <FormActions
                editing={voucher.id > 0}
                onCancel={() => setVoucher(emptyVoucher())}
                onSave={() => void saveVoucher()}
                saving={saving === "voucher"}
                label="Simpan voucher"
              />
            </section>
          )}

          <PromotionList
            title="Daftar voucher diskon"
            subtitle="Riwayat kode promo yang tersedia di checkout."
            empty="Belum ada voucher diskon."
          >
            {vouchers.map((item) => (
              <div key={item.id} className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <strong className="font-mono text-xs text-[#d8ff8d]">{item.code}</strong>
                    <StatePill active={item.isActive} startsAt={item.startsAt} endsAt={item.endsAt} now={referenceTime} />
                  </div>
                  <p className="mt-1 text-[10px] text-white/70">{item.name}</p>
                  <p className="mt-0.5 text-[9px] text-white/32">
                    {voucherValue(item)} · terpakai {formatNumber(item.usedCount)}{item.usageLimit ? "/" + formatNumber(item.usageLimit) : ""} · sampai {formatDate(item.endsAt)}
                  </p>
                </div>
                {role === "owner" && (
                  <div className="flex shrink-0 gap-1">
                    <Button type="button" size="icon-sm" variant="ghost" onClick={() => editVoucher(item)} className="text-white/55 hover:bg-white/[0.08] hover:text-white" aria-label={"Ubah voucher " + item.code}>
                      <Edit3 className="size-3.5" />
                    </Button>
                    <Button type="button" size="icon-sm" variant="ghost" onClick={() => void remove("voucher", item.id)} className="text-red-300 hover:bg-red-300/10 hover:text-red-200" aria-label={"Hapus voucher " + item.code}>
                      <Trash2 className="size-3.5" />
                    </Button>
                  </div>
                )}
              </div>
            ))}
          </PromotionList>
        </>
      ) : (
        <>
          {role === "owner" && (
            <section className="rounded-lg border border-white/[0.08] bg-white/[0.015] p-4">
              <div className="flex items-start gap-2">
                <span className="grid size-7 place-items-center rounded-md bg-[#b9ff35]/10 text-[#d8ff8d]">
                  <CalendarClock className="size-3.5" />
                </span>
                <div>
                  <h3 className="text-xs font-bold">{flash.id ? "Ubah promo harga" : "Buat promo harga terjadwal"}</h3>
                  <p className="mt-0.5 text-[10px] text-white/35">Fitur dari pola Offers BagusPay: satu nominal diberi harga promo dalam waktu dan stok yang dapat dibatasi.</p>
                </div>
              </div>

              <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <Field label="Produk">
                  <select value={flash.productSlug} onChange={(event) => selectFlashProduct(event.target.value)} className="admin-select">
                    <option value="">Pilih produk</option>
                    {products.map((item) => <option key={item.slug} value={item.slug}>{item.name}</option>)}
                  </select>
                </Field>
                <Field label="Nominal">
                  <select value={flash.packageSku} onChange={(event) => {
                    const packageItem = selectablePackages.find((item) => item.id === event.target.value);
                    setFlash((current) => ({ ...current, packageSku: event.target.value, salePrice: packageItem ? Math.max(1, packageItem.price - 1) : current.salePrice }));
                  }} disabled={!selectedProduct} className="admin-select disabled:cursor-not-allowed disabled:opacity-45">
                    <option value="">Pilih nominal</option>
                    {selectablePackages.map((item) => <option key={item.id} value={item.id}>{item.label} · {formatRupiah(item.price)}</option>)}
                  </select>
                </Field>
                <Field label="Harga normal">
                  <div className="admin-readonly">{selectedPackage ? formatRupiah(selectedPackage.price) : "Pilih nominal terlebih dahulu"}</div>
                </Field>
                <Field label="Harga promo">
                  <Input type="number" min={1} max={selectedPackage ? Math.max(1, selectedPackage.price - 1) : undefined} value={flash.salePrice || ""} onChange={(event) => setFlash({ ...flash, salePrice: Number(event.target.value) })} className="admin-input" placeholder="Harus lebih rendah dari harga normal" />
                </Field>
                <Field label="Label promo">
                  <Input value={flash.badge} onChange={(event) => setFlash({ ...flash, badge: event.target.value.slice(0, 30) })} className="admin-input" placeholder="Promo" />
                </Field>
                <Field label="Batas stok promo">
                  <Input type="number" min={1} value={flash.stockLimit ?? ""} onChange={(event) => setFlash({ ...flash, stockLimit: event.target.value ? Number(event.target.value) : null })} className="admin-input" placeholder="Tanpa batas" />
                </Field>
                <Field label="Mulai">
                  <Input type="datetime-local" value={flash.startsAt} onChange={(event) => setFlash({ ...flash, startsAt: event.target.value })} className="admin-input" />
                </Field>
                <Field label="Berakhir">
                  <Input type="datetime-local" value={flash.endsAt} onChange={(event) => setFlash({ ...flash, endsAt: event.target.value })} className="admin-input" />
                </Field>
                <SwitchField label="Promo aktif" checked={flash.isActive} onCheckedChange={(isActive) => setFlash({ ...flash, isActive })} />
              </div>

              {selectedPackage && flash.salePrice > 0 && flash.salePrice < selectedPackage.price && (
                <div className="mt-3 rounded-md border border-[#b9ff35]/15 bg-[#b9ff35]/[0.04] px-3 py-2 text-[10px] text-[#d8ff8d]">
                  Harga turun {formatRupiah(selectedPackage.price - flash.salePrice)} dari harga normal.
                </div>
              )}

              <FormActions
                editing={flash.id > 0}
                onCancel={() => setFlash(emptyFlash())}
                onSave={() => void saveFlash()}
                saving={saving === "flash"}
                label="Simpan promo harga"
              />
            </section>
          )}

          <PromotionList
            title="Daftar promo harga"
            subtitle="Promo per nominal yang bisa dijadwalkan tanpa mengubah harga normal."
            empty="Belum ada promo harga terjadwal."
          >
            {flashSales.map((item) => (
              <div key={item.id} className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <strong className="truncate text-xs text-white">{item.productName}</strong>
                    <StatePill active={item.isActive} startsAt={item.startsAt} endsAt={item.endsAt} now={referenceTime} />
                  </div>
                  <p className="mt-1 truncate text-[10px] text-white/60">{item.packageLabel} · <span className="font-bold text-[#d8ff8d]">{formatRupiah(item.salePrice)}</span> <span className="text-white/30">dari {formatRupiah(item.basePrice)}</span></p>
                  <p className="mt-0.5 text-[9px] text-white/32">
                    {item.badge} · terjual {formatNumber(item.soldCount)}{item.stockLimit ? "/" + formatNumber(item.stockLimit) : ""} · {formatDate(item.startsAt)} — {formatDate(item.endsAt)}
                  </p>
                </div>
                {role === "owner" && (
                  <div className="flex shrink-0 gap-1">
                    <Button type="button" size="icon-sm" variant="ghost" onClick={() => editFlash(item)} className="text-white/55 hover:bg-white/[0.08] hover:text-white" aria-label={"Ubah promo " + item.productName}>
                      <Edit3 className="size-3.5" />
                    </Button>
                    <Button type="button" size="icon-sm" variant="ghost" onClick={() => void remove("flash", item.id)} className="text-red-300 hover:bg-red-300/10 hover:text-red-200" aria-label={"Hapus promo " + item.productName}>
                      <Trash2 className="size-3.5" />
                    </Button>
                  </div>
                )}
              </div>
            ))}
          </PromotionList>
        </>
      )}
    </div>
  );
}

function SectionButton({
  active,
  onClick,
  icon,
  children,
}: {
  active: boolean;
  onClick: () => void;
  icon: ReactNode;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        "inline-flex h-7 items-center gap-1.5 rounded px-2 text-[10px] font-bold transition " +
        (active ? "bg-[#b9ff35] text-[#091006]" : "text-white/45 hover:bg-white/[0.07] hover:text-white")
      }
    >
      {icon}
      {children}
    </button>
  );
}

function PromotionList({
  title,
  subtitle,
  empty,
  children,
}: {
  title: string;
  subtitle: string;
  empty: string;
  children: ReactNode;
}) {
  const count = Array.isArray(children) ? children.length : 0;
  return (
    <section className="overflow-hidden rounded-lg border border-white/[0.08] bg-white/[0.012]">
      <div className="flex items-start justify-between gap-3 border-b border-white/[0.08] px-4 py-3">
        <div>
          <h3 className="text-xs font-bold">{title}</h3>
          <p className="mt-0.5 text-[10px] text-white/35">{subtitle}</p>
        </div>
        <span className="rounded-md bg-white/[0.05] px-2 py-1 text-[9px] text-white/42">{count}</span>
      </div>
      <div className="divide-y divide-white/[0.08]">
        {count ? children : <div className="p-6 text-center"><Plus className="mx-auto mb-2 size-4 text-white/25" /><p className="text-[10px] text-white/30">{empty}</p></div>}
      </div>
    </section>
  );
}

function FormActions({
  editing,
  onCancel,
  onSave,
  saving,
  label,
}: {
  editing: boolean;
  onCancel: () => void;
  onSave: () => void;
  saving: boolean;
  label: string;
}) {
  return (
    <div className="mt-4 flex justify-end gap-2 border-t border-white/[0.07] pt-3">
      {editing && <Button type="button" variant="outline" onClick={onCancel} className="border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white">Batal</Button>}
      <Button type="button" onClick={onSave} disabled={saving} className="bg-[#b9ff35] font-black text-[#091006] hover:bg-[#c9ff70]">
        {saving ? <LoaderCircle className="size-3.5 animate-spin" /> : <Save className="size-3.5" />}
        {label}
      </Button>
    </div>
  );
}

function Field({
  label,
  wide = false,
  children,
}: {
  label: string;
  wide?: boolean;
  children: ReactNode;
}) {
  return (
    <label className={wide ? "sm:col-span-2" : ""}>
      <span className="field-label">{label}</span>
      {children}
    </label>
  );
}

function SwitchField({
  label,
  checked,
  onCheckedChange,
}: {
  label: string;
  checked: boolean;
  onCheckedChange: (value: boolean) => void;
}) {
  return (
    <label className="flex h-8 items-center justify-between rounded-md border border-white/[0.1] bg-white/[0.025] px-3 text-[10px] text-white/62">
      <span>{label}</span>
      <Switch checked={checked} onCheckedChange={onCheckedChange} />
    </label>
  );
}

function StatePill({
  active,
  startsAt,
  endsAt,
  now,
}: {
  active: boolean;
  startsAt: string;
  endsAt: string;
  now: number;
}) {
  const starts = new Date(startsAt).getTime();
  const ends = new Date(endsAt).getTime();
  const state = !active ? "Nonaktif" : now === 0 ? "Aktif" : now < starts ? "Akan datang" : now > ends ? "Berakhir" : "Berjalan";
  const tone = state === "Berjalan"
    ? "bg-emerald-300/10 text-emerald-200"
    : state === "Akan datang"
      ? "bg-sky-300/10 text-sky-200"
      : "bg-white/[0.06] text-white/45";
  return <span className={"rounded-md px-1.5 py-0.5 text-[8px] font-bold " + tone}>{state}</span>;
}

function voucherValue(item: DiscountVoucher) {
  return item.discountType === "percentage" ? item.discountValue + "%" : formatRupiah(item.discountValue);
}

function formatNumber(value: number) {
  return new Intl.NumberFormat("id-ID").format(Number(value || 0));
}

function formatDate(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}

function localDate(date: Date) {
  if (Number.isNaN(date.getTime())) return "";
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}
