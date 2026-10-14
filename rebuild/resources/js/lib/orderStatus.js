// Presentation only: do not mutate payment or order state.
export const manualHelpMessage = 'Sudah bayar tapi barang belum masuk? Hubungi admin, pesanan diselesaikan manual. Sertakan ID order dan bukti bayar.';
const orderStatuses = {
    PENDING_PAYMENT: ['Menunggu pembayaran', 'Selesaikan pembayaran sesuai petunjuk invoice.'],
    PAID: ['Pembayaran diterima', 'Pembayaran terverifikasi dan pesanan masuk antrean.'],
    PROCESSING: ['Sedang diproses', 'Top up sedang diproses. Periksa akun game secara berkala.'],
    SUCCESS: ['Berhasil', 'Top up selesai. Periksa akun game untuk hasilnya.'],
    FAILED: ['Pesanan gagal', 'Pesanan tidak berhasil diproses. Periksa status pembayaran.'],
    EXPIRED: ['Pembayaran kedaluwarsa', 'Waktu pembayaran telah berakhir.'],
    CANCELLED: ['Pesanan dibatalkan', 'Pesanan tidak akan diproses lagi.'],
    BLOCKED: ['Gagal diproses', 'Top up gagal diproses dan perlu penanganan admin.'],
    REFUND: ['Perlu pemeriksaan admin', 'Hubungi admin untuk memeriksa status pembayaran dan penyelesaian pesanan.'],
    REFUNDED: ['Perlu pemeriksaan admin', 'Penyedia melaporkan status refund. Hubungi admin untuk memeriksa penyelesaian.'],
};
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
export function orderStatusInfo(status) {
    const [label, description] = orderStatuses[String(status || '').toUpperCase()] || [
        'Status sedang diperbarui', 'Silakan periksa kembali status pesanan.',
    ];
    return { label, description };
}
export function paymentStatusLabel(status) {
    return paymentLabels[String(status || '').toUpperCase()] || 'Status pembayaran belum tersedia';
}
export function nextOrderAction(orderStatus, paymentStatus = null, paidAt = null) {
    const order = String(orderStatus || '').toUpperCase();
    const payment = String(paymentStatus || '').toUpperCase();
    if (['BLOCKED', 'REFUND', 'REFUNDED'].includes(order)) return 'support';
    if (!['FAILED', 'EXPIRED', 'CANCELLED'].includes(order)) return null;
    if (paidAt || ['PAID', 'SETTLEMENT', 'CAPTURE', 'SUCCESS', 'REFUNDED', 'REFUND'].includes(payment)) return 'support';
    if (['PENDING', 'PENDING_PAYMENT', 'CREATING', 'UNKNOWN', 'PROCESSING'].includes(payment)) return null;
    if (!payment || ['FAILED', 'REJECTED', 'DENIED', 'DENY', 'EXPIRED', 'EXPIRE', 'CANCELLED', 'CANCEL'].includes(payment)) return 'new';
    return null;
}
