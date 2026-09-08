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
