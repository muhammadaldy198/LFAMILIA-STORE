import { z } from "zod";
import { publicPaymentLabel } from "@/lib/public-payment";
import { getCustomerSession } from "@/lib/server/customer-auth";
import {
  createDokuDirectPayment,
  getDokuReadiness,
  isDokuChannelSupported,
} from "@/lib/server/doku";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import {
  NicknameServiceError,
  NicknameValidationError,
  verifyNicknameForCheckout,
} from "@/lib/server/nickname-check";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { quotePromotion } from "@/lib/server/promotions";
import {
  createOrderIdentity,
  getExternalOrderByCheckoutKey,
  insertPendingOrder,
  markPaymentCreationFailed,
  normalizeCustomerInputs,
  recordOrderEvent,
  resolvePurchasableItem,
  updateDokuPayment,
  type OrderRecord,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { hasAvailableVoucherStock } from "@/lib/server/vouchers";
import { readWalletSettings } from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

const routingSchema = z.object({
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
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
  voucherCode: z.string().trim().max(40).optional(),
  idempotencyKey: z.string().uuid(),
});

function publicInvoice(referenceId: string) {
  const clean = referenceId.trim().toUpperCase();
  if (!clean.includes("-")) return clean;
  const token = clean.split("-").at(-1) ?? clean.replace(/^LF/, "");
  return `LF${token}`;
}


function existingExternalResponse(order: OrderRecord) {
  if (["failed", "expired"].includes(order.payment_status)) {
    return Response.json(
      { error: "Percobaan pembayaran sebelumnya sudah gagal atau kedaluwarsa. Buat pembayaran baru.", retryable: false },
      { status: 409 },
    );
  }
  if (!order.doku_request_id) {
    return Response.json(
      { error: "Invoice sedang dibuat. Coba lagi dengan data yang sama.", retryable: true },
      { status: 409 },
    );
  }
  return Response.json({
    orderId: order.id,
    referenceId: order.reference_id,
    publicInvoice: publicInvoice(order.reference_id),
    fulfillmentType: order.fulfillment_type,
    basePrice: order.base_subtotal,
    sellingPrice: order.subtotal,
    discountAmount: order.discount_amount,
    voucherCode: order.voucher_code,
    flashSaleId: order.flash_sale_id,
    paymentMethod: order.payment_method,
    paymentNo: order.doku_payment_no,
    qrContent: order.doku_qr_content,
    paymentName: publicPaymentLabel(order.payment_method, order.payment_channel),
    paymentUrl: order.doku_payment_url,
    fee: order.admin_fee,
    total: order.total,
    expiredAt: order.doku_expired_at,
    paymentStatus: order.payment_status,
  });
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "automatic-checkout", 12, 600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak percobaan checkout. Coba lagi beberapa menit." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter) } },
    );
  }

  let referenceId: string | null = null;
  let checkoutKey: string | null = null;
  try {
    const input = routingSchema.parse(await request.json());
    checkoutKey = input.idempotencyKey;
    const priorOrder = await getExternalOrderByCheckoutKey(input.idempotencyKey);
    if (priorOrder) return existingExternalResponse(priorOrder);
    const paymentChannel =
      input.paymentMethod === "qris" && input.paymentChannel === "qris"
        ? "mpm"
        : input.paymentChannel;

    if (
      !isDokuChannelSupported(input.paymentMethod, paymentChannel) ||
      !(await isPaymentChannelAvailable(input.paymentMethod, paymentChannel))
    ) {
      return Response.json(
        { error: "Metode pembayaran belum didukung atau sedang dinonaktifkan." },
        { status: 400 },
      );
    }

    const settings = await readWalletSettings();
    const readiness = getDokuReadiness();
    if (!settings.dokuCheckoutEnabled || !readiness.ready) {
      return Response.json(
        {
          error: readiness.ready
            ? "Pembayaran otomatis sedang dinonaktifkan."
            : "Pembayaran otomatis belum siap.",
        },
        { status: 503 },
      );
    }

    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
    if (!item) {
      return Response.json({ error: "Produk atau nominal tidak tersedia." }, { status: 404 });
    }
    if (
      item.providerCode === "voucher-stock" &&
      item.providerSku &&
      !(await hasAvailableVoucherStock(item.providerSku))
    ) {
      return Response.json({ error: "Stok kode untuk paket ini sedang habis." }, { status: 409 });
    }

    const customer = await getCustomerSession(request);
    const membership = customer ? await getMemberTierProfile(customer.id) : null;
    const promotion = await quotePromotion(
      item.productSlug,
      item.packageSku,
      item.price,
      input.voucherCode,
      membership
        ? {
            tier: membership.tier,
            discountPercent: membership.setting.discountPercent,
          }
        : null,
    );

    const customerData = normalizeCustomerInputs(
      item,
      input.customerInputs,
      input.destination,
      input.server || null,
    );
    const verifiedAccount = await verifyNicknameForCheckout({
      productSlug: item.productSlug,
      userId: customerData.destination,
      server: customerData.server,
    });
    const identity = createOrderIdentity();
    referenceId = identity.referenceId;

    await insertPendingOrder({
      ...identity,
      item,
      destination: customerData.destination,
      server: customerData.server,
      nickname: verifiedAccount.nickname,
      buyerName: input.buyerName,
      buyerEmail: input.buyerEmail,
      buyerPhone: input.buyerPhone,
      customerNotes: input.customerNotes || null,
      customerInputs: customerData.values,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      customerId: customer?.id ?? null,
      externalCheckoutKey: input.idempotencyKey,
      promotion,
    });

    const baseUrl = getPublicBaseUrl();
    const invoice = publicInvoice(identity.referenceId);
    const payment = await createDokuDirectPayment({
      referenceId: identity.referenceId,
      amount: promotion.finalPrice,
      productName: `${item.productName} - ${item.packageLabel}`,
      buyerName: input.buyerName,
      buyerEmail: input.buyerEmail,
      buyerPhone: input.buyerPhone,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      finishUrl: `${baseUrl}/payment?invoice=${encodeURIComponent(invoice)}`,
    });

    await updateDokuPayment({
      referenceId: identity.referenceId,
      requestId: payment.requestId,
      referenceNo: payment.referenceNo,
      paymentNo: payment.paymentNo,
      qrContent: payment.qrContent,
      paymentName: payment.paymentName,
      paymentUrl: payment.paymentUrl,
      expiredAt: payment.expiredAt,
      total: promotion.finalPrice,
    });
    await recordOrderEvent({
      orderId: identity.id,
      source: "doku",
      eventId: `create-${payment.requestId}`,
      status: "pending",
      payload: payment.raw,
    });

    return Response.json(
      {
        orderId: identity.id,
        referenceId: identity.referenceId,
        publicInvoice: invoice,
        fulfillmentType: item.fulfillmentType,
        basePrice: promotion.basePrice,
        sellingPrice: promotion.sellingPrice,
        discountAmount: promotion.discountAmount,
        voucherCode: promotion.voucherCode,
        flashSaleId: promotion.flashSaleId,
        memberTier: promotion.memberTier,
        memberDiscountPercent: promotion.memberDiscountPercent,
        discountSource: promotion.discountSource,
        paymentMethod: input.paymentMethod,
        paymentNo: payment.paymentNo,
        qrContent: payment.qrContent,
        paymentName: publicPaymentLabel(input.paymentMethod, paymentChannel),
        paymentUrl: payment.paymentUrl,
        fee: 0,
        total: promotion.finalPrice,
        expiredAt: payment.expiredAt,
      },
      { status: 201 },
    );
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message || "Data checkout tidak valid."
        : error instanceof Error
          ? error.message
          : "Pembayaran gagal dibuat.";

    if (
      checkoutKey &&
      error instanceof Error &&
      /UNIQUE constraint failed.*external_checkout_key/i.test(error.message)
    ) {
      const priorOrder = await getExternalOrderByCheckoutKey(checkoutKey);
      if (priorOrder) return existingExternalResponse(priorOrder);
    }

    if (referenceId) {
      await markPaymentCreationFailed(referenceId, message).catch(() => undefined);
    }
    const invalidInput =
      error instanceof z.ZodError ||
      error instanceof NicknameValidationError;
    const serviceUnavailable = error instanceof NicknameServiceError;
    return Response.json(
      { error: message },
      { status: invalidInput ? 400 : serviceUnavailable ? 503 : 503 },
    );
  }
}
