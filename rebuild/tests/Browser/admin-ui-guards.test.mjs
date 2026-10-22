import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
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
    for (const name of ['CustomerDetail.vue']) {
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
    for (const key of ['chart', 'notifications', 'recentOrders', 'integrations']) {
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
    assert.match(source, /store_cost_idr: mapping\.store_cost_idr \?\? mapping\.cost_idr/);
    assert.match(source, /const price=props\.checkoutPreviews\[pack\.id\]/);
    assert.match(source, /Harga checkout tersimpan/);
    assert.doesNotMatch(source, /customer_no_template: item\.customer_no_template/);
    assert.match(source, /const importRowPreview = \(sku, item\) => markupExample\(\s*sku\.price_idr,\s*effectiveUpliftPercent\(null, item\),\s*item\.sell_markup_percent !== null && item\.sell_markup_percent !== undefined\s*\? effectiveMarkupPercent\(null, item\)\s*: Number\(importForm\.margin_percent\),?\s*\)/);
    assert.match(source, /:disabled="item\.sell_markup_percent !== null && item\.sell_markup_percent !== undefined"/);
    assert.match(source, /@click="saveFields"/);
    assert.match(source, /Modal toko \/ batas harga \(Rp\)<Input v-model\.number="mapping\.store_cost_idr"/);
    assert.match(source, /\{ store_cost_idr: Number\(mapping\.store_cost_idr\) \}/);

    const customers = read('Customers.vue');
    assert.match(customers, /canManageMembershipRules: Boolean/);
    // Aturan tingkat member pindah ke tab sendiri di halaman Pelanggan.
    assert.match(customers, /tab === 'membership'/);
    assert.match(customers, /<Sheet :open="editingTier === tier"/);
});


test('dashboard exposes selectable revenue/orders chart and split order statuses', () => {
    const source = read('Dashboard.vue');
    assert.match(source, /chartMetric/);
    assert.match(source, /payment_status/);
    assert.match(source, /order\.status/);
});

test('Digiflazz UI omits Harga Max controls and client requests while keeping SKU health tools', () => {
    const source = read('Digiflazz.vue');
    assert.doesNotMatch(source, /Harga Max|max-prices|_previewMaxPrices|_applyMaxPrices/);
    assert.match(source, /healthLabel/);
    assert.match(source, /Sinkron daftar harga/);
});

test('password confirmation uses the admin dark theme', () => {
    const source = read('PasswordConfirm.vue');
    assert.match(source, /lf-admin lf-admin-auth/);
    assert.match(source, /bg-slate-900/);
    assert.match(source, /focus-visible:ring/);
});

test('reports separates sections and keeps operations grouped', () => {
    const source = read('Reports.vue');
    for (const section of ['Penjualan', 'Produk', 'Operasional']) assert.match(source, new RegExp(section));
    assert.match(source, /aria-label="Bagian laporan"/);
    assert.match(source, /visible\('sales'\)/);
    assert.match(source, /visible\('products'\)/);
    assert.match(source, /visible\('operations'\)/);
    assert.match(source, /Produk terlaris/);
    assert.match(source, /Kategori teratas/);
    assert.match(source, /Kinerja penyedia layanan/);
    assert.match(source, /v-if="canViewFinance"/);
    assert.match(source, /v-show="visible\('operations'\)"/);
    assert.match(source, /function exportReport\(\)/);
    assert.match(source, /props\.providerReport\.map/);
    assert.match(source, /props\.daily\.map/);
    assert.match(source, /props\.topProducts\.map/);
    assert.match(source, /link\.download = `laporan-\$\{section\.value\}-\$\{range\}\.csv`/);
});

test('notifications link only to authorized in-app order and ticket targets', () => {
    const source = read('Notifications.vue');
    assert.match(source, /item\.target_type === 'order' && canOrders\.value/);
    assert.match(source, /item\.target_type === 'support_ticket' && canSupport\.value/);
    assert.match(source, /item\.target_type === 'fulfillment_attempt'/);
    assert.match(source, /Number\.isSafeInteger\(relatedOrderId\)/);
    assert.match(source, /@click="openNotification\(item\)"/);
});

test('health puts attention checks first and describes all-clear state', () => {
    const source = read('Health.vue');
    assert.match(source, /const attentionChecks = computed\(\(\) => props\.checks\.filter/);
    assert.match(source, /const orderedChecks = computed/);
    assert.match(source, /v-for="check in orderedChecks"/);
    assert.match(source, /Perlu perhatian/);
    assert.match(source, /Tidak ada komponen yang memerlukan perhatian/);
});

test('orders prioritize attention and pending then newest with split status columns', () => {
    const source = read('Orders.vue');
    assert.match(source, /sortedOrders/);
    assert.match(source, /needs_attention/);
    assert.match(source, /PENDING_PAYMENT/);
    assert.match(source, /Status Pembayaran/);
    assert.match(source, /Status Proses/);
});


test('customer detail is a sequential profile-to-accounts page', () => { const source = read('CustomerDetail.vue'); assert.doesNotMatch(source, /<nav class="lf-admin-tabs"/); for (const id of ['customer-profile-heading','customer-orders-heading','customer-wallet-heading','customer-support-heading','customer-accounts-heading']) assert.match(source, new RegExp('id="'+id+'"')); assert.match(source, /v-if="isSuperAdmin"/); });

test('admin access editor opens in a sheet over the account list', () => {
    const source = read('Access.vue');
    assert.match(source, /<Sheet :open="editorOpen"/);
    assert.match(source, /@click="openEdit\(admin\)"/);
    assert.doesNotMatch(source, /id="access-editor"/);
});

test('news entries use a compact list and open the editor in a sheet', () => {
    const source = read('Content.vue');
    assert.match(source, /selectedNewsId === item.id/);
    assert.match(source, /aria-label="Pratinjau berita"/);
    assert.match(source, /<Sheet :open="selectedNewsId !== null"/);
});


test('catalog create form opens in a sheet instead of pushing the list down', () => {
    const source = read('Catalog.vue');
    assert.match(source, /<Sheet :open="showCreateProduct"/);
    assert.doesNotMatch(source, /<form v-if="showCreateProduct"/);
});

test('content editors (banner, popup, news, faq, policy) open in sheets', () => {
    const source = read('Content.vue');
    assert.match(source, /<Sheet :open="bannerFormOpen"/);
    assert.match(source, /<Sheet :open="popupFormOpen"/);
    assert.match(source, /<Sheet :open="newsFormOpen"/);
    assert.match(source, /<Sheet :open="faqFormOpen"/);
    assert.match(source, /<Sheet :open="selectedBannerId === item.id"/);
    assert.match(source, /<Sheet :open="selectedFaqId === item.id"/);
    assert.match(source, /<Sheet :open="selectedPageKey === item.key"/);
});

test('payment settings keep one page and edit each method in a sheet', () => {
    const source = read('Payments.vue');
    assert.doesNotMatch(source, /Subbagian pengaturan pembayaran|settingTabs|selectSetting/);
    // Biaya dan gateway diatur di dalam pop-up metode, bukan tabel terpisah.
    assert.doesNotMatch(source, /id="payment-routing"/);
    assert.doesNotMatch(source, /id="payment-gateways"/);
    assert.match(source, /<Sheet :open="Boolean\(channelEditor\)"/);
    assert.match(source, /Gateway untuk metode ini/);
    const order = ['Metode Pembayaran', 'URL Notifikasi Gateway', 'Pengaturan Top Up', 'Tampilan Checkout'];
    let previous = -1;
    for (const heading of order) { const position = source.indexOf('>'+heading+'</h2>'); assert.ok(position > previous, heading); previous = position; }
    assert.match(source, /area === 'transactions'/);
});

test('customer detail starts at profile and confirms wallet changes', () => {
    const source = read('CustomerDetail.vue');
    // Urutan rancangan 4.12: ringkasan -> profil -> tingkat -> pesanan -> saldo -> tiket -> akun.
    const order = ['lf-admin-summary', 'customer-profile-heading', 'customer-tier-heading', 'customer-orders-heading', 'customer-wallet-heading', 'customer-support-heading', 'customer-accounts-heading'];
    let previous = -1;
    for (const marker of order) { const position = source.indexOf(marker); assert.ok(position > previous, marker); previous = position; }
    assert.match(source, /withSensitive\(`Konfirmasi \$\{direction\} saldo pelanggan/);
    assert.match(source, /function saveWallet\(amount, reason\)/);
});

test('shell never drops backend menu entries and names the support group', () => {
    const source = readFileSync(new URL('../../resources/js/Components/AdminShell.vue', import.meta.url), 'utf8');
    assert.match(source, /Layanan Pelanggan/);
    assert.match(source, /const rest = items\.filter/);
    assert.match(source, /label: 'Lainnya'/);
});

test('support and fulfillment lead with the work list instead of metric cards', () => {
    for (const file of ['Support.vue', 'Fulfillment.vue', 'Settings.vue']) {
        assert.ok(!read(file).includes('lf-admin-summary'), file);
    }
});

test('wallet adjustment lives on the detail page and confirms before posting', () => {
    // Daftar pelanggan hanya untuk mencari dan membuka detail (rancangan 4.12).
    assert.doesNotMatch(read('Customers.vue'), /adjustWallet|saveWallet/);
    const source = read('CustomerDetail.vue');
    assert.match(source, /withSensitive\(`Konfirmasi \$\{direction\} saldo pelanggan sebesar/);
    assert.match(source, /function saveWallet\(amount, reason\)/);
    assert.doesNotMatch(source, /window\.confirm\(`Konfirmasi/);
});

test('product notices use dark theme tokens and render a preview', () => {
    const source = read('Catalog.vue');
    assert.doesNotMatch(source, /border-slate-200 bg-white/);
    assert.match(source, /aria-label="Pratinjau pemberitahuan"/);
});

test('contact cards stack vertically on mobile so WhatsApp and email are not side by side', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const mobile = css.slice(css.indexOf('@media(max-width:640px)'));
    assert.match(mobile, /\.lf-contact-grid>a[^{]*\{flex-direction:column/);
    assert.match(mobile, /\.lf-contact-grid>a>div[^{]*\{min-width:0;width:100%\}/);
    assert.match(mobile, /\.lf-contact-grid small[^{]*\{overflow-wrap:anywhere\}/);
});

test('report export audits itself and never labels a period it did not load', () => {
    const source = read('Reports.vue');
    // Nama file dan data memakai filter yang benar-benar diterapkan, bukan form yang belum disubmit.
    assert.match(source, /const applied = props\.filters \|\| \{\}/);
    assert.match(source, /applied\.range === 'custom' \? `\$\{applied\.from\}-\$\{applied\.to\}`/);
    assert.doesNotMatch(source, /const range = form\.range === 'custom'/);
    // Ekspor klien tetap meninggalkan jejak audit, lewat fetch karena endpoint membalas JSON.
    assert.match(source, /void fetch\('\/admin\/reports\/export-audit'/);
    // Formula setelah whitespace juga dinetralkan.
    assert.match(source, /const safe = \/\^\\s\*\[=\+@-\]\/\.test\(text\)/);
});

test('notification open waits for mark-read and report export posts JSON by fetch', () => {
    const notifications = read('Notifications.vue');
    // Kunjungan Inertia kedua membatalkan POST pertama, jadi navigasi menunggu markRead.
    assert.match(notifications, /void markRead\(item\)\.finally\(\(\) => router\.visit\(href\)\)/);
    assert.doesNotMatch(notifications, /markRead\(item\);\s*\n\s*const href = notificationHref\(item\);\s*\n\s*if \(href\) router\.visit\(href\);/);
    const reports = read('Reports.vue');
    // Endpoint audit membalas JSON, bukan halaman Inertia.
    assert.match(reports, /void fetch\('\/admin\/reports\/export-audit'/);
    assert.doesNotMatch(reports, /router\.post\('\/admin\/reports\/export-audit'/);
});

test('no template expression unwraps a ref that Vue already unwraps', () => {
    const pages = new URL('../../resources/js/Pages/', import.meta.url);
    const walk = dir => readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
        const full = new URL(entry.name + (entry.isDirectory() ? '/' : ''), dir);
        if (entry.isDirectory()) return walk(full);
        return entry.name.endsWith('.vue') ? [full] : [];
    });
    const offenders = [];
    for (const file of walk(pages)) {
        const source = readFileSync(file, 'utf8');
        if (!source.includes('<template>')) continue;
        const [script, template] = source.split('<template>', 2);
        const refs = [...script.matchAll(/const\s+(\w+)\s*=\s*ref\(/g)].map(match => match[1]);
        for (const name of refs) {
            // Di template ref sudah di-unwrap; `.value` akan mengakses properti dari nilai aslinya.
            if (new RegExp('\\b' + name + '\\.value\\b').test(template)) {
                offenders.push(file.pathname.split('/Pages/')[1] + ' -> ' + name);
            }
        }
    }
    assert.deepEqual(offenders, []);
});
