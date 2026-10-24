import assert from 'node:assert/strict';
import { nextOrderAction, orderStatusInfo, paymentStatusLabel } from '../../resources/js/lib/orderStatus.js';

const officialLabels = new Set([
    'Menunggu Pembayaran',
    'Sedang Diproses',
    'Berhasil',
    'Gagal',
    'Kedaluwarsa',
    'Dibatalkan',
]);

for (const status of ['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED']) {
    assert(officialLabels.has(orderStatusInfo(status).label));
}

assert.equal(orderStatusInfo('BLOCKED').label, 'Gagal');
assert.equal(orderStatusInfo('internal_status').label, 'Gagal');
assert.equal(paymentStatusLabel('unknown_status'), 'Status belum dapat dipastikan');
assert.equal(paymentStatusLabel('UNKNOWN'), 'Status belum dapat dipastikan');
assert.equal(paymentStatusLabel('REFUNDED'), 'Perlu pemeriksaan admin');
assert.equal(nextOrderAction('BLOCKED'), 'support');