import { getD1 } from "@/db";

export type PaymentPageSettings = {
  accentColor: string;
  headerImageUrl: string;
  eyebrow: string;
  pendingTitle: string;
  paidTitle: string;
  failedTitle: string;
  subtitle: string;
  invoiceNoticeTitle: string;
  invoiceNoticeText: string;
  pendingStatusText: string;
  paidStatusText: string;
  failedStatusText: string;
  payButtonText: string;
  checkStatusButtonText: string;
  checkInvoiceButtonText: string;
  supportText: string;
  supportUrl: string;
  showStoreBrand: boolean;
  showInvoiceNotice: boolean;
  showOrderSummary: boolean;
  showStatusBox: boolean;
  showSupport: boolean;
};

export const defaultPaymentPageSettings: PaymentPageSettings = {
  accentColor: "#b9ff35",
  headerImageUrl: "",
  eyebrow: "LFAMILIA PAYMENT",
  pendingTitle: "Selesaikan pembayaran",
  paidTitle: "Pembayaran berhasil",
  failedTitle: "Pembayaran tidak aktif",
  subtitle: "Pembayaran diproses aman oleh LFAMILIA STORE melalui DOKU.",
  invoiceNoticeTitle: "Simpan invoice sebelum membayar",
  invoiceNoticeText: "Invoice diperlukan untuk mengecek transaksi jika halaman pembayaran tertutup atau terjadi kendala.",
  pendingStatusText: "Status diperiksa otomatis setiap 3 detik.",
  paidStatusText: "Pembayaran sudah diterima. Status pesanan akan diperbarui otomatis.",
  failedStatusText: "Transaksi ini tidak dapat dilanjutkan. Buat checkout baru bila diperlukan.",
  payButtonText: "Bayar Sekarang",
  checkStatusButtonText: "Cek status",
  checkInvoiceButtonText: "Cek invoice",
  supportText: "Butuh bantuan pembayaran?",
  supportUrl: "/contact",
  showStoreBrand: true,
  showInvoiceNotice: true,
  showOrderSummary: true,
  showStatusBox: true,
  showSupport: true,
};

let schemaReady: Promise<void> | null = null;

async function ensurePaymentPageSettingsTable() {
  if (!schemaReady) {
    schemaReady = (async () => {
      const db = getD1();
      await db.prepare(`CREATE TABLE IF NOT EXISTS payment_page_settings (
        id INTEGER PRIMARY KEY NOT NULL CHECK (id = 1),
        config_json TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )`).run();
      await db.prepare(`INSERT OR IGNORE INTO payment_page_settings (id, config_json)
        VALUES (1, ?)`).bind(JSON.stringify(defaultPaymentPageSettings)).run();
    })().catch((error) => {
      schemaReady = null;
      throw error;
    });
  }
  await schemaReady;
}

function normalize(value: unknown): PaymentPageSettings {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    return { ...defaultPaymentPageSettings };
  }
  const raw = value as Record<string, unknown>;
  const result = { ...defaultPaymentPageSettings } as Record<string, unknown>;
  for (const [key, fallback] of Object.entries(defaultPaymentPageSettings)) {
    const next = raw[key];
    if (typeof fallback === "boolean") {
      if (typeof next === "boolean") result[key] = next;
    } else if (typeof next === "string") {
      result[key] = next;
    }
  }
  return result as PaymentPageSettings;
}

export async function readPaymentPageSettings(): Promise<PaymentPageSettings> {
  await ensurePaymentPageSettingsTable();
  const row = await getD1().prepare(
    "SELECT config_json FROM payment_page_settings WHERE id = 1 LIMIT 1",
  ).first<{ config_json: string }>();
  if (!row?.config_json) return { ...defaultPaymentPageSettings };
  try {
    return normalize(JSON.parse(row.config_json));
  } catch {
    return { ...defaultPaymentPageSettings };
  }
}

export async function savePaymentPageSettings(settings: PaymentPageSettings) {
  await ensurePaymentPageSettingsTable();
  await getD1().prepare(`INSERT INTO payment_page_settings (id, config_json, updated_at)
    VALUES (1, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(id) DO UPDATE SET
      config_json = excluded.config_json,
      updated_at = CURRENT_TIMESTAMP`)
    .bind(JSON.stringify(normalize(settings)))
    .run();
}
