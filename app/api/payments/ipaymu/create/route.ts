import { z } from "zod";
import { getCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import {
  createIpaymuDirectPayment,
  IpaymuProviderError,
  isIpaymuChannelSupported,
} from "@/lib/server/ipaymu";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { quotePromotion } from "@/lib/server/promotions";
import {
  createOrderIdentity,
  insertPendingOrder,
  normalizeCustomerInputs,
  markPaymentCreationFailed,
  recordOrderEvent,
  resolvePurchasableItem,
  updateIpaymuPayment,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { hasAvailableVoucherStock } from "@/lib/server/vouchers";
import { readWalletSettings } from "@/lib/server/wallet";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { IPAYMU_MIN_CHECKOUT_AMOUNT, isIpaymuAmountSupported } from "@/lib/payment-limits";

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
  const rate = await allowRequest(request, "ipaymu-checkout", 12, 600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak percobaan checkout. Coba lagi beberapa menit." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  let referenceId: string | null = null;
  try {
    const input = schema.parse(await request.json());
    const paymentChannel =
      input.paymentMethod === "qris" && input.paymentChannel === "qris"
        ? "mpm"
        : input.paymentChannel;

    const settings = await readWalletSettings();
    if (!settings.ipaymuCheckoutEnabled)
      return Response.json(
        { error: "Checkout iPaymu belum diaktifkan oleh Pemilik." },
        { status: 403 },
      );

    if (
      !isIpaymuChannelSupported(input.paymentMethod, paymentChannel) ||
      !(await isPaymentChannelAvailable(input.paymentMethod, paymentChannel))
    )
      return Response.json(
        { error: "Metode pembayaran iPaymu tidak tersedia." },
        { status: 400 },
      );

    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
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
        ? {
            tier: membership.tier,
            discountPercent: membership.setting.discountPercent,
          }
        : null,
    );

    if (!isIpaymuAmountSupported(promotion.finalPrice)) {
      return Response.json(
        {
          error: `iPaymu hanya tersedia mulai Rp${IPAYMU_MIN_CHECKOUT_AMOUNT.toLocaleString("id-ID")}. Pilih nominal lain atau gunakan Koin LFAMILIA.`,
        },
        { status: 422 },
      );
    }

    const customerData = normalizeCustomerInputs(item, input.customerInputs, input.destination, input.server || null);
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
        orderId: identity.id,
        referenceId: identity.referenceId,
        paymentGateway: "ipaymu",
        publicInvoice: invoice,
        paymentNo: payment.paymentNo,
        paymentName: payment.paymentName,
        paymentUrl: payment.paymentUrl,
        fee: payment.fee,
        total: payment.total,
        expiredAt: payment.expiredAt,
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
          : "Pembayaran iPaymu gagal dibuat.";
    if (referenceId)
      await markPaymentCreationFailed(referenceId, message).catch(() => undefined);
    const safeFallback =
      error instanceof IpaymuProviderError && error.safeToFallback;
    return Response.json(
      {
        error: message,
        fallbackAllowed: safeFallback,
      },
      {
        status:
          error instanceof z.ZodError
            ? 400
            : safeFallback
              ? 502
              : 503,
      },
    );
  }
}
