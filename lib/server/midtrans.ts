import { hashHex, safeEqual } from "@/lib/server/crypto";
import {
  getRuntimeEnv,
  requireRuntimeChoice,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

export type MidtransEnvironment = "sandbox" | "production";
export type MidtransMode = "snap" | "bisnap";

type MidtransRuntime = {
  MIDTRANS_MODE?: string;
  MIDTRANS_ENV?: string;
  MIDTRANS_SNAP_SANDBOX_SERVER_KEY?: string;
  MIDTRANS_SNAP_PRODUCTION_SERVER_KEY?: string;
  MIDTRANS_SNAP_SANDBOX_API_URL?: string;
  MIDTRANS_SNAP_PRODUCTION_API_URL?: string;
};

type SnapResponse = {
  token?: string;
  redirect_url?: string;
  status_message?: string;
};

export type MidtransPaymentResult = {
  transactionId: string | null;
  paymentUrl: string;
  raw: unknown;
};

function runtime() {
  return getRuntimeEnv<MidtransRuntime>();
}

export function getMidtransMode() {
  return requireRuntimeChoice(runtime().MIDTRANS_MODE, "MIDTRANS_MODE", [
    "snap",
    "bisnap",
  ] as const);
}

export function getMidtransEnvironment() {
  return requireRuntimeChoice(runtime().MIDTRANS_ENV, "MIDTRANS_ENV", [
    "sandbox",
    "production",
  ] as const);
}

function snapConfig(environment = getMidtransEnvironment()) {
  const config = runtime();

  if (environment === "sandbox") {
    return {
      environment,
      serverKey: requireRuntimeValue(
        config.MIDTRANS_SNAP_SANDBOX_SERVER_KEY,
        "MIDTRANS_SNAP_SANDBOX_SERVER_KEY",
      ),
      endpoint: requireRuntimeValue(
        config.MIDTRANS_SNAP_SANDBOX_API_URL,
        "MIDTRANS_SNAP_SANDBOX_API_URL",
      ),
    };
  }

  return {
    environment,
    serverKey: requireRuntimeValue(
      config.MIDTRANS_SNAP_PRODUCTION_SERVER_KEY,
      "MIDTRANS_SNAP_PRODUCTION_SERVER_KEY",
    ),
    endpoint: requireRuntimeValue(
      config.MIDTRANS_SNAP_PRODUCTION_API_URL,
      "MIDTRANS_SNAP_PRODUCTION_API_URL",
    ),
  };
}

function enabledSnapPayments(method: string, channel: string) {
  if (method === "qris") return ["other_qris"];

  if (method === "ewallet") {
    const map: Record<string, string> = {
      gopay: "gopay",
      shopeepay: "shopeepay",
      dana: "dana",
      ovo: "ovo",
    };
    const payment = map[channel];
    if (!payment)
      throw new Error(
        `Channel e-wallet ${channel} belum didukung Midtrans Snap.`,
      );
    return [payment];
  }

  if (method === "va") {
    const map: Record<string, string> = {
      bca: "bca_va",
      bni: "bni_va",
      bri: "bri_va",
      permata: "permata_va",
      mandiri: "echannel",
      bsi: "bsi_va",
      cimb: "cimb_va",
      danamon: "danamon_va",
    };
    const payment = map[channel];
    if (!payment)
      throw new Error(
        `Channel VA ${channel} belum didukung Midtrans Snap.`,
      );
    return [payment];
  }

  throw new Error("Metode pembayaran belum didukung Midtrans Snap.");
}

export function isMidtransSnapChannelSupported(
  method: string,
  channel: string,
) {
  try {
    enabledSnapPayments(method, channel);
    return true;
  } catch {
    return false;
  }
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
  const { serverKey, endpoint } = snapConfig();
  const payments = enabledSnapPayments(
    input.paymentMethod,
    input.paymentChannel,
  );

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
    enabled_payments: payments,
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
      payload.status_message ||
        "Midtrans menolak pembuatan pembayaran Snap.",
    );

  return {
    transactionId: null,
    paymentUrl: payload.redirect_url,
    raw: payload,
  } satisfies MidtransPaymentResult;
}

export function validateMidtransNotification(
  input: Record<string, unknown>,
) {
  const config = runtime();
  const orderId = String(input.order_id ?? "");
  const statusCode = String(input.status_code ?? "");
  const grossAmount = String(input.gross_amount ?? "");
  const signature = String(input.signature_key ?? "");

  const candidates = [
    [
      "sandbox",
      config.MIDTRANS_SNAP_SANDBOX_SERVER_KEY?.trim(),
    ],
    [
      "production",
      config.MIDTRANS_SNAP_PRODUCTION_SERVER_KEY?.trim(),
    ],
  ] as const;

  for (const [environment, serverKey] of candidates) {
    if (!serverKey) continue;
    const expected = hashHex(
      "sha512",
      `${orderId}${statusCode}${grossAmount}${serverKey}`,
    );
    if (
      Boolean(orderId && statusCode && grossAmount) &&
      safeEqual(signature, expected)
    ) {
      return { valid: true, environment, orderId, grossAmount };
    }
  }

  return {
    valid: false,
    environment: null,
    orderId,
    grossAmount,
  };
}

export function mapMidtransStatus(input: Record<string, unknown>) {
  const status = String(input.transaction_status ?? "").toLowerCase();
  const fraud = String(input.fraud_status ?? "accept").toLowerCase();

  if (
    status === "settlement" ||
    (status === "capture" && fraud === "accept")
  )
    return "paid" as const;
  if (status === "expire") return "expired" as const;
  if (["deny", "cancel", "failure"].includes(status))
    return "failed" as const;
  return "pending" as const;
}
