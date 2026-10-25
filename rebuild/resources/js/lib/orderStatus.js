// Presentation only: do not mutate payment or order state.
export const manualHelpMessage = 'Sudah bayar tapi barang belum masuk? Hubungi admin, pesanan diselesaikan manual. Sertakan ID order dan bukti bayar.';
const publicStatuses = {
    PENDING_PAYMENT: ['Menunggu Pembayaran', 'Selesaikan pembayaran sesuai petunjuk invoice.', 'amber', false],
    PROCESSING: ['Sedang Diproses', 'Pesanan sedang diproses. Periksa akun game secara berkala.', 'blue', false],
    SUCCESS: ['Berhasil', 'Pesanan selesai. Periksa akun game untuk hasilnya.', 'green', true],
    FAILED: ['Gagal', 'Pesanan tidak berhasil diproses. Hubungi admin jika pembayaran sudah dilakukan.', 'red', true],
    EXPIRED: ['Kedaluwarsa', 'Waktu pembayaran telah berakhir.', 'gray', true],
    CANCELLED: ['Dibatalkan', 'Pesanan dibatalkan dan tidak akan diproses lagi.', 'red', true],
};
const internalToPublic = {
    PENDING: 'PENDING_PAYMENT', PENDING_PAYMENT: 'PENDING_PAYMENT', CREATING: 'PENDING_PAYMENT',
    PAID: 'PROCESSING', SETTLEMENT: 'PROCESSING', CAPTURE: 'PROCESSING', PROCESSING: 'PROCESSING',
    SUCCESS: 'SUCCESS', FAILED: 'FAILED', REJECTED: 'FAILED', DENIED: 'FAILED', DENY: 'FAILED',
    EXPIRED: 'EXPIRED', EXPIRE: 'EXPIRED', CANCELLED: 'CANCELLED', CANCEL: 'CANCELLED',
};
export function orderStatusInfo(status) {
    const key = internalToPublic[String(status || '').toUpperCase()] || 'FAILED';
    const [label, description, tone, terminal] = publicStatuses[key];
    return { key, label, description, tone, terminal };
}

// Only display copy is editable. Terminal flags and status mapping stay code-owned.
export function customerOrderStatusInfo(status, customerText) {
    const info = orderStatusInfo(status);
    if (typeof customerText !== 'function') return info;
    return {
        ...info,
        label: customerText('order.status.' + info.key + '.label', info.label),
        description: customerText('order.status.' + info.key + '.description', info.description),
    };
}
const paymentLabels = {
    PENDING: 'Menunggu pembayaran', PENDING_PAYMENT: 'Menunggu pembayaran',
    CREATING: 'Menyiapkan pembayaran', UNKNOWN: 'Status belum dapat dipastikan',
    PAID: 'Pembayaran diterima', SETTLEMENT: 'Pembayaran diterima',
    CAPTURE: 'Pembayaran diterima', SUCCESS: 'Pembayaran diterima',
    REJECTED: 'Pembayaran ditolak', DENIED: 'Pembayaran ditolak',
    DENY: 'Pembayaran ditolak', FAILED: 'Pembayaran gagal',
    EXPIRED: 'Pembayaran kedaluwarsa', EXPIRE: 'Pembayaran kedaluwarsa',
    CANCELLED: 'Pembayaran dibatalkan', CANCEL: 'Pembayaran dibatalkan',
    REFUND: 'Perlu pemeriksaan admin', REFUNDED: 'Perlu pemeriksaan admin',
};
export function paymentStatusLabel(status) {
    return paymentLabels[String(status || '').toUpperCase()] || 'Status belum dapat dipastikan';
}
export function isTerminalOrderStatus(status) {
    return orderStatusInfo(status).terminal;
}
export function nextOrderAction(orderStatus, paymentStatus = null, paidAt = null) {
    const order = String(orderStatus || '').toUpperCase();
    const payment = String(paymentStatus || '').toUpperCase();
    if (!internalToPublic[order] || ['REFUND', 'REFUNDED'].includes(order)) return 'support';
    if (!['FAILED', 'EXPIRED', 'CANCELLED'].includes(internalToPublic[order])) return null;
    if (paidAt || ['PAID', 'SETTLEMENT', 'CAPTURE', 'SUCCESS', 'REFUNDED', 'REFUND'].includes(payment)) return 'support';
    if (['PENDING', 'PENDING_PAYMENT', 'CREATING', 'UNKNOWN', 'PROCESSING'].includes(payment)) return null;
    if (!payment || ['FAILED', 'REJECTED', 'DENIED', 'DENY', 'EXPIRED', 'EXPIRE', 'CANCELLED', 'CANCEL'].includes(payment)) return 'new';
    return null;
}
