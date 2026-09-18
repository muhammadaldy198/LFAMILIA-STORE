import { z } from "zod";
import { publicPaymentLabel } from "@/lib/public-payment";
import { isAutomaticPackageAvailable } from "@/lib/server/availability";
import { getCustomerSession } from "@/lib/server/customer-auth";
import {
  externalArtifactsFromOrder,
  recordExternalPaymentEvent,
  updateExternalPayment,
} from "@/lib/server/external-payments";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import {
  NicknameServiceError,
  NicknameValidationError,
  verifyNicknameForCheckout,
} from "@/lib/server/nickname-check";
import {
  calculateCustomerPaymentFee,
  getPaymentChannel,
  isGatewayChannelSupported,
  isPaymentGatewayActive,
} from "@/lib/server/payment-channels";
import { createConfiguredPayment, getConfiguredGatewayReadiness } from "@/lib/server/payment-router";
import {
  quotePromotion,
  releaseExternalPromotion,
  reserveExternalPromotion,
  updateExternalPromotionExpiry,
} from "@/lib/server/promotions";
import {
  createOrderIdentity,
  getExternalOrderByCheckoutKey,
  insertPendingOrder,
  markPaymentCreationFailed,
  normalizeCustomerInputs,
  resolvePurchasableItem,
  type OrderRecord,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

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
  return referenceId.trim().toUpperCase();
}

function existingExternalResponse(order: OrderRecord) {
  if (["failed", "expired"].includes(order.payment_status)) {
    return Response.json(
      { error: "Percobaan pembayaran sebelumnya sudah gagal atau kedaluwarsa. Buat pembayaran baru.", retryable: false },
      { status: 409 },
    );
  }
  const artifacts = externalArtifactsFromOrder(order as unknown as Record<string, unknown>);
  if (!artifacts.requestId) {
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
    paymentNo: artifacts.paymentNo,
    qrContent: artifacts.qrContent,
    paymentName: publicPaymentLabel(order.payment_method, order.payment_channel),
    paymentUrl: artifacts.paymentUrl,
    fee: order.admin_fee,
    total: order.total,
    expiredAt: artifacts.expiredAt,
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
  let orderId: string | null = null;
  let paymentDispatchStarted = false;
  try {
    const input = routingSchema.parse(await request.json());
    checkoutKey = input.idempotencyKey;
    const priorOrder = await getExternalOrderByCheckoutKey(input.idempotencyKey);
    if (priorOrder) return existingExternalResponse(priorOrder);

    const paymentChannel =
      input.paymentMethod === "qris" && input.paymentChannel === "qris"
        ? "mpm"
        : input.paymentChannel;
    const managedChannel = await getPaymentChannel(input.paymentMethod, paymentChannel, false);
    if (
      !managedChannel ||
      !isGatewayChannelSupported(
        managedChannel.gateway,
        input.paymentMethod,
        paymentChannel,
        managedChannel.gatewayConfig,
      )
    ) {
      return Response.json(
        { error: "Metode pembayaran belum didukung atau sedang dinonaktifkan." },
        { status: 400 },
      );
    }
    if (!(await isPaymentGatewayActive(managedChannel.gateway))) {
      return Response.json(
        { error: "Metode pembayaran sedang dinonaktifkan." },
        { status: 503 },
      );
    }

    const readiness = await getConfiguredGatewayReadiness({
      gateway: managedChannel.gateway,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      gatewayConfig: managedChannel.gatewayConfig,
    });
    if (!readiness.ready) {
      return Response.json({ error: "Metode pembayaran ini belum siap." }, { status: 503 });
    }

    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
    if (!item) {
      return Response.json({ error: "Produk atau nominal tidak tersedia." }, { status: 404 });
    }
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

    const paymentFee = calculateCustomerPaymentFee(
      promotion.finalPrice,
      managedChannel.gatewayConfig,
    );
    const paymentTotal = promotion.finalPrice + paymentFee;

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
      adminFee: paymentFee,
    });

    orderId = identity.id;
    await reserveExternalPromotion({
      orderId,
      voucherCode: promotion.voucherCode,
      flashSaleId: promotion.flashSaleId,
      expiresAt: new Date(Date.now() + 30 * 60_000).toISOString(),
    });

    const baseUrl = getPublicBaseUrl();
    const invoice = publicInvoice(identity.referenceId);
    paymentDispatchStarted = true;
    const payment = await createConfiguredPayment({
      gateway: managedChannel.gateway,
      referenceId: identity.referenceId,
      amount: paymentTotal,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      gatewayConfig: managedChannel.gatewayConfig,
      buyerName: input.buyerName,
      buyerEmail: input.buyerEmail,
      buyerPhone: input.buyerPhone,
      productName: item.productName,
      packageLabel: item.packageLabel,
      finishUrl: `${baseUrl}/payment?invoice=${encodeURIComponent(invoice)}`,
      deviceId: input.idempotencyKey,
    });

    await updateExternalPayment({
      referenceId: identity.referenceId,
      gateway: payment.gateway,
      mode: payment.mode,
      environment: payment.environment,
      requestId: payment.requestId,
      referenceNo: payment.referenceNo || null,
      paymentNo: payment.paymentNo || null,
      qrContent: payment.qrContent || null,
      paymentName: payment.paymentName || null,
      paymentUrl: payment.paymentUrl || null,
      expiredAt: payment.expiredAt || null,
      total: paymentTotal,
    });
    await updateExternalPromotionExpiry(identity.id, payment.expiredAt || null);
    await recordExternalPaymentEvent({
      orderId: identity.id,
      gateway: payment.gateway,
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
        paymentNo: payment.paymentNo || null,
        qrContent: payment.qrContent || null,
        paymentName: publicPaymentLabel(input.paymentMethod, paymentChannel),
        paymentUrl: payment.paymentUrl || null,
        fee: paymentFee,
        total: paymentTotal,
        expiredAt: payment.expiredAt || null,
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

    // Once a request has been dispatched to a gateway, a timeout/error is
    // ambiguous: the provider may already have created a payable transaction.
    // Keep the local invoice pending so a signed callback can still settle it.
    // The scheduler expires unresolved attempts after the safe provider window.
    if (!paymentDispatchStarted) {
      if (orderId) await releaseExternalPromotion(orderId).catch(() => undefined);
      if (referenceId) {
        await markPaymentCreationFailed(referenceId, message).catch(() => undefined);
      }
    }
    const invalidInput =
      error instanceof z.ZodError ||
      error instanceof NicknameValidationError;
    const serviceUnavailable = error instanceof NicknameServiceError;
    return Response.json(
      {
        error: paymentDispatchStarted && !invalidInput
          ? "Status pembuatan pembayaran belum dapat dipastikan. Jangan bayar dua kali; coba cek invoice ini beberapa saat lagi."
          : message,
        retryable: paymentDispatchStarted && !invalidInput,
      },
      { status: invalidInput ? 400 : serviceUnavailable ? 503 : 503 },
    );
  }
}
