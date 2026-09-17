import { hmacBase64 } from "@/lib/server/crypto";
import {
  getHostedGatewayProfileForEnvironment,
  type PaymentEnvironment,
} from "@/lib/server/payment-mode-config";

function object(value: unknown) {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim() : "";
}

function mapCheckoutStatus(payload: Record<string, unknown>) {
  const transaction = object(payload.transaction);
  const order = object(payload.order);
  const transactionStatus = text(transaction.status).toUpperCase();
  const orderStatus = text(order.status).toUpperCase();

  if (transactionStatus === "SUCCESS") return "paid" as const;
  if (transactionStatus === "EXPIRED" || orderStatus === "ORDER_EXPIRED") {
    return "expired" as const;
  }

  // DOKU Checkout allows the customer to retry/change payment method after a
  // failed attempt, so FAILED/TIMEOUT/REDIRECT are not final on our side.
  return "pending" as const;
}

export async function queryDokuCheckoutStatus(input: {
  referenceId: string;
  environment: PaymentEnvironment;
}) {
  const values = await getHostedGatewayProfileForEnvironment(
    "doku",
    "checkout",
    input.environment,
  );
  const clientId = values?.clientId?.trim() || "";
  const secretKey = values?.secretKey?.trim() || "";
  if (!clientId || !secretKey) {
    throw new Error(`Kredensial DOKU Checkout ${input.environment} belum lengkap.`);
  }

  const apiOrigin = input.environment === "production"
    ? "https://api.doku.com"
    : "https://api-sandbox.doku.com";
  const requestTarget = `/orders/v1/status/${encodeURIComponent(input.referenceId)}`;
  const requestId = crypto.randomUUID();
  const requestTimestamp = new Date().toISOString();
  const component = [
    `Client-Id:${clientId}`,
    `Request-Id:${requestId}`,
    `Request-Timestamp:${requestTimestamp}`,
    `Request-Target:${requestTarget}`,
  ].join("\n");
  const signature = `HMACSHA256=${hmacBase64("sha256", secretKey, component)}`;

  const response = await fetch(`${apiOrigin}${requestTarget}`, {
    method: "GET",
    headers: {
      accept: "application/json",
      "client-id": clientId,
      "request-id": requestId,
      "request-timestamp": requestTimestamp,
      signature,
    },
    signal: AbortSignal.timeout(15_000),
  });
  const raw = await response.json().catch(() => ({})) as Record<string, unknown>;
  if (!response.ok) {
    const message = text(raw.message) || text(raw.responseMessage) ||
      "DOKU Checkout belum dapat mengembalikan status transaksi.";
    throw new Error(message);
  }

  const order = object(raw.order);
  const invoice = text(order.invoice_number);
  if (invoice && invoice !== input.referenceId) {
    throw new Error("Invoice dari DOKU Checkout tidak sesuai.");
  }

  const amount = Number(order.amount ?? Number.NaN);
  return {
    requestId,
    status: mapCheckoutStatus(raw),
    amount,
    raw,
  };
}
