import assert from 'node:assert/strict';
import { nextOrderAction, orderStatusInfo, paymentStatusLabel } from '../../resources/js/lib/orderStatus.js';

const officialLabels = new Set([
    'Menunggu Pembayaran',
    'Diproses',
    'Terkirim',
    'Gagal',
]);

for (const status of ['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED']) {
    assert(officialLabels.has(orderStatusInfo(status).label));
}

assert.equal(orderStatusInfo('BLOCKED').label, 'Gagal');
assert.equal(orderStatusInfo('EXPIRED', false).key, 'FAILED');
assert.equal(orderStatusInfo('EXPIRED', false).note, 'Kedaluwarsa');
assert.equal(orderStatusInfo('EXPIRED', true).note, null);
assert.equal(orderStatusInfo('CANCELLED', false).note, 'Kedaluwarsa');
assert.equal(orderStatusInfo('internal_status').label, 'Gagal');
assert.equal(paymentStatusLabel('unknown_status'), 'Status belum dapat dipastikan');
assert.equal(paymentStatusLabel('UNKNOWN'), 'Status belum dapat dipastikan');
assert.equal(paymentStatusLabel('REFUNDED'), 'Perlu pemeriksaan admin');
assert.equal(nextOrderAction('BLOCKED'), 'support');