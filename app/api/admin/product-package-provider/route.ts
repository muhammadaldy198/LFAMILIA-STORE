import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";
import { updateProductPackageProvider } from "@/lib/server/products";

const schema = z.object({
  packageId: z.number().int().positive(),
  providerCode: z.enum(["digiflazz", "voucher-stock"]).nullable(),
  providerSku: z.string().trim().max(100).nullable(),
  pricingMode: z.enum(["manual", "auto"]).default("auto"),
  marginType: z.enum(["fixed", "percent"]).default("fixed"),
  marginValue: z.number().int().min(0).max(1_000_000).default(0),
  providerMaxPrice: z.number().int().min(1).max(100_000_000).nullable().optional(),
});

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;

  try {
    const input = schema.parse(await request.json());
    if (input.providerCode === "digiflazz") {
      if (!input.providerSku) throw new Error("SKU DigiFlazz wajib diisi.");
      // Product management owns the SKU mapping only. Max Price, margin and
      // selling price are controlled exclusively from the Digiflazz workspace.
      const result = await getD1().prepare(`
        UPDATE product_packages
           SET provider_code = 'digiflazz', provider_sku = ?, pricing_mode = 'auto'
         WHERE id = ?
      `).bind(input.providerSku, input.packageId).run();
      if (!result.meta.changes) throw new Error("Nominal tidak ditemukan.");
      return Response.json({ ok: true });
    }

    if (
      input.providerCode === "voucher-stock" &&
      (!input.providerSku || !/^[a-z0-9][a-z0-9._:-]{1,99}$/.test(input.providerSku))
    ) {
      throw new Error("Kunci stok internal tidak valid.");
    }

    await updateProductPackageProvider({ ...input, providerMaxPrice: null });
    return Response.json({ ok: true });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message
        : error instanceof Error
          ? error.message
          : "Provider/SKU nominal gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
