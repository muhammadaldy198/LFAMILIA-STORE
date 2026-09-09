import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { updateProductPackageProvider } from "@/lib/server/products";

const schema = z.object({
  packageId: z.number().int().positive(),
  providerCode: z.enum(["digiflazz", "voucher-stock"]).nullable(),
  providerSku: z.string().trim().max(100).nullable(),
  pricingMode: z.enum(["manual", "auto"]).default("auto"),
  marginType: z.enum(["fixed", "percent"]).default("fixed"),
  marginValue: z.number().int().min(0).max(1_000_000).default(0),
});

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;

  try {
    const input = schema.parse(await request.json());
    if (input.providerCode === "digiflazz" && !input.providerSku) {
      throw new Error("SKU DigiFlazz wajib diisi.");
    }
    if (
      input.providerCode === "voucher-stock" &&
      (!input.providerSku || !/^[a-z0-9][a-z0-9._:-]{1,99}$/.test(input.providerSku))
    ) {
      throw new Error("Kunci stok internal tidak valid.");
    }

    await updateProductPackageProvider(input);
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
