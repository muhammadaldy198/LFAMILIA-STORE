import { z } from "zod";
import { getCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import {
  createMidtransPayment,
  getMidtransMode,
  isMidtransChannelSupported,
} from "@/lib/server/midtrans";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { quotePromotion } from "@/lib/server/promotions";
import {
  createOrderIdentity,
  insertPendingOrder,
  normalizeCustomerInputs,
  markPaymentCreationFailed,
  recordOrderEvent,
  resolvePurchasableItem,
  updateMidtransPayment,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { hasAvailableVoucherStock } from "@/lib/server/vouchers";
import { readWalletSettings } from "@/lib/server/wallet";
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
  const rate = await allowRequest(request, "midtrans-checkout", 12, 600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak percobaan checkout. Coba lagi beberapa menit." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  let referenceId: string | null = null;
  try {
    const input = schema.parse(await request.json());
    const paymentChannel = input.paymentMethod === "qris" && input.paymentChannel === "qris" ? "mpm" : input.paymentChannel;
    const settings = await readWalletSettings();
    if (!settings.midtransCheckoutEnabled)
      return Response.json({ error: "Checkout Midtrans belum diaktifkan oleh Pemilik." }, { status: 403 });
    const midtransMode = getMidtransMode();
    if (
      !(await isPaymentChannelAvailable(input.paymentMethod, paymentChannel)) ||
      !isMidtransChannelSupported(
        input.paymentMethod,
        paymentChannel,
        midtransMode,
      )
    )
      return Response.json(
        { error: "Metode pembayaran tidak tersedia pada mode Midtrans aktif." },
        { status: 400 },
      );

    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
    if (!item)
      return Response.json({ error: "Produk atau nominal tidak tersedia." }, { status: 404 });
    if (item.providerCode === "voucher-stock" && item.providerSku && !(await hasAvailableVoucherStock(item.providerSku)))
      return Response.json({ error: "Stok kode untuk paket ini sedang habis." }, { status: 409 });

    const customer = await getCustomerSession(request);
    const membership = customer ? await getMemberTierProfile(customer.id) : null;
    const promotion = await quotePromotion(
      item.productSlug,
      item.packageSku,
      item.price,
      input.voucherCode,
      membership ? { tier: membership.tier, discountPercent: membership.setting.discountPercent } : null,
    );
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

    return Response.json({
      orderId: identity.id,
      referenceId: identity.referenceId,
      paymentGateway: "midtrans",
      publicInvoice: invoice,
      paymentNo: payment.paymentNo,
      paymentName: payment.paymentName,
      paymentUrl: payment.paymentUrl,
      fee: 0,
      total: promotion.finalPrice,
      expiredAt: payment.expiredAt,
      paymentMethod: input.paymentMethod,
      midtransMode: payment.mode,
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
    }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message || "Data checkout tidak valid."
      : error instanceof Error
        ? error.message
        : "Pembayaran Midtrans gagal dibuat.";
    if (referenceId) await markPaymentCreationFailed(referenceId, message).catch(() => undefined);
    return Response.json({ error: message }, { status: error instanceof z.ZodError ? 400 : 503 });
  }
}
