import { z } from "zod";
import { getCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import {
  createIpaymuDirectPayment,
  IpaymuProviderError,
} from "@/lib/server/ipaymu";
import { createMidtransPayment } from "@/lib/server/midtrans";
import { routePaymentGateway } from "@/lib/server/payment-gateway-router";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { PromotionQuoteError, quotePromotion } from "@/lib/server/promotions";
import {
  createOrderIdentity,
  insertPendingOrder,
  markPaymentCreationFailed,
  normalizeCustomerInputs,
  recordOrderEvent,
  resolvePurchasableItem,
  updateIpaymuPayment,
  updateMidtransPayment,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { hasAvailableVoucherStock } from "@/lib/server/vouchers";
import { readWalletSettings } from "@/lib/server/wallet";
import { IPAYMU_MIN_CHECKOUT_AMOUNT } from "@/lib/payment-limits";

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
  nickname: z.string().trim().max(100).optional(),
  buyerName: z.string().trim().min(2).max(100),
  buyerEmail: z.string().trim().email().max(150),
  buyerPhone: z.string().trim().regex(/^\+?[0-9]{8,16}$/),
  customerNotes: z.string().trim().max(500).optional(),
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
  voucherCode: z.string().trim().max(40).optional(),
});

function publicInvoice(referenceId: string) {
  const clean = referenceId.trim().toUpperCase();
  if (!clean.includes("-")) return clean;
  const token = clean.split("-").at(-1) ?? clean.replace(/^LF/, "");
  return `LF${token}`;
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "automatic-checkout", 12, 600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak percobaan checkout. Coba lagi beberapa menit." },
      {
        status: 429,
        headers: { "Retry-After": String(rate.retryAfter) },
      },
    );
  }

  let referenceId: string | null = null;

  try {
    const input = routingSchema.parse(await request.json());
    const paymentChannel =
      input.paymentMethod === "qris" && input.paymentChannel === "qris"
        ? "mpm"
        : input.paymentChannel;

    if (!(await isPaymentChannelAvailable(input.paymentMethod, paymentChannel))) {
      return Response.json(
        { error: "Metode pembayaran sedang tidak tersedia." },
        { status: 400 },
      );
    }

    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
    if (!item) {
      return Response.json(
        { error: "Produk atau nominal tidak tersedia." },
        { status: 404 },
      );
    }
    if (
      item.providerCode === "voucher-stock" &&
      item.providerSku &&
      !(await hasAvailableVoucherStock(item.providerSku))
    ) {
      return Response.json(
        { error: "Stok kode untuk paket ini sedang habis." },
        { status: 409 },
      );
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

    const settings = await readWalletSettings();
    const routing = routePaymentGateway({
      amount: promotion.finalPrice,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      ipaymuEnabled: settings.ipaymuCheckoutEnabled,
      midtransEnabled: settings.midtransCheckoutEnabled,
    });
    const [primary, fallback] = routing.candidates;

    if (!primary) {
      if (
        settings.ipaymuCheckoutEnabled &&
        promotion.finalPrice < IPAYMU_MIN_CHECKOUT_AMOUNT &&
        !routing.midtransEligible
      ) {
        return Response.json(
          {
            error: `iPaymu tersedia mulai Rp${IPAYMU_MIN_CHECKOUT_AMOUNT.toLocaleString("id-ID")}. Pilih nominal lain atau gunakan Koin LFAMILIA.`,
          },
          { status: 422 },
        );
      }
      return Response.json(
        {
          error:
            "Tidak ada payment gateway yang siap untuk metode ini. Periksa toggle gateway, credential environment aktif, dan channel pembayaran di panel admin.",
        },
        { status: 503 },
      );
    }

    const customerData = normalizeCustomerInputs(
      item,
      input.customerInputs,
      input.destination,
      input.server || null,
    );
    const identity = createOrderIdentity();
    referenceId = identity.referenceId;

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
      paymentChannel,
      customerId: customer?.id ?? null,
      promotion,
    });

    const baseUrl = getPublicBaseUrl();
    const invoice = publicInvoice(identity.referenceId);

    const shared = {
      orderId: identity.id,
      referenceId: identity.referenceId,
      publicInvoice: invoice,
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
      paymentMethod: input.paymentMethod,
    };

    if (primary === "ipaymu") {
      try {
        const payment = await createIpaymuDirectPayment({
          name: input.buyerName,
          phone: input.buyerPhone,
          email: input.buyerEmail,
          amount: promotion.finalPrice,
          notifyUrl: `${baseUrl}/api/payments/ipaymu/callback`,
          referenceId: identity.referenceId,
          paymentMethod: input.paymentMethod,
          paymentChannel,
          productName: `${item.productName} - ${item.packageLabel}`,
          productPrice: promotion.finalPrice,
        });

        await updateIpaymuPayment({
          referenceId: identity.referenceId,
          transactionId: payment.transactionId,
          paymentNo: payment.paymentNo,
          paymentName: payment.paymentName,
          paymentUrl: payment.paymentUrl,
          expiredAt: payment.expiredAt,
          fee: payment.fee,
          total: payment.total,
        });
        await recordOrderEvent({
          orderId: identity.id,
          source: "ipaymu",
          eventId: `create-${identity.referenceId}`,
          status: "pending",
          payload: payment.raw,
        });

        return Response.json(
          {
            ...shared,
            paymentGateway: "ipaymu",
            paymentNo: payment.paymentNo,
            paymentName: payment.paymentName,
            paymentUrl: payment.paymentUrl,
            fee: payment.fee,
            total: payment.total,
            expiredAt: payment.expiredAt,
          },
          { status: 201 },
        );
      } catch (error) {
        const safeFallback =
          error instanceof IpaymuProviderError && error.safeToFallback;
        if (!safeFallback || fallback !== "midtrans") throw error;

        await recordOrderEvent({
          orderId: identity.id,
          source: "ipaymu",
          eventId: `create-failed-${identity.referenceId}`,
          status: "failed",
          payload: {
            fallback: "midtrans",
            message: error.message,
          },
        });
      }
    }

    const payment = await createMidtransPayment({
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

    await updateMidtransPayment({
      referenceId: identity.referenceId,
      mode: payment.mode,
      transactionId: payment.transactionId,
      paymentNo: payment.paymentNo,
      paymentName: payment.paymentName,
      paymentUrl: payment.paymentUrl,
      expiredAt: payment.expiredAt,
      fee: 0,
      total: promotion.finalPrice,
    });
    await recordOrderEvent({
      orderId: identity.id,
      source: "midtrans",
      eventId: `create-${identity.referenceId}`,
      status: "pending",
      payload: payment.raw,
    });

    return Response.json(
      {
        ...shared,
        paymentGateway: "midtrans",
        paymentNo: payment.paymentNo,
        paymentName: payment.paymentName,
        paymentUrl: payment.paymentUrl,
        fee: 0,
        total: promotion.finalPrice,
        expiredAt: payment.expiredAt,
        midtransMode: payment.mode,
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

    if (referenceId) {
      await markPaymentCreationFailed(referenceId, message).catch(() => undefined);
    }

    return Response.json(
      { error: message },
      {
        status:
          error instanceof z.ZodError
            ? 400
            : error instanceof PromotionQuoteError
              ? 409
              : 503,
      },
    );
  }
}
