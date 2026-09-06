import { z } from "zod";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import {
  applyPaymentStatus,
  createOrderIdentity,
  fulfillAutomaticOrder,
  getOrderById,
  insertPendingOrder,
  normalizeCustomerInputs,
  markPaymentCreationFailed,
  recordOrderEvent,
  resolvePurchasableItem,
} from "@/lib/server/orders";
import { quotePromotion } from "@/lib/server/promotions";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";
import { hasAvailableVoucherStock } from "@/lib/server/vouchers";
import { spendWallet } from "@/lib/server/wallet";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

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
  buyerPhone: z.string().trim().regex(/^\+?[0-9]{8,16}$/),
  customerNotes: z.string().trim().max(500).optional(),
  voucherCode: z.string().trim().max(40).optional(),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "wallet-checkout", 12, 600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak percobaan checkout. Coba lagi beberapa menit." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  let referenceId: string | null = null;
  try {
    const input = schema.parse(await request.json());
    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
    if (!item) return Response.json({ error: "Produk atau nominal tidak tersedia." }, { status: 404 });
    if (item.providerCode === "voucher-stock" && item.providerSku && !await hasAvailableVoucherStock(item.providerSku)) return Response.json({ error: "Stok kode untuk paket ini sedang habis." }, { status: 409 });
    const membership = await getMemberTierProfile(customer.id);
    const promotion = await quotePromotion(
      item.productSlug,
      item.packageSku,
      item.price,
      input.voucherCode,
      { tier: membership.tier, discountPercent: membership.setting.discountPercent },
    );
    const customerData = normalizeCustomerInputs(item, input.customerInputs, input.destination, input.server || null);
    const identity = createOrderIdentity();
    referenceId = identity.referenceId;
    await insertPendingOrder({ ...identity, item, destination: customerData.destination, server: customerData.server, nickname: input.nickname || null, buyerName: input.buyerName, buyerEmail: input.buyerEmail, buyerPhone: input.buyerPhone, customerNotes: input.customerNotes || null, customerInputs: customerData.values, paymentMethod: "wallet", paymentChannel: "lfamilia-balance", customerId: customer.id, promotion });
    const balanceAfter = await spendWallet({ customerId: customer.id, orderId: identity.id, amount: promotion.finalPrice, description: `${item.productName} • ${item.packageLabel}` });
    const order = await getOrderById(identity.id);
    if (!order) throw new Error("Pesanan tidak ditemukan setelah dibuat.");
    const firstPaid = await applyPaymentStatus(order, "paid");
    await recordOrderEvent({ orderId: identity.id, source: "wallet", eventId: `wallet-${identity.id}`, status: "paid", payload: { amount: promotion.finalPrice, balanceAfter } });
    if (firstPaid && item.fulfillmentType === "automatic") {
      await fulfillAutomaticOrder(identity.id, getPublicBaseUrl());
      await notifyOrderFulfillmentSuccessById(identity.id).catch((error) =>
        console.error("Notifikasi pesanan selesai saldo gagal:", error),
      );
    }
    return Response.json({ orderId: identity.id, referenceId: identity.referenceId, paymentNo: null, paymentName: "Saldo LFAMILIA", paymentUrl: null, fee: 0, total: promotion.finalPrice, expiredAt: null, paymentStatus: "paid", balanceAfter, fulfillmentType: item.fulfillmentType, providerCode: item.providerCode, basePrice: promotion.basePrice, sellingPrice: promotion.sellingPrice, discountAmount: promotion.discountAmount, voucherCode: promotion.voucherCode, flashSaleId: promotion.flashSaleId, memberTier: promotion.memberTier, memberDiscountPercent: promotion.memberDiscountPercent, discountSource: promotion.discountSource }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message || "Data checkout tidak valid." : error instanceof Error ? error.message : "Pembayaran saldo gagal.";
    if (referenceId) await markPaymentCreationFailed(referenceId, message).catch(() => undefined);
    return Response.json({ error: message }, { status: error instanceof z.ZodError ? 400 : 400 });
  }
}
