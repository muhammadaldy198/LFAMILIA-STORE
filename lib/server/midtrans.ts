import { hashHex, safeEqual } from "@/lib/server/crypto";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

type MidtransRuntime = {
  MIDTRANS_ENV?: string;
  MIDTRANS_SERVER_KEY?: string;
};

type SnapResponse = {
  token?: string;
  redirect_url?: string;
  status_message?: string;
};

export type MidtransPaymentResult = {
  transactionId: string | null;
  paymentUrl: string;
  raw: SnapResponse;
};

function runtimeConfig() {
  const runtime = getRuntimeEnv<MidtransRuntime>();
  const serverKey = runtime.MIDTRANS_SERVER_KEY?.trim();
  if (!serverKey)
    throw new Error(
      "MIDTRANS_SERVER_KEY belum dikonfigurasi sebagai Cloudflare Secret.",
    );
  const sandbox = runtime.MIDTRANS_ENV?.toLowerCase() !== "production";
  return {
    serverKey,
    endpoint: sandbox
      ? "https://app.sandbox.midtrans.com/snap/v1/transactions"
      : "https://app.midtrans.com/snap/v1/transactions",
  };
}

function enabledPayments(method: string, channel: string) {
  if (method === "qris") return ["qris"];
  if (method === "ewallet") {
    const map: Record<string, string> = {
      gopay: "gopay",
      shopeepay: "shopeepay",
      dana: "dana",
      ovo: "ovo",
    };
    return map[channel] ? [map[channel]] : undefined;
  }
  if (method === "va") {
    const map: Record<string, string> = {
      bca: "bca_va",
      bni: "bni_va",
      bri: "bri_va",
      permata: "permata_va",
      mandiri: "echannel",
    };
    return map[channel] ? [map[channel]] : undefined;
  }
  return undefined;
}

export async function createMidtransSnapPayment(input: {
  referenceId: string;
  amount: number;
  productName: string;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
  paymentMethod: string;
  paymentChannel: string;
  finishUrl: string;
}) {
  const { serverKey, endpoint } = runtimeConfig();
  const payments = enabledPayments(input.paymentMethod, input.paymentChannel);
  const body = {
    transaction_details: {
      order_id: input.referenceId,
      gross_amount: input.amount,
    },
    item_details: [
      {
        id: input.referenceId,
        price: input.amount,
        quantity: 1,
        name: input.productName.slice(0, 50),
      },
    ],
    customer_details: {
      first_name: input.buyerName.slice(0, 50),
      email: input.buyerEmail,
      phone: input.buyerPhone,
    },
    callbacks: { finish: input.finishUrl },
    ...(payments ? { enabled_payments: payments } : {}),
  };
  const response = await fetch(endpoint, {
    method: "POST",
    headers: {
      accept: "application/json",
      "content-type": "application/json",
      authorization: `Basic ${Buffer.from(`${serverKey}:`).toString("base64")}`,
    },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(15_000),
  });
  const payload = (await response.json().catch(() => ({}))) as SnapResponse;
  if (!response.ok || !payload.redirect_url)
    throw new Error(
      payload.status_message || "Midtrans menolak pembuatan pembayaran.",
    );
  return {
    transactionId: payload.token ?? null,
    paymentUrl: payload.redirect_url,
    raw: payload,
  } satisfies MidtransPaymentResult;
}

export function validateMidtransNotification(input: Record<string, unknown>) {
  const { serverKey } = runtimeConfig();
  const orderId = String(input.order_id ?? "");
  const statusCode = String(input.status_code ?? "");
  const grossAmount = String(input.gross_amount ?? "");
  const signature = String(input.signature_key ?? "");
  const expected = hashHex(
    "sha512",
    `${orderId}${statusCode}${grossAmount}${serverKey}`,
  );
  return {
    valid:
      Boolean(orderId && statusCode && grossAmount) &&
      safeEqual(signature, expected),
    orderId,
    grossAmount,
  };
}

export function mapMidtransStatus(input: Record<string, unknown>) {
  const status = String(input.transaction_status ?? "").toLowerCase();
  const fraud = String(input.fraud_status ?? "accept").toLowerCase();
  if (status === "settlement" || (status === "capture" && fraud === "accept"))
    return "paid" as const;
  if (status === "expire") return "expired" as const;
  if (["deny", "cancel", "failure"].includes(status)) return "failed" as const;
  return "pending" as const;
}
