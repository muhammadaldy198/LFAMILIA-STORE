import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { walletAdjustmentError } from '../../resources/js/lib/adminWalletAdjustment.js';
const path = filename => new URL('../../resources/js/Pages/Admin/' + filename, import.meta.url);
const read = filename => readFileSync(path(filename), 'utf8');

test('orphaned admin activation page is removed', () => {
    assert.equal(existsSync(path('Activate.vue')), false);
    assert.doesNotMatch(readFileSync(new URL('../../routes/web.php', import.meta.url), 'utf8'), /admin\/activate|Admin\/Activate/);
});
test('wallet UI validation matches backend signed integer and reason limits', () => {
    for (const n of [-1_000_000_000, -1, 1, 1_000_000_000]) assert.equal(walletAdjustmentError(n, 'Audit saldo'), '');
    for (const n of ['', 0, 1.5, NaN, Infinity, 1_000_000_001, -1_000_000_001]) assert.notEqual(walletAdjustmentError(n, 'Audit saldo'), '');
    for (const reason of ['', 'AB', '   ', 'A'.repeat(501)]) assert.notEqual(walletAdjustmentError(100, reason), '');
    assert.equal(walletAdjustmentError(100, '   valid   '), '');
});
test('both admin wallet forms expose client/server validation errors', () => {
    for (const name of ['Customers.vue', 'CustomerDetail.vue']) {
        const source = read(name);
        assert.match(source, /walletAdjustmentError\(/);
        assert.match(source, /onError: \(errors\)/);
        assert.match(source, /v-if="adjustError" role="alert"/);
        assert.match(source, /idempotency_key/);
    }
});
test('notification date formatting and collection guards', () => {
    const source = read('Notifications.vue');
    assert.match(source, /date\(item\.created_at\)/);
    assert.match(source, /timeZone: 'Asia\/Jakarta'/);
    assert.match(source, /Array\.isArray\(props\.notifications\?\.data\)/);
});
test('admin props are null-safe across dashboard, customer tier, presentation, Digiflazz', () => {
    const dash = read('Dashboard.vue');
    for (const key of ['chart', 'notifications', 'recentOrders', 'topProducts', 'integrations']) {
        assert.ok(dash.includes('Array.isArray(props.' + key + ')'));
    }
    assert.match(read('CustomerDetail.vue'), /v-if="membershipProfile"/);
    const view = read('Presentation.vue');
    assert.match(view, /props\.presentation\?\.typography \|\| \{\}/);
    assert.match(view, /props\.registry \|\| \{\}/);
    assert.match(view, /props\.fields \|\| \{\}/);
    const digi = read('Digiflazz.vue');
    assert.match(digi, /Array\.isArray\(props\.items\?\.data\)/);
    assert.match(digi, /Array\.isArray\(props\.recentTransactions\)/);
});
test('integration empty state is meaningful', () => {
    const source = read('Integrations.vue');
    assert.match(source, /v-if="!items\.length" role="status"/);
    assert.match(source, /v-if="items\.length" class="lf-admin-tabs"/);
});
