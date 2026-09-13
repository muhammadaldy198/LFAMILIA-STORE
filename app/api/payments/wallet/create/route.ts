import { z } from "zod";
import { isAutomaticPackageAvailable } from "@/lib/server/availability";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import {
  NicknameServiceError,
  NicknameValidationError,
  verifyNicknameForCheckout,
} from "@/lib/server/nickname-check";
import {
  CheckoutValidationError,
  createOrderIdentity,
  fulfillAutomaticOrder,
  getOrderById,
  getWalletOrderByCheckoutKey,
  insertPendingOrder,
  normalizeCustomerInputs,
  markPaymentCreationFailed,
  resolvePurchasableItem,
} from "@/lib/server/orders";
import { PromotionQuoteError, quotePromotion } from "@/lib/server/promotions";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";
import { getWalletOrderBalance, settleWalletOrder, WalletSettlementError } from "@/lib/server/wallet";
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
  buyerName: z.string().trim().min(2).max(100),
  buyerEmail: z.string().trim().email().max(150),
  buyerPhone: z.string().trim().regex(/^\+?[0-9]{8,16}$/),
  customerNotes: z.string().trim().max(500).optional(),
  voucherCode: z.string().trim().max(40).optional(),
  idempotencyKey: z.string().uuid(),
});

function walletSuccessResponse(order: Awaited<ReturnType<typeof getOrderById>>, balanceAfter: number) {
  if (!order) throw new Error("Pesanan wallet tidak ditemukan.");
  return {
    orderId: order.id,
    referenceId: order.reference_id,
    paymentNo: null,
    paymentName: "Saldo LFAMILIA",
    paymentUrl: null,
    fee: 0,
    total: order.total,
    expiredAt: null,
    paymentStatus: "paid" as const,
    balanceAfter,
    fulfillmentType: order.fulfillment_type,
    basePrice: order.base_subtotal,
    sellingPrice: order.subtotal,
    discountAmount: order.discount_amount,
    voucherCode: order.voucher_code,
    flashSaleId: order.flash_sale_id,
  };
}

async function existingWalletResponse(customerId: string, checkoutKey: string) {
  const existing = await getWalletOrderByCheckoutKey(customerId, checkoutKey);
  if (!existing) return null;
  if (existing.payment_status === "pending") {
    try {
      await settleWalletOrder({
        customerId,
        orderId: existing.id,
        amount: existing.total,
        description: `${existing.product_name} • ${existing.package_label}`,
        fulfillmentType: existing.fulfillment_type,
        voucherCode: existing.voucher_code,
        flashSaleId: existing.flash_sale_id,
      });
    } catch (error) {
      if (error instanceof WalletSettlementError) {
        await markPaymentCreationFailed(existing.reference_id, error.message).catch(() => undefined);
      }
      throw error;
    }
    const settled = await getOrderById(existing.id);
    if (!settled) throw new Error("Pesanan wallet tidak ditemukan setelah dipulihkan.");
    if (settled.fulfillment_type === "automatic") {
      await fulfillAutomaticOrder(settled.id, getPublicBaseUrl()).catch((error) =>
        console.error("Pemulihan fulfillment wallet gagal:", error),
      );
      await notifyOrderFulfillmentSuccessById(settled.id).catch((error) =>
        console.error("Notifikasi pemulihan wallet gagal:", error),
      );
    }
    const balanceAfter = await getWalletOrderBalance(settled.id);
    if (balanceAfter === null) throw new Error("Debit wallet tidak ditemukan setelah pemulihan.");
    return Response.json(walletSuccessResponse(settled, balanceAfter));
  }
  if (existing.payment_status === "paid") {
    if (existing.fulfillment_type === "automatic") {
      await fulfillAutomaticOrder(existing.id, getPublicBaseUrl()).catch((error) =>
        console.error("Pemulihan fulfillment wallet gagal:", error),
      );
      await notifyOrderFulfillmentSuccessById(existing.id).catch((error) =>
        console.error("Notifikasi pemulihan wallet gagal:", error),
      );
    }
    const balanceAfter = await getWalletOrderBalance(existing.id);
    if (balanceAfter === null) {
      return Response.json({ error: "Pembayaran wallet perlu diperiksa admin." }, { status: 409 });
    }
    return Response.json(walletSuccessResponse(existing, balanceAfter));
  }
  return Response.json({ error: "Percobaan sebelumnya gagal. Silakan buat pesanan kembali." }, { status: 409 });
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "wallet-checkout", 12, 600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak percobaan checkout. Coba lagi beberapa menit.", retryable: true }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  let referenceId: string | null = null;
  let checkoutKey: string | null = null;
  try {
    const input = schema.parse(await request.json());
    checkoutKey = input.idempotencyKey;
    const priorResponse = await existingWalletResponse(customer.id, input.idempotencyKey);
    if (priorResponse) return priorResponse;
    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
    if (!item) return Response.json({ error: "Produk atau nominal tidak tersedia." }, { status: 404 });
    if (
      item.fulfillmentType === "automatic" &&
      !(await isAutomaticPackageAvailable({
        packageId: item.packageId,
        providerCode: item.providerCode,
        providerSku: item.providerSku,
      }))
    ) {
      return Response.json({ error: "Nominal otomatis sedang tidak tersedia." }, { status: 409 });
    }
    const membership = await getMemberTierProfile(customer.id);
    const promotion = await quotePromotion(
      item.productSlug,
      item.packageSku,
      item.price,
      input.voucherCode,
      { tier: membership.tier, discountPercent: membership.setting.discountPercent },
    );
    const customerData = normalizeCustomerInputs(item, input.customerInputs, input.destination, input.server || null);
    const verifiedAccount = await verifyNicknameForCheckout({
      productSlug: item.productSlug,
      userId: customerData.destination,
      server: customerData.server,
    });
    const identity = createOrderIdentity();
    referenceId = identity.referenceId;
    await insertPendingOrder({ ...identity, item, destination: customerData.destination, server: customerData.server, nickname: verifiedAccount.nickname, buyerName: input.buyerName, buyerEmail: input.buyerEmail, buyerPhone: input.buyerPhone, customerNotes: input.customerNotes || null, customerInputs: customerData.values, paymentMethod: "wallet", paymentChannel: "lfamilia-balance", customerId: customer.id, walletCheckoutKey: input.idempotencyKey, promotion });
    const balanceAfter = await settleWalletOrder({ customerId: customer.id, orderId: identity.id, amount: promotion.finalPrice, description: `${item.productName} • ${item.packageLabel}`, fulfillmentType: item.fulfillmentType, voucherCode: promotion.voucherCode, flashSaleId: promotion.flashSaleId });
    const order = await getOrderById(identity.id);
    if (!order) throw new Error("Pesanan tidak ditemukan setelah dibuat.");
    if (item.fulfillmentType === "automatic") {
      await fulfillAutomaticOrder(identity.id, getPublicBaseUrl()).catch((error) =>
        console.error("Pemenuhan otomatis setelah pembayaran saldo gagal:", error),
      );
      await notifyOrderFulfillmentSuccessById(identity.id).catch((error) =>
        console.error("Notifikasi pesanan selesai saldo gagal:", error),
      );
    }
    return Response.json(walletSuccessResponse(order, balanceAfter), { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message || "Data checkout tidak valid." : error instanceof Error ? error.message : "Pembayaran saldo gagal.";
    if (checkoutKey && error instanceof Error && /UNIQUE constraint failed.*wallet_checkout_key/i.test(error.message)) {
      const priorResponse = await existingWalletResponse(customer.id, checkoutKey);
      if (priorResponse) return priorResponse;
    }
    const clientInputRejected =
      error instanceof z.ZodError ||
      error instanceof CheckoutValidationError ||
      error instanceof NicknameValidationError ||
      error instanceof PromotionQuoteError;
    const rejected = clientInputRejected || error instanceof WalletSettlementError;
    if (referenceId && rejected) await markPaymentCreationFailed(referenceId, message).catch(() => undefined);
    if (!rejected) console.error("Checkout wallet belum dapat dipastikan:", error);
    return Response.json(
      { error: rejected || error instanceof NicknameServiceError ? message : "Pembayaran saldo belum dapat dipastikan. Coba lagi dengan data yang sama.", retryable: !rejected },
      { status: clientInputRejected ? 400 : error instanceof WalletSettlementError ? 409 : 503 },
    );
  }
}
