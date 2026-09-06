import { z } from "zod";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import {
  createIpaymuDirectPayment,
  isIpaymuChannelSupported,
} from "@/lib/server/ipaymu";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import {
  createIpaymuWalletTopup,
  readWalletSettings,
  updateIpaymuWalletTopup,
} from "@/lib/server/wallet";

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
  if (!rate.allowed)
    return Response.json(
      { error: "Terlalu banyak permintaan top up. Coba lagi beberapa menit." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter) } },
    );

  try {
    if (!request.headers.get("content-type")?.includes("application/json"))
      throw new Error("Top up saldo hanya tersedia melalui payment gateway otomatis.");

    const settings = await readWalletSettings();
    const input = automaticSchema.parse(await request.json());

    if (!settings.ipaymuTopupEnabled)
      throw new Error("Top up otomatis iPaymu belum diaktifkan oleh Pemilik.");
    if (input.amount < settings.minTopup)
      throw new Error(`Minimum top up Rp${settings.minTopup.toLocaleString("id-ID")}.`);

    const paymentChannel =
      input.paymentMethod === "qris" && input.paymentChannel === "qris"
        ? "mpm"
        : input.paymentChannel;

    if (
      !(await isPaymentChannelAvailable(input.paymentMethod, paymentChannel)) ||
      !isIpaymuChannelSupported(input.paymentMethod, paymentChannel)
    )
      throw new Error("Metode pembayaran iPaymu tidak tersedia.");

    const referenceId = `WLT-${crypto.randomUUID().replace(/-/g, "").slice(0, 20).toUpperCase()}`;

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
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Permintaan top up gagal." },
      { status: 400 },
    );
  }
}
