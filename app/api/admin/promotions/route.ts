import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { deletePromotion, listDiscountVouchers, listFlashSales, saveDiscountVoucher, saveFlashSale } from "@/lib/server/promotions";

const base = z.object({ id: z.number().int().positive().nullable().optional(), isActive: z.boolean(), startsAt: z.string().datetime(), endsAt: z.string().datetime() });
const voucherSchema = base.extend({
  kind: z.literal("voucher"), code: z.string().trim().regex(/^[A-Za-z0-9_-]+$/).max(40), name: z.string().trim().min(2).max(100),
  description: z.string().trim().max(300), discountType: z.enum(["fixed", "percentage"]), discountValue: z.number().int().positive(),
  minPurchase: z.number().int().min(0), maxDiscount: z.number().int().positive().nullable(), usageLimit: z.number().int().positive().nullable(),
}).superRefine((value, context) => {
  if (new Date(value.endsAt) <= new Date(value.startsAt)) context.addIssue({ code: z.ZodIssueCode.custom, path: ["endsAt"], message: "Waktu berakhir harus setelah waktu mulai." });
  if (value.discountType === "percentage" && value.discountValue > 100) context.addIssue({ code: z.ZodIssueCode.custom, path: ["discountValue"], message: "Persentase diskon maksimal 100%." });
});
const flashSchema = base.extend({
  kind: z.literal("flash"), productSlug: z.string().trim().min(2).max(80), packageSku: z.string().trim().min(2).max(100),
  salePrice: z.number().int().positive(), badge: z.string().trim().min(2).max(30), stockLimit: z.number().int().positive().nullable(),
}).refine((value) => new Date(value.endsAt) > new Date(value.startsAt), { path: ["endsAt"], message: "Waktu berakhir harus setelah waktu mulai." });

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  const [vouchers, flashSales] = await Promise.all([listDiscountVouchers(true), listFlashSales(true)]);
  return Response.json({ vouchers, flashSales, role: access.role });
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const body = await request.json();
    if (body?.kind === "voucher") {
      const input = voucherSchema.parse(body);
      const { kind: _kind, id, ...value } = input;
      void _kind;
      const savedId = await saveDiscountVoucher(value, id ?? undefined);
      return Response.json({ ok: true, id: savedId });
    }
    const input = flashSchema.parse(body);
    const { kind: _kind, id, ...value } = input;
    void _kind;
    const savedId = await saveFlashSale(value, id ?? undefined);
    return Response.json({ ok: true, id: savedId });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Promo gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  const url = new URL(request.url);
  const id = Number(url.searchParams.get("id"));
  const kind = url.searchParams.get("kind");
  if (!Number.isInteger(id) || id < 1 || (kind !== "voucher" && kind !== "flash")) return Response.json({ error: "Promo tidak valid." }, { status: 400 });
  await deletePromotion(kind, id);
  return Response.json({ ok: true });
}
