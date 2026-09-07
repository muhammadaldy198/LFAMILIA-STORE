import { z } from "zod";
import { getCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import { routePaymentGateway } from "@/lib/server/payment-gateway-router";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { quotePromotion } from "@/lib/server/promotions";
import { resolvePurchasableItem } from "@/lib/server/orders";
import { readWalletSettings } from "@/lib/server/wallet";
import { IPAYMU_MIN_CHECKOUT_AMOUNT } from "@/lib/payment-limits";
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
    const routing = routePaymentGateway({
      amount: promotion.finalPrice,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      ipaymuEnabled: settings.ipaymuCheckoutEnabled,
      midtransEnabled: settings.midtransCheckoutEnabled,
    });

    const [primary, fallback] = routing.candidates;

    if (primary === "ipaymu") {
      const ipaymuResponse = await createIpaymuCheckout(ipaymuRequest);
      if (ipaymuResponse.ok) return ipaymuResponse;

      const failed = (await ipaymuResponse.clone().json().catch(() => null)) as {
        fallbackAllowed?: boolean;
      } | null;
      if (!failed?.fallbackAllowed || fallback !== "midtrans") {
        return ipaymuResponse;
      }
      return createMidtransCheckout(midtransRequest);
    }

    if (primary === "midtrans") {
      return createMidtransCheckout(midtransRequest);
    }

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
