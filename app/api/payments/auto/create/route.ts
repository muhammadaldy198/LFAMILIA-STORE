import { z } from "zod";
import { getCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import {
  getIpaymuReadiness,
  isIpaymuChannelSupported,
} from "@/lib/server/ipaymu";
import {
  getMidtransMode,
  getMidtransReadiness,
  isMidtransChannelSupported,
} from "@/lib/server/midtrans";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { quotePromotion } from "@/lib/server/promotions";
import { resolvePurchasableItem } from "@/lib/server/orders";
import { readWalletSettings } from "@/lib/server/wallet";
import {
  IPAYMU_MIN_CHECKOUT_AMOUNT,
  isIpaymuAmountSupported,
} from "@/lib/payment-limits";
import { POST as createIpaymuCheckout } from "@/app/api/payments/ipaymu/create/route";
import { POST as createMidtransCheckout } from "@/app/api/payments/midtrans/create/route";

export const dynamic = "force-dynamic";

const routingSchema = z.object({
  productSlug: z.string().trim().min(2).max(80),
  packageSku: z.string().trim().min(2).max(100),
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
  voucherCode: z.string().trim().max(40).optional(),
});

export async function POST(request: Request) {
  try {
    const ipaymuRequest = request.clone();
    const midtransRequest = request.clone();
    const input = routingSchema.parse(await request.clone().json());
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
    const ipaymuReadiness = getIpaymuReadiness();
    const midtransReadiness = getMidtransReadiness();

    const canUseIpaymu =
      settings.ipaymuCheckoutEnabled &&
      ipaymuReadiness.ready &&
      isIpaymuAmountSupported(promotion.finalPrice) &&
      isIpaymuChannelSupported(input.paymentMethod, paymentChannel);

    const canUseMidtrans =
      settings.midtransCheckoutEnabled &&
      midtransReadiness.ready &&
      isMidtransChannelSupported(
        input.paymentMethod,
        paymentChannel,
        getMidtransMode(),
      );

    if (canUseIpaymu) {
      const ipaymuResponse = await createIpaymuCheckout(ipaymuRequest);
      if (ipaymuResponse.ok) return ipaymuResponse;

      const failed = (await ipaymuResponse.clone().json().catch(() => null)) as {
        fallbackAllowed?: boolean;
      } | null;
      if (!failed?.fallbackAllowed || !canUseMidtrans) {
        return ipaymuResponse;
      }
      return createMidtransCheckout(midtransRequest);
    }

    if (canUseMidtrans) {
      return createMidtransCheckout(midtransRequest);
    }

    if (
      settings.ipaymuCheckoutEnabled &&
      !isIpaymuAmountSupported(promotion.finalPrice)
    ) {
      return Response.json(
        {
          error: `Nominal ini di bawah minimum iPaymu Rp${IPAYMU_MIN_CHECKOUT_AMOUNT.toLocaleString("id-ID")} dan gateway alternatif belum siap untuk metode yang dipilih.`,
        },
        { status: 422 },
      );
    }

    return Response.json(
      {
        error:
          "Tidak ada payment gateway yang siap untuk metode ini. Periksa status gateway, credential environment aktif, dan channel pembayaran di panel admin.",
      },
      { status: 503 },
    );
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message || "Data checkout tidak valid."
        : error instanceof Error
          ? error.message
          : "Routing pembayaran gagal.";
    return Response.json({ error: message }, { status: 400 });
  }
}
