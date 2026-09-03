import { getD1 } from "@/db";
import { getWebsiteVoucherCodeByReference } from "@/lib/server/customer-voucher-codes";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

type NotificationRuntimeEnv = {
  RESEND_API_KEY?: string;
  RESEND_FROM_EMAIL?: string;
  RESEND_API_URL?: string;
  WHATSAPP_ACCESS_TOKEN?: string;
  WHATSAPP_PHONE_NUMBER_ID?: string;
  WHATSAPP_TRANSACTION_TEMPLATE?: string;
  WHATSAPP_TEMPLATE_LANGUAGE?: string;
  WHATSAPP_GRAPH_VERSION?: string;
  WHATSAPP_GRAPH_BASE_URL?: string;
};

type NotificationInput = {
  kind: "order" | "wallet_topup";
  name: string;
  email: string;
  phone: string;
  detail: string;
  amount: number;
  referenceId: string;
};

function rupiah(value: number) {
  return `Rp${Math.max(0, Math.round(value)).toLocaleString("id-ID")}`;
}

function normalizeWhatsApp(value: string) {
  const digits = value.replace(/\D/g, "");
  if (digits.startsWith("0")) return `62${digits.slice(1)}`;
  if (digits.startsWith("8")) return `62${digits}`;
  return digits;
}

function escapeHtml(value: string) {
  return value.replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  })[character] || character);
}

function emailReady(input: NotificationInput, config: NotificationRuntimeEnv) {
  return Boolean(
    input.email.trim() &&
      config.RESEND_API_KEY?.trim() &&
      config.RESEND_FROM_EMAIL?.trim() &&
      config.RESEND_API_URL?.trim(),
  );
}

function whatsappReady(input: NotificationInput, config: NotificationRuntimeEnv) {
  return Boolean(
    normalizeWhatsApp(input.phone) &&
      config.WHATSAPP_ACCESS_TOKEN?.trim() &&
      config.WHATSAPP_PHONE_NUMBER_ID?.trim() &&
      config.WHATSAPP_TRANSACTION_TEMPLATE?.trim() &&
      config.WHATSAPP_TEMPLATE_LANGUAGE?.trim() &&
      config.WHATSAPP_GRAPH_VERSION?.trim() &&
      config.WHATSAPP_GRAPH_BASE_URL?.trim(),
  );
}

async function sendEmail(input: NotificationInput, config: NotificationRuntimeEnv) {
  const apiKey = config.RESEND_API_KEY!.trim();
  const from = config.RESEND_FROM_EMAIL!.trim();
  const apiUrl = config.RESEND_API_URL!.trim();
  const typeLabel = input.kind === "wallet_topup" ? "Top up saldo berhasil" : "Pesanan selesai";
  const response = await fetch(apiUrl, {
    method: "POST",
    headers: {
      authorization: `Bearer ${apiKey}`,
      "content-type": "application/json",
      "idempotency-key": `lfamilia-success-${input.kind}-${input.referenceId}`,
    },
    body: JSON.stringify({
      from,
      to: [input.email.trim()],
      subject: `${typeLabel} — ${input.referenceId}`,
      html: `<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515"><h1>LFAMILIA STORE</h1><p>Halo ${escapeHtml(input.name)},</p><p><strong>${escapeHtml(typeLabel)}.</strong></p><p>${escapeHtml(input.detail)}</p><p>Total: <strong>${escapeHtml(rupiah(input.amount))}</strong></p><p>Referensi: <strong>${escapeHtml(input.referenceId)}</strong></p><p>Terima kasih telah menggunakan LFAMILIA STORE.</p></div>`,
    }),
    signal: AbortSignal.timeout(12_000),
  });
  const payload = await response.json().catch(() => ({})) as { id?: string; message?: string };
  if (!response.ok) throw new Error(payload.message || "Resend menolak notifikasi transaksi.");
}

async function sendWhatsApp(input: NotificationInput, config: NotificationRuntimeEnv) {
  const token = config.WHATSAPP_ACCESS_TOKEN!.trim();
  const phoneId = config.WHATSAPP_PHONE_NUMBER_ID!.trim();
  const template = config.WHATSAPP_TRANSACTION_TEMPLATE!.trim();
  const language = config.WHATSAPP_TEMPLATE_LANGUAGE!.trim();
  const version = config.WHATSAPP_GRAPH_VERSION!.trim();
  const graphBaseUrl = config.WHATSAPP_GRAPH_BASE_URL!.trim().replace(/\/$/, "");
  const typeLabel = input.kind === "wallet_topup" ? "Top up saldo berhasil" : "Pesanan selesai";
  const response = await fetch(`${graphBaseUrl}/${version}/${encodeURIComponent(phoneId)}/messages`, {
    method: "POST",
    headers: { authorization: `Bearer ${token}`, "content-type": "application/json" },
    body: JSON.stringify({
      messaging_product: "whatsapp",
      to: normalizeWhatsApp(input.phone),
      type: "template",
      template: {
        name: template,
        language: { code: language },
        components: [{
          type: "body",
          parameters: [
            input.name,
            typeLabel,
            input.detail,
            rupiah(input.amount),
            input.referenceId,
          ].map((text) => ({ type: "text", text })),
        }],
      },
    }),
    signal: AbortSignal.timeout(12_000),
  });
  const payload = await response.json().catch(() => ({})) as {
    messages?: Array<{ id?: string }>;
    error?: { message?: string };
  };
  if (!response.ok) throw new Error(payload.error?.message || "WhatsApp menolak notifikasi transaksi.");
}

async function notify(input: NotificationInput) {
  const config = getRuntimeEnv<NotificationRuntimeEnv>();
  const tasks: Promise<void>[] = [];
  if (emailReady(input, config)) tasks.push(sendEmail(input, config));
  if (whatsappReady(input, config)) tasks.push(sendWhatsApp(input, config));
  if (!tasks.length) return;
  const results = await Promise.allSettled(tasks);
  for (const result of results) {
    if (result.status === "rejected") {
      console.error("Notifikasi transaksi LFAMILIA gagal:", result.reason);
    }
  }
}

async function claimOrderChannel(orderId: string, referenceId: string, channel: "email" | "whatsapp") {
  const result = await getD1()
    .prepare(
      `INSERT OR IGNORE INTO order_events (order_id, source, event_id, status, payload_json)
       VALUES (?, 'admin', ?, 'notified', ?)`,
    )
    .bind(
      orderId,
      `customer-${channel}-order-success-${referenceId}`,
      JSON.stringify({ channel, type: "order_success" }),
    )
    .run();
  return Number(result.meta.changes ?? 0) > 0;
}

async function releaseOrderChannel(referenceId: string, channel: "email" | "whatsapp") {
  await getD1()
    .prepare("DELETE FROM order_events WHERE source = 'admin' AND event_id = ?")
    .bind(`customer-${channel}-order-success-${referenceId}`)
    .run();
}

export async function notifyOrderFulfillmentSuccessById(orderId: string) {
  const order = await getD1()
    .prepare(
      `SELECT id, buyer_name, buyer_email, buyer_phone, product_name, package_label,
       total, reference_id, fulfillment_status FROM orders WHERE id = ? LIMIT 1`,
    )
    .bind(orderId)
    .first<{
      id: string;
      buyer_name: string;
      buyer_email: string;
      buyer_phone: string;
      product_name: string;
      package_label: string;
      total: number;
      reference_id: string;
      fulfillment_status: string;
    }>();
  if (!order || order.fulfillment_status !== "success") return;

  const voucherCode = await getWebsiteVoucherCodeByReference(order.reference_id).catch(() => null);
  const detail = voucherCode
    ? `${order.product_name} — ${order.package_label}. Produk berhasil dikirim. Kode voucher: ${voucherCode}`
    : `${order.product_name} — ${order.package_label}. Produk berhasil dikirim.`;

  const input: NotificationInput = {
    kind: "order",
    name: order.buyer_name,
    email: order.buyer_email,
    phone: order.buyer_phone,
    detail,
    amount: order.total,
    referenceId: order.reference_id,
  };
  const config = getRuntimeEnv<NotificationRuntimeEnv>();

  if (emailReady(input, config) && await claimOrderChannel(order.id, order.reference_id, "email")) {
    try {
      await sendEmail(input, config);
    } catch (error) {
      await releaseOrderChannel(order.reference_id, "email");
      console.error("Email pesanan selesai gagal:", error);
    }
  }

  if (whatsappReady(input, config) && await claimOrderChannel(order.id, order.reference_id, "whatsapp")) {
    try {
      await sendWhatsApp(input, config);
    } catch (error) {
      await releaseOrderChannel(order.reference_id, "whatsapp");
      console.error("WhatsApp pesanan selesai gagal:", error);
    }
  }
}

export async function notifyOrderFulfillmentSuccessByProviderRef(
  providerCode: string,
  providerRefId: string,
) {
  const order = await getD1()
    .prepare(
      `SELECT id FROM orders
       WHERE provider_code = ? AND (provider_ref_id = ? OR reference_id = ?)
       AND fulfillment_status = 'success' LIMIT 1`,
    )
    .bind(providerCode, providerRefId, providerRefId)
    .first<{ id: string }>();
  if (order) await notifyOrderFulfillmentSuccessById(order.id);
}

export async function notifyWalletTopupSuccessById(
  topupId: string,
  referenceId?: string,
) {
  const row = await getD1()
    .prepare(
      `SELECT t.id, t.amount, t.reference_id, t.status,
      u.name, u.email, u.phone, u.balance
      FROM wallet_topups t JOIN customer_users u ON u.id = t.customer_id
      WHERE t.id = ? LIMIT 1`,
    )
    .bind(topupId)
    .first<{
      id: string;
      amount: number;
      reference_id: string | null;
      status: string;
      name: string;
      email: string;
      phone: string;
      balance: number;
    }>();
  if (!row || row.status !== "approved") return;
  await notify({
    kind: "wallet_topup",
    name: row.name,
    email: row.email,
    phone: row.phone,
    detail: `Saldo LFAMILIA sudah bertambah. Saldo sekarang ${rupiah(row.balance)}.`,
    amount: row.amount,
    referenceId: referenceId || row.reference_id || `TOPUP-${row.id.slice(0, 8).toUpperCase()}`,
  });
}
