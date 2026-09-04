import { z } from "zod";
import { getCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import { quotePromotion } from "@/lib/server/promotions";
import {
  createOrderIdentity,
  insertPendingOrder,
  normalizeCustomerInputs,
  resolvePurchasableItem,
} from "@/lib/server/orders";
import { hasAvailableVoucherStock } from "@/lib/server/vouchers";
import { readWalletSettings } from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

const schema = z.object({
  productSlug: z.string().trim().min(2).max(80),
  packageSku: z.string().trim().min(2).max(100),
  destination: z.string().trim().max(150).optional().default(""),
  server: z.string().trim().max(40).optional(),
  customerInputs: z.array(z.object({
    id: z.string().trim().min(1).max(60),
    value: z.string().trim().max(300),
  })).max(12).default([]),
  nickname: z.string().trim().max(100).optional(),
  buyerName: z.string().trim().min(2).max(100),
  buyerEmail: z.string().trim().email().max(150),
  buyerPhone: z
    .string()
    .trim()
    .regex(/^\+?[0-9]{8,16}$/),
  customerNotes: z.string().trim().max(500).optional(),
  voucherCode: z.string().trim().max(40).optional(),
  paymentMethod: z.enum(["manual_qris", "manual_bank"]),
});

export async function POST(request: Request) {
  try {
    const input = schema.parse(await request.json());
    const settings = await readWalletSettings();
    if (input.paymentMethod === "manual_qris" && !settings.manualQrisEnabled)
      throw new Error("QRIS manual sedang tidak tersedia.");
    if (
      input.paymentMethod === "manual_bank" &&
      (!settings.isEnabled || !settings.accountNumber)
    )
      throw new Error("Transfer bank manual sedang tidak tersedia.");
    const item = await resolvePurchasableItem(
      input.productSlug,
      input.packageSku,
    );
    if (!item)
      return Response.json(
        { error: "Produk atau nominal tidak tersedia." },
        { status: 404 },
      );
    if (
      item.providerCode === "voucher-stock" &&
      item.providerSku &&
      !(await hasAvailableVoucherStock(item.providerSku))
    )
      return Response.json(
        { error: "Stok kode untuk paket ini sedang habis." },
        { status: 409 },
      );
    const customer = await getCustomerSession(request);
    const membership = customer ? await getMemberTierProfile(customer.id) : null;
    const promotion = await quotePromotion(
      item.productSlug,
      item.packageSku,
      item.price,
      input.voucherCode,
      membership
        ? { tier: membership.tier, discountPercent: membership.setting.discountPercent }
        : null,
    );
    const customerData = normalizeCustomerInputs(item, input.customerInputs, input.destination, input.server || null);
    const identity = createOrderIdentity();
    await insertPendingOrder({
      ...identity,
      item,
      destination: customerData.destination,
      server: customerData.server,
      nickname: input.nickname || null,
      buyerName: input.buyerName,
      buyerEmail: input.buyerEmail,
      buyerPhone: input.buyerPhone,
      customerNotes: input.customerNotes || null,
      customerInputs: customerData.values,
      paymentMethod: input.paymentMethod,
      paymentChannel: input.paymentMethod,
      customerId: customer?.id ?? null,
      promotion,
    });
    const isQris = input.paymentMethod === "manual_qris";
    return Response.json(
      {
        orderId: identity.id,
        referenceId: identity.referenceId,
        paymentMethod: input.paymentMethod,
        paymentNo: isQris ? "Scan QRIS sesuai total" : settings.accountNumber,
        paymentName: isQris
          ? settings.manualQrisName
          : `${settings.methodName} • ${settings.accountName}`,
        paymentUrl: isQris ? settings.manualQrisImageUrl || null : null,
        fee: 0,
        total: promotion.finalPrice,
        expiredAt: null,
        paymentStatus: "pending",
        fulfillmentType: item.fulfillmentType,
        providerCode: item.providerCode,
        basePrice: promotion.basePrice,
        sellingPrice: promotion.sellingPrice,
        discountAmount: promotion.discountAmount,
        voucherCode: promotion.voucherCode,
        flashSaleId: promotion.flashSaleId,
        memberTier: promotion.memberTier,
        memberDiscountPercent: promotion.memberDiscountPercent,
        discountSource: promotion.discountSource,
      },
      { status: 201 },
    );
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message || "Data checkout tidak valid."
        : error instanceof Error
          ? error.message
          : "Pembayaran manual gagal dibuat.";
    return Response.json({ error: message }, { status: 400 });
  }
}
