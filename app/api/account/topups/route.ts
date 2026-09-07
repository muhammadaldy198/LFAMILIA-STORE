import { z } from "zod";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { createMidtransPayment } from "@/lib/server/midtrans";
import { createIpaymuDirectPayment } from "@/lib/server/ipaymu";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { routePaymentGateway } from "@/lib/server/payment-gateway-router";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { createIpaymuWalletTopup, createMidtransWalletTopup, readWalletSettings, updateIpaymuWalletTopup, updateMidtransWalletTopup } from "@/lib/server/wallet";

const automaticSchema = z.object({
  amount: z.number().int().min(1000).max(100_000_000),
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  const rate = await allowRequest(request, "wallet-topup", 8, 900);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak permintaan top up. Coba lagi beberapa menit." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });

  try {
    if (!request.headers.get("content-type")?.includes("application/json")) {
      throw new Error("Top up saldo hanya tersedia melalui payment gateway otomatis.");
    }
    const settings = await readWalletSettings();
    const input = automaticSchema.parse(await request.json());
    if (input.amount < settings.minTopup)
      throw new Error(`Minimum top up Rp${settings.minTopup.toLocaleString("id-ID")}.`);

    const paymentChannel =
      input.paymentMethod === "qris" && input.paymentChannel === "qris"
        ? "mpm"
        : input.paymentChannel;

    if (!(await isPaymentChannelAvailable(input.paymentMethod, paymentChannel)))
      throw new Error("Metode pembayaran otomatis tidak tersedia.");

    const routing = routePaymentGateway({
      amount: input.amount,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      ipaymuEnabled: settings.ipaymuTopupEnabled,
      midtransEnabled: settings.midtransTopupEnabled,
    });
    const gateway = routing.candidates[0];
    if (!gateway)
      throw new Error(
        "Tidak ada payment gateway yang siap untuk top up ini. Periksa toggle, credential, nominal, dan channel pembayaran.",
      );

    const referenceId = `WLT-${crypto.randomUUID().replace(/-/g, "").slice(0, 20).toUpperCase()}`;

    if (gateway === "ipaymu") {
      await createIpaymuWalletTopup({
        customerId: customer.id,
        amount: input.amount,
        name: customer.name,
        paymentMethod: input.paymentMethod,
        paymentChannel,
        referenceId,
      });
      const payment = await createIpaymuDirectPayment({
        name: customer.name,
        phone: customer.phone,
        email: customer.email,
        amount: input.amount,
        notifyUrl: `${getPublicBaseUrl()}/api/payments/ipaymu/callback`,
        referenceId,
        paymentMethod: input.paymentMethod,
        paymentChannel,
        productName: "Top up Saldo LFAMILIA",
        productPrice: input.amount,
      });
      await updateIpaymuWalletTopup({
        referenceId,
        transactionId: payment.transactionId,
        paymentNo: payment.paymentNo,
        paymentName: payment.paymentName,
        paymentUrl: payment.paymentUrl,
        expiredAt: payment.expiredAt,
        fee: payment.fee,
        total: payment.total,
      });
      return Response.json(
        {
          ok: true,
          mode: "ipaymu",
          referenceId,
          paymentMethod: input.paymentMethod,
          paymentChannel,
          paymentNo: payment.paymentNo,
          paymentName: payment.paymentName,
          paymentUrl: payment.paymentUrl,
          total: payment.total,
          fee: payment.fee,
          expiredAt: payment.expiredAt,
        },
        { status: 201 },
      );
    }

    await createMidtransWalletTopup({
      customerId: customer.id,
      amount: input.amount,
      name: customer.name,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      referenceId,
    });
    const payment = await createMidtransPayment({
      buyerName: customer.name,
      buyerPhone: customer.phone,
      buyerEmail: customer.email,
      amount: input.amount,
      referenceId,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      productName: "Top up Saldo LFAMILIA",
      finishUrl: `${getPublicBaseUrl()}/account`,
    });
    await updateMidtransWalletTopup({
      referenceId,
      mode: payment.mode,
      transactionId: payment.transactionId,
      paymentNo: payment.paymentNo,
      paymentName: payment.paymentName,
      paymentUrl: payment.paymentUrl,
      expiredAt: payment.expiredAt,
      fee: 0,
      total: input.amount,
    });
    return Response.json(
      {
        ok: true,
        mode: "midtrans",
        midtransMode: payment.mode,
        referenceId,
        paymentMethod: input.paymentMethod,
        paymentChannel,
        paymentNo: payment.paymentNo,
        paymentName: payment.paymentName,
        paymentUrl: payment.paymentUrl,
        total: input.amount,
        fee: 0,
        expiredAt: payment.expiredAt,
      },
      { status: 201 },
    );
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Permintaan top up gagal." }, { status: 400 });
  }
}
