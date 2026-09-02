import { createCipheriv, createDecipheriv, createHash, randomBytes } from "node:crypto";
import { getD1 } from "@/db";
import { hmacHex } from "@/lib/server/crypto";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import type { ProviderOrder, ProviderResult } from "@/lib/server/providers/types";

type VoucherRuntimeEnv = {
  VOUCHER_ENCRYPTION_KEY?: string;
  VOUCHER_DELIVERY_CHANNEL?: string;
  RESEND_API_KEY?: string;
  RESEND_FROM_EMAIL?: string;
  WHATSAPP_ACCESS_TOKEN?: string;
  WHATSAPP_PHONE_NUMBER_ID?: string;
  WHATSAPP_VOUCHER_TEMPLATE?: string;
  WHATSAPP_TEMPLATE_LANGUAGE?: string;
  WHATSAPP_GRAPH_VERSION?: string;
};

type VoucherCodeRow = {
  id: number;
  stock_key: string;
  code_ciphertext: string;
  code_iv: string;
  code_tag: string;
  code_hash: string;
  status: "available" | "reserved" | "delivered" | "void";
  order_id: string | null;
  reserved_at: string | null;
  delivered_at: string | null;
  created_at: string;
};

type DeliveryChannel = "email" | "whatsapp";

type DeliveryOutcome = {
  channel: DeliveryChannel;
  status: "sent" | "failed";
  providerId: string | null;
  message: string;
};

type StockCountRow = {
  stock_key: string;
  available: number;
  reserved: number;
  delivered: number;
  voided: number;
  total: number;
};

type StockPackageRow = {
  stock_key: string;
  product_name: string;
  package_label: string;
};

type DeliveryDashboardRow = {
  code_id: number;
  stock_key: string;
  code_status: string;
  order_id: string;
  reference_id: string;
  product_name: string;
  package_label: string;
  buyer_name: string;
  buyer_email: string;
  buyer_phone: string;
  email_status: string | null;
  whatsapp_status: string | null;
  reserved_at: string | null;
  delivered_at: string | null;
};

const stockKeyPattern = /^[a-z0-9][a-z0-9._:-]{1,99}$/;

function runtime() {
  return getRuntimeEnv<VoucherRuntimeEnv>();
}

function encryptionSecret() {
  const secret = runtime().VOUCHER_ENCRYPTION_KEY?.trim();
  if (!secret || secret.length < 32) {
    throw new Error("VOUCHER_ENCRYPTION_KEY minimal 32 karakter belum dikonfigurasi.");
  }
  return secret;
}

function encryptionKey(secret: string) {
  return createHash("sha256").update(secret, "utf8").digest();
}

function encryptCode(code: string, secret: string) {
  const iv = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", encryptionKey(secret), iv);
  const ciphertext = Buffer.concat([cipher.update(code, "utf8"), cipher.final()]);
  return {
    ciphertext: ciphertext.toString("base64"),
    iv: iv.toString("base64"),
    tag: cipher.getAuthTag().toString("base64"),
    hash: hmacHex("sha256", secret, code),
  };
}

function decryptCode(row: VoucherCodeRow, secret: string) {
  const decipher = createDecipheriv("aes-256-gcm", encryptionKey(secret), Buffer.from(row.code_iv, "base64"));
  decipher.setAuthTag(Buffer.from(row.code_tag, "base64"));
  return Buffer.concat([
    decipher.update(Buffer.from(row.code_ciphertext, "base64")),
    decipher.final(),
  ]).toString("utf8");
}

function normalizeStockKey(value: string) {
  const key = value.trim().toLowerCase();
  if (!stockKeyPattern.test(key)) throw new Error("Kunci stok hanya boleh berisi huruf kecil, angka, titik, garis, titik dua, atau underscore.");
  return key;
}

function cleanCodes(values: string[]) {
  const unique = new Set<string>();
  for (const value of values) {
    const code = value.trim();
    if (!code) continue;
    if (code.length < 3 || code.length > 500 || code.includes("\0")) throw new Error("Setiap kode harus berisi 3–500 karakter yang valid.");
    unique.add(code);
  }
  if (unique.size < 1) throw new Error("Masukkan setidaknya satu kode voucher.");
  if (unique.size > 1_000) throw new Error("Maksimal 1.000 kode untuk sekali impor.");
  return [...unique];
}

export async function importVoucherCodes(stockKeyInput: string, values: string[]) {
  const stockKey = normalizeStockKey(stockKeyInput);
  const codes = cleanCodes(values);
  const secret = encryptionSecret();
  const db = getD1();
  let imported = 0;

  for (let offset = 0; offset < codes.length; offset += 50) {
    const chunk = codes.slice(offset, offset + 50);
    const statements = chunk.map((code) => {
      const encrypted = encryptCode(code, secret);
      return db.prepare(
        `INSERT OR IGNORE INTO voucher_codes
         (stock_key, code_ciphertext, code_iv, code_tag, code_hash)
         VALUES (?, ?, ?, ?, ?)`,
      ).bind(stockKey, encrypted.ciphertext, encrypted.iv, encrypted.tag, encrypted.hash);
    });
    const results = await db.batch(statements);
    imported += results.reduce((total, result) => total + Number(result.meta.changes ?? 0), 0);
  }

  return { stockKey, received: codes.length, imported, duplicates: codes.length - imported };
}

export async function listVoucherDashboard() {
  const db = getD1();
  const [countResult, packageResult, deliveryResult] = await Promise.all([
    db.prepare(
      `SELECT stock_key,
       SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available,
       SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved,
       SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
       SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS voided,
       COUNT(*) AS total
       FROM voucher_codes GROUP BY stock_key ORDER BY stock_key`,
    ).all<StockCountRow>(),
    db.prepare(
      `SELECT pp.provider_sku AS stock_key, p.name AS product_name, pp.label AS package_label
       FROM product_packages pp JOIN products p ON p.id = pp.product_id
       WHERE pp.provider_code = 'voucher-stock' AND pp.provider_sku IS NOT NULL
       ORDER BY p.name, pp.sort_order`,
    ).all<StockPackageRow>(),
    db.prepare(
      `SELECT vc.id AS code_id, vc.stock_key, vc.status AS code_status, vc.order_id,
       o.reference_id, o.product_name, o.package_label, o.buyer_name, o.buyer_email, o.buyer_phone,
       MAX(CASE WHEN vd.channel = 'email' THEN vd.status END) AS email_status,
       MAX(CASE WHEN vd.channel = 'whatsapp' THEN vd.status END) AS whatsapp_status,
       vc.reserved_at, vc.delivered_at
       FROM voucher_codes vc
       JOIN orders o ON o.id = vc.order_id
       LEFT JOIN voucher_deliveries vd ON vd.voucher_code_id = vc.id
       WHERE vc.order_id IS NOT NULL
       GROUP BY vc.id, vc.stock_key, vc.status, vc.order_id, o.reference_id, o.product_name,
        o.package_label, o.buyer_name, o.buyer_email, o.buyer_phone, vc.reserved_at, vc.delivered_at
       ORDER BY COALESCE(vc.delivered_at, vc.reserved_at) DESC LIMIT 100`,
    ).all<DeliveryDashboardRow>(),
  ]);

  const stockMap = new Map<string, StockCountRow & { labels: string[] }>();
  for (const row of countResult.results) stockMap.set(row.stock_key, { ...row, labels: [] });
  for (const row of packageResult.results) {
    const current: StockCountRow & { labels: string[] } = stockMap.get(row.stock_key) ?? { stock_key: row.stock_key, available: 0, reserved: 0, delivered: 0, voided: 0, total: 0, labels: [] };
    current.labels.push(`${row.product_name} — ${row.package_label}`);
    stockMap.set(row.stock_key, current);
  }

  const config = runtime();
  return {
    stocks: [...stockMap.values()].sort((a, b) => a.stock_key.localeCompare(b.stock_key)),
    deliveries: deliveryResult.results,
    config: {
      encryptionReady: Boolean(config.VOUCHER_ENCRYPTION_KEY && config.VOUCHER_ENCRYPTION_KEY.trim().length >= 32),
      emailReady: Boolean(config.RESEND_API_KEY && config.RESEND_FROM_EMAIL),
      whatsappReady: Boolean(config.WHATSAPP_ACCESS_TOKEN && config.WHATSAPP_PHONE_NUMBER_ID && config.WHATSAPP_VOUCHER_TEMPLATE),
      deliveryChannel: parseDeliveryChannels(config.VOUCHER_DELIVERY_CHANNEL).join("+") || "website",
    },
  };
}

export async function revealVoucherCode(orderId: string) {
  const row = await getD1().prepare(
    `SELECT vc.* FROM voucher_codes vc WHERE vc.order_id = ? LIMIT 1`,
  ).bind(orderId).first<VoucherCodeRow>();
  if (!row) throw new Error("Kode untuk pesanan ini belum direservasi.");
  return { code: decryptCode(row, encryptionSecret()), stockKey: row.stock_key, codeId: row.id };
}

export async function hasAvailableVoucherStock(stockKeyInput: string) {
  const stockKey = normalizeStockKey(stockKeyInput);
  const row = await getD1().prepare(
    "SELECT 1 AS available FROM voucher_codes WHERE stock_key = ? AND status = 'available' LIMIT 1",
  ).bind(stockKey).first<{ available: number }>();
  return Boolean(row?.available);
}

async function reserveCode(orderId: string, stockKeyInput: string) {
  const db = getD1();
  const stockKey = normalizeStockKey(stockKeyInput);
  const existing = await db.prepare("SELECT * FROM voucher_codes WHERE order_id = ? LIMIT 1").bind(orderId).first<VoucherCodeRow>();
  if (existing) return existing;

  try {
    const result = await db.prepare(
      `UPDATE voucher_codes SET status = 'reserved', order_id = ?, reserved_at = CURRENT_TIMESTAMP
       WHERE id = (
        SELECT id FROM voucher_codes WHERE stock_key = ? AND status = 'available' ORDER BY id LIMIT 1
       ) AND status = 'available'
       RETURNING *`,
    ).bind(orderId, stockKey).all<VoucherCodeRow>();
    const reserved = result.results[0];
    if (reserved) return reserved;
  } catch {
    const wonByAnotherRequest = await db.prepare("SELECT * FROM voucher_codes WHERE order_id = ? LIMIT 1").bind(orderId).first<VoucherCodeRow>();
    if (wonByAnotherRequest) return wonByAnotherRequest;
    throw new Error("Reservasi stok kode gagal dan perlu diperiksa admin.");
  }
  throw new Error(`Stok kode ${stockKey} habis.`);
}

function parseDeliveryChannels(value?: string): DeliveryChannel[] {
  const normalized = value?.trim().toLowerCase();
  if (normalized === "email") return ["email"];
  if (normalized === "whatsapp") return ["whatsapp"];
  if (normalized === "both" || normalized === "email+whatsapp" || normalized === "whatsapp+email") return ["email", "whatsapp"];
  return [];
}

async function existingDeliveryStatus(orderId: string, channel: DeliveryChannel) {
  return getD1().prepare(
    "SELECT status FROM voucher_deliveries WHERE order_id = ? AND channel = ? LIMIT 1",
  ).bind(orderId, channel).first<{ status: string }>();
}

async function deliverChannel(order: ProviderOrder, voucher: VoucherCodeRow, code: string, channel: DeliveryChannel): Promise<DeliveryOutcome> {
  const previous = await existingDeliveryStatus(order.id, channel);
  if (previous?.status === "sent") return { channel, status: "sent", providerId: null, message: "Sudah dikirim sebelumnya." };

  const db = getD1();
  await db.prepare(
    `INSERT INTO voucher_deliveries
     (order_id, voucher_code_id, channel, status, attempts, last_attempt_at)
     VALUES (?, ?, ?, 'pending', 1, CURRENT_TIMESTAMP)
     ON CONFLICT(order_id, channel) DO UPDATE SET
      status = 'pending', attempts = voucher_deliveries.attempts + 1,
      provider_message = NULL, last_attempt_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP`,
  ).bind(order.id, voucher.id, channel).run();

  let outcome: DeliveryOutcome;
  try {
    outcome = channel === "email" ? await sendEmail(order, code) : await sendWhatsApp(order, code);
  } catch (error) {
    outcome = {
      channel,
      status: "failed",
      providerId: null,
      message: (error instanceof Error ? error.message : "Pengiriman gagal.").slice(0, 500),
    };
  }

  await db.prepare(
    `UPDATE voucher_deliveries SET status = ?, provider_id = ?, provider_message = ?,
     updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND channel = ?`,
  ).bind(outcome.status, outcome.providerId, outcome.message, order.id, channel).run();
  return outcome;
}

async function sendEmail(order: ProviderOrder, code: string): Promise<DeliveryOutcome> {
  const config = runtime();
  const apiKey = config.RESEND_API_KEY?.trim();
  const from = config.RESEND_FROM_EMAIL?.trim();
  if (!apiKey || !from) throw new Error("Resend belum dikonfigurasi.");

  const response = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: {
      authorization: `Bearer ${apiKey}`,
      "content-type": "application/json",
      "idempotency-key": `lfamilia-voucher-${order.id}-email`,
    },
    body: JSON.stringify({
      from,
      to: [order.buyerEmail],
      subject: `Kode ${order.productName} — ${order.referenceId}`,
      html: `<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515"><h1>LFAMILIA STORE</h1><p>Halo ${escapeHtml(order.buyerName)}, pembayaran pesanan <strong>${escapeHtml(order.referenceId)}</strong> sudah berhasil.</p><p>${escapeHtml(order.productName)} — ${escapeHtml(order.packageLabel)}</p><div style="padding:18px;border-radius:12px;background:#f1f7df;font-size:20px;font-weight:700;word-break:break-all">${escapeHtml(code)}</div><p>Simpan kode ini dan jangan bagikan kepada orang lain.</p></div>`,
    }),
    signal: AbortSignal.timeout(12_000),
  });
  const payload = await response.json().catch(() => ({})) as { id?: string; message?: string };
  if (!response.ok) throw new Error(payload.message || "Resend menolak pengiriman email.");
  return { channel: "email", status: "sent", providerId: payload.id || null, message: "Kode berhasil dikirim lewat email." };
}

async function sendWhatsApp(order: ProviderOrder, code: string): Promise<DeliveryOutcome> {
  const config = runtime();
  const token = config.WHATSAPP_ACCESS_TOKEN?.trim();
  const phoneId = config.WHATSAPP_PHONE_NUMBER_ID?.trim();
  const template = config.WHATSAPP_VOUCHER_TEMPLATE?.trim();
  if (!token || !phoneId || !template) throw new Error("WhatsApp Cloud API belum dikonfigurasi.");

  const version = config.WHATSAPP_GRAPH_VERSION?.trim() || "v23.0";
  const response = await fetch(`https://graph.facebook.com/${version}/${encodeURIComponent(phoneId)}/messages`, {
    method: "POST",
    headers: { authorization: `Bearer ${token}`, "content-type": "application/json" },
    body: JSON.stringify({
      messaging_product: "whatsapp",
      to: normalizeWhatsApp(order.buyerPhone),
      type: "template",
      template: {
        name: template,
        language: { code: config.WHATSAPP_TEMPLATE_LANGUAGE?.trim() || "id" },
        components: [{
          type: "body",
          parameters: [order.buyerName, order.productName, order.packageLabel, code, order.referenceId]
            .map((text) => ({ type: "text", text })),
        }],
      },
    }),
    signal: AbortSignal.timeout(12_000),
  });
  const payload = await response.json().catch(() => ({})) as { messages?: Array<{ id?: string }>; error?: { message?: string } };
  if (!response.ok) throw new Error(payload.error?.message || "WhatsApp menolak pengiriman pesan.");
  return { channel: "whatsapp", status: "sent", providerId: payload.messages?.[0]?.id || null, message: "Kode berhasil dikirim lewat WhatsApp." };
}

function normalizeWhatsApp(value: string) {
  const digits = value.replace(/\D/g, "");
  if (digits.startsWith("0")) return `62${digits.slice(1)}`;
  if (digits.startsWith("8")) return `62${digits}`;
  return digits;
}

function escapeHtml(value: string) {
  return value.replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[character] || character);
}

export async function fulfillVoucherStockOrder(order: ProviderOrder): Promise<ProviderResult> {
  const voucher = await reserveCode(order.id, order.providerSku);
  const code = decryptCode(voucher, encryptionSecret());

  await getD1().prepare(
    `UPDATE voucher_codes SET status = 'delivered', delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP)
     WHERE id = ? AND order_id = ?`,
  ).bind(voucher.id, order.id).run();

  const channels = parseDeliveryChannels(runtime().VOUCHER_DELIVERY_CHANNEL);
  const outcomes = channels.length
    ? await Promise.all(channels.map((channel) => deliverChannel(order, voucher, code, channel)))
    : [];
  const sent = outcomes.filter((outcome) => outcome.status === "sent");
  const extraMessage = sent.length
    ? ` Notifikasi tambahan terkirim via ${sent.map((outcome) => outcome.channel).join(" + ")}.`
    : "";

  return {
    externalId: `stock-${voucher.id}`,
    status: "success",
    message: `Kode tersedia di website.${extraMessage}`,
    serialNumber: `STOCK-${voucher.id}`,
    raw: { voucherCodeId: voucher.id, stockKey: voucher.stock_key, websiteDelivery: true, deliveries: outcomes },
  };
}

export async function listCustomerVoucherCodes(customerId: string) {
  const rows = await getD1().prepare(
    `SELECT vc.id, vc.stock_key, vc.code_ciphertext, vc.code_iv, vc.code_tag, vc.code_hash, vc.status, vc.order_id, vc.reserved_at, vc.delivered_at, vc.created_at,
            o.reference_id, o.product_name, o.package_label
     FROM voucher_codes vc JOIN orders o ON o.id = vc.order_id
     WHERE o.customer_id = ? AND vc.status = 'delivered' AND o.payment_status = 'paid'
     ORDER BY vc.delivered_at DESC LIMIT 50`,
  ).bind(customerId).all<VoucherCodeRow & { reference_id: string; product_name: string; package_label: string }>();
  const secret = encryptionSecret();
  return rows.results.map((row) => ({ id: row.id, referenceId: row.reference_id, productName: row.product_name, packageLabel: row.package_label, code: decryptCode(row, secret), deliveredAt: row.delivered_at }));
}
