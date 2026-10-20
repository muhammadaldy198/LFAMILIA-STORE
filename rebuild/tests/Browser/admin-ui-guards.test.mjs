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

test('per-product sync button label admits it syncs the whole catalog', () => {
    const source = read('Catalog.vue');
    assert.match(source, /Sinkron seluruh katalog/);
    assert.match(source, /Menyinkronkan seluruh katalog Digiflazz, bukan hanya produk ini/);
    assert.doesNotMatch(source, /Sinkron semua nominal produk/);
    assert.match(source, /router\.post\('\/admin\/catalog\/products\/'\+item\.id\+'\/sync'/);
});

test('catalog prices preserve markup for manual store-cost mappings and shared tables keep mobile cards by default', () => {
    const source = read('Catalog.vue');
    const table = readFileSync(new URL('../../resources/js/Components/AdminResponsiveTable.vue', import.meta.url), 'utf8');

    assert.match(table, /scroll: \{ type: Boolean, default: false \}/);
    assert.match(source, /<AdminResponsiveTable scroll class="min-w-\[1100px\]"/);
    assert.match(source, /if\(mapping\.store_cost_idr!==null&&mapping\.store_cost_idr!==undefined\)/);
    assert.match(source, /mapping\.provider_code === 'DIGIFLAZZ' \? \{/);
    assert.match(source, /\{ store_cost_idr: Number\(mapping\.store_cost_idr\) \}/);
    assert.match(source, /1000000\+Math\.round\(markup\*10000\)/);
    assert.match(source, /Math\.round\(Number\(markup\) \* 10000\)/);
    assert.doesNotMatch(source, /customer_no_template: item\.customer_no_template/);
    assert.match(source, /const importRowPreview = \(sku, item\) => markupExample\(\s*sku\.price_idr,\s*effectiveUpliftPercent\(null, item\),\s*item\.sell_markup_percent !== null && item\.sell_markup_percent !== undefined\s*\? effectiveMarkupPercent\(null, item\)\s*: Number\(importForm\.margin_percent\),?\s*\)/);
    assert.match(source, /:disabled="item\.sell_markup_percent !== null && item\.sell_markup_percent !== undefined"/);
    assert.match(source, /@click="saveFields"/);
    assert.match(source, /Modal toko \/ batas harga \(Rp\)<Input v-model\.number="mapping\.store_cost_idr"/);
    assert.match(source, /\{ store_cost_idr: Number\(mapping\.store_cost_idr\) \}/);

    const customers = read('Customers.vue');
    assert.match(customers, /canManageMembershipRules: Boolean/);
    assert.match(customers, /<Card v-if="canManageMembershipRules"/);
});
