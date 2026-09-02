import { z } from "zod";
import { getCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import { resolvePurchasableItem } from "@/lib/server/orders";
import { quotePromotion } from "@/lib/server/promotions";

const schema = z.object({
  productSlug: z.string().trim().min(2).max(80),
  packageSku: z.string().trim().min(2).max(100),
  voucherCode: z.string().trim().max(40).optional(),
});

export async function POST(request: Request) {
  try {
    const input = schema.parse(await request.json());
    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
    if (!item) return Response.json({ error: "Produk atau nominal tidak tersedia." }, { status: 404 });
    const customer = await getCustomerSession(request);
    const membership = customer ? await getMemberTierProfile(customer.id) : null;
    return Response.json(await quotePromotion(
      item.productSlug,
      item.packageSku,
      item.price,
      input.voucherCode,
      membership ? { tier: membership.tier, discountPercent: membership.setting.discountPercent } : null,
    ));
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Harga promo gagal dihitung.";
    return Response.json({ error: message }, { status: 400 });
  }
}
