import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { walletAdjustmentError } from '../../resources/js/lib/adminWalletAdjustment.js';
import { mappingPayload, sortMappings, mappingStatusLabel } from '../../resources/js/lib/catalogSources.js';
const path = filename => new URL('../../resources/js/Pages/Admin/' + filename, import.meta.url);
const read = filename => readFileSync(path(filename), 'utf8');
const repo = relative => readFileSync(new URL('../../' + relative, import.meta.url), 'utf8');

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
test('admin wallet form exposes client and server validation errors', () => {
    const source = read('CustomerDetail.vue');
    assert.match(source, /import \{ walletAdjustmentError \} from '\.\.\/\.\.\/lib\/adminWalletAdjustment'/);
    assert.match(source, /const problem = walletAdjustmentError\(wallet\.amount_idr, wallet\.reason\)/);
    assert.match(source, /errors\.value = problem \? \{ amount_idr: problem \} : \{\}/);
    assert.match(source, /onError: bag => \{ errors\.value = bag; \}/);
    assert.match(source, /v-if="errors\.amount_idr" class="err"/);
    assert.match(source, /v-if="errors\.reason" class="err"/);
    assert.match(source, /idempotency_key: crypto\.randomUUID\(\)/);
});
test('notification dates use the WIB formatter and collections are guarded', () => {
    const source = read('Notifications.vue');
    assert.match(source, /import \{ num, wibDateTime \} from '\.\.\/\.\.\/lib\/adminFormat'/);
    assert.match(source, /wibDateTime\(item\.created_at\)/);
    assert.match(source, /Array\.isArray\(props\.notifications\?\.data\)/);
    assert.match(repo('resources/js/lib/adminFormat.js'), /timeZone: 'Asia\/Jakarta'/);
});
test('admin props are null-safe across dashboard, presentation, Digiflazz', () => {
    const dash = read('Dashboard.vue');
    assert.match(dash, /Array\.isArray\(props\.chart\)/);
    for (const key of ['recentOrders', 'integrations', 'notifications', 'activities']) {
        assert.ok(dash.includes(key + ': { type: Array, default: () => [] }'), key);
    }
    assert.match(read('CustomerDetail.vue'), /v-if="membershipProfile"/);
    const view = read('Presentation.vue');
    assert.match(view, /props\.presentation\?\.typography \|\| \{\}/);
    assert.match(view, /props\.presentation\?\.text \|\| \{\}/);
    assert.match(view, /registry: \{ type: Object, default: \(\) => \(\{\}\) \}/);
    const digi = read('Digiflazz.vue');
    assert.match(digi, /Array\.isArray\(props\.items\?\.data\)/);
    assert.match(digi, /recentTransactions: \{ type: Array, default: \(\) => \[\] \}/);
});
test('integration page guards an empty integration list', () => {
    const source = read('Integrations.vue');
    assert.match(source, /const current = computed\(\(\) => items\.find/);
    assert.match(source, /<section v-if="current" class="adm-card">/);
    assert.match(source, /v-else class="adm-note">Belum ada integrasi yang memakai alamat pemberitahuan\./);
});
test('Digiflazz owns catalog sync with an honest whole-catalog label', () => {
    const catalog = read('Catalog.vue');
    assert.doesNotMatch(catalog, /\/admin\/catalog\/products\/'\+item\.id\+'\/sync|Sinkron seluruh katalog/);
    const digi = read('Digiflazz.vue');
    assert.match(digi, /router\.post\('\/admin\/digiflazz\/sync'/);
    assert.match(digi, /Sinkron daftar harga/);
    assert.match(digi, /Menyinkronkan…/);
});
test('catalog keeps stored-cost fallback and wide tables scroll on mobile', () => {
    const source = read('Catalog.vue');
    assert.match(source, /Number\(mapping\.store_cost_idr \?\? mapping\.cost_idr \?\? 0\)/);
    assert.match(source, /props\.checkoutPreviews\?\.\[row\.id\]/);
    assert.doesNotMatch(source, /customer_no_template: item\.customer_no_template/);
    assert.match(repo('resources/css/admin.css'), /\.adm-table \{ min-width: 760px; \}/);
    const customers = read('Customers.vue');
    assert.match(customers, /canManageMembershipRules: Boolean/);
    assert.match(customers, /:aria-selected="tab === 'tiers'"/);
    assert.match(customers, /<AdminModal :open="!!tierEditor"/);
});
test('dashboard chart shows orders and revenue while orders split payment and process columns', () => {
    const dash = read('Dashboard.vue');
    assert.match(dash, /role="img" :aria-label="`Grafik jumlah pesanan/);
    assert.match(dash, /rupiah\(row\.revenue_idr\)/);
    assert.match(dash, /Number\(row\.orders \|\| 0\)/);
    const orders = read('Orders.vue');
    assert.match(orders, /<th>Status Pembayaran<\/th>/);
    assert.match(orders, /<th>Status Proses<\/th>/);
});
test('Digiflazz UI omits Harga Max controls and client requests while keeping SKU health tools', () => {
    const source = read('Digiflazz.vue');
    assert.doesNotMatch(source, /Harga Max|max-prices|_previewMaxPrices|_applyMaxPrices/);
    assert.match(source, /healthLabel/);
    assert.match(source, /Sinkron daftar harga/);
});
test('auth pages share the light admin shell', () => {
    for (const file of ['Login.vue', 'ForgotPassword.vue', 'ResetPassword.vue', 'PasswordConfirm.vue']) {
        const source = read(file);
        assert.match(source, /class="auth-page"/, file);
        assert.doesNotMatch(source, /lf-admin/, file);
        assert.doesNotMatch(source, /bg-slate-900|text-slate-100/, file);
    }
    assert.match(read('PasswordConfirm.vue'), /class="adm-btn primary auth-submit"/);
});
test('reports separate sections and keep operations grouped', () => {
    const source = read('Reports.vue');
    for (const section of ['Penjualan', 'Produk', 'Operasional']) assert.match(source, new RegExp(section));
    assert.match(source, /aria-label="Bagian laporan"/);
    assert.match(source, /\[\[?.*'sales', 'Penjualan'.*\], \['products', 'Produk'\], \['operations', 'Operasional'\]\]/);
    assert.match(source, /tab === 'sales'/);
    assert.match(source, /Produk terlaris/);
    assert.match(source, /Kategori terlaris/);
    assert.match(source, /Kinerja penyedia/);
    assert.match(source, /v-if="canViewFinance"/);
    assert.match(source, /const exportReport = async \(\) => \{/);
    assert.match(source, /props\.providerReport\.map/);
    assert.match(source, /props\.daily\.map/);
    assert.match(source, /props\.topProducts\.map/);
    assert.match(source, /link\.download = `laporan-\$\{tab\.value\}-\$\{range\}\.csv`/);
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
    assert.match(source, /const attention = computed\(\(\) => \[/);
    assert.match(source, /\.filter\(check => check\.status !== 'HEALTHY'\)/);
    assert.match(source, /v-for="item in attention"/);
    assert.match(source, /Perlu perhatian/);
    assert.match(source, /Semua komponen berstatus normal\./);
});
test('orders prioritize attention then pending, newest last', () => {
    const controller = repo('app/Http/Controllers/AdminOrdersController.php');
    assert.match(controller, /THEN 0 WHEN orders\.status = 'PENDING_PAYMENT' THEN 1 ELSE 2 END/);
    assert.match(controller, /orderByDesc\('orders\.id'\)/);
    const source = read('Orders.vue');
    assert.match(source, /:class="\{ attn: row\.needs_attention \}"/);
    assert.match(source, /<option value="attention">Perlu perhatian<\/option>/);
    assert.match(source, /<p v-if="row\.needs_attention" class="dim" style="color:var\(--a-danger\)">Perlu perhatian<\/p>/);
    assert.match(source, /Pesanan yang perlu perhatian tampil paling atas/);
});
test('customer detail is a sequential profile-to-accounts page', () => {
    const source = read('CustomerDetail.vue');
    const order = ['<h2>Profil</h2>', '<h2>Tingkat member</h2>', '<h2>Riwayat pesanan</h2>', '<h2>Perubahan saldo</h2>', '<h2>Tiket bantuan</h2>', '<h2>Akun game tersimpan</h2>'];
    let previous = -1;
    for (const marker of order) { const position = source.indexOf(marker); assert.ok(position > previous, marker); previous = position; }
    assert.match(source, /<button v-if="isSuperAdmin" type="button" class="adm-btn primary" @click="openManage">Atur pelanggan<\/button>/);
    assert.doesNotMatch(source, /lf-admin/);
});
test('admin access editor opens in a modal over the account list', () => {
    const source = read('Access.vue');
    assert.match(source, /<AdminModal :open="!!editor"/);
    assert.match(source, /@click="openEdit\(row\)"/);
    assert.match(source, /import \{ withSensitive \} from '\.\.\/\.\.\/lib\/sensitivePrompt'/);
    assert.doesNotMatch(source, /lf-admin/);
});
test('news entries use a compact list and open the editor in a modal', () => {
    const source = read('Content.vue');
    assert.match(source, /<h2>Berita<\/h2>/);
    assert.match(source, /@click="open\('news', row\)"/);
    assert.match(source, /<AdminModal v-model:open="modal"/);
    assert.doesNotMatch(source, /lf-admin/);
});
test('catalog create form opens in a modal instead of pushing the list down', () => {
    const source = read('Catalog.vue');
    assert.match(source, /<AdminModal v-model:open="addOpen" title="Tambah produk"/);
    assert.doesNotMatch(source, /<form v-if="addOpen"/);
    assert.doesNotMatch(source, /lf-admin/);
});
test('content editors (banner, popup, news, faq, policy) share one modal', () => {
    const source = read('Content.vue');
    assert.match(source, /const open = \(type, row = null\) => \{/);
    for (const type of ['banner', 'popup', 'news', 'faq', 'page']) assert.ok(source.includes(`open('${type}'`), type);
    assert.match(source, /<AdminModal v-model:open="modal"/);
    assert.match(source, /:wide="editing\?\.type === 'news' \|\| editing\?\.type === 'page'"/);
});
test('payment settings keep one page and edit each method in a modal', () => {
    const source = read('Payments.vue');
    assert.match(source, /<AdminModal :open="!!editor" :title="editor\?\.id \? 'Ubah metode pembayaran' : 'Tambah metode pembayaran'"/);
    const order = ['<h2>Metode Pembayaran</h2>', '<h2>QRIS manual</h2>', '<h2>Top up LFAMILIA Coin</h2>', '<h2>Tampilan halaman pembayaran</h2>'];
    let previous = -1;
    for (const marker of order) { const position = source.indexOf(marker); assert.ok(position > previous, marker); previous = position; }
    assert.doesNotMatch(source, /lf-admin/);
});
test('checkout displays separate QRIS choices when both manual and automatic methods are available', () => {
    const checkout = repo('resources/js/Components/Checkout/PaymentMethods.vue');
    const panel = read('Payments.vue');
    assert.ok(checkout.includes("group.key!=='qris' || group.items.length > 1"));
    assert.ok(checkout.includes('v-for="channel in group.items"'));
    assert.match(panel, /const manualQrisReady = computed/);
    assert.ok(panel.includes('is_active: !manualQrisReady.value'));
});

test('customer detail starts at profile and confirms wallet changes before posting', () => {
    const source = read('CustomerDetail.vue');
    assert.ok(source.indexOf('<h2>Profil</h2>') < source.indexOf('<h2>Akun game tersimpan</h2>'));
    assert.match(source, /withSensitive\(`Konfirmasi \$\{direction\} saldo pelanggan sebesar \$\{rupiah\(Math\.abs\(amount\)\)\}\? Alasan: \$\{wallet\.reason\}`/);
    assert.match(source, /onSuccess: \(\) => \{[\s\S]*?wallet\.idempotency_key = crypto\.randomUUID\(\);/);
    assert.doesNotMatch(source, /window\.confirm/);
});
test('sidebar covers every backend menu entry and names the support group', () => {
    const layout = repo('resources/js/Components/Admin/AdminLayout.vue');
    const service = repo('app/Services/AdminPermissionService.php');
    const backend = [...service.matchAll(/'href' => '([^']+)'/g)].map(match => match[1]);
    assert.equal(backend.length, 21, 'daftar menu backend harus 21');
    assert.equal(new Set(backend).size, backend.length, 'URL menu backend tidak boleh duplikat');
    assert.doesNotMatch(layout, /const MENU =/, 'sidebar tidak boleh punya daftar menu kedua');
    assert.match(layout, /panel\.value\.menu/, 'sidebar wajib memakai menu dari server');
    assert.match(layout, /item\.group/, 'kelompok menu harus memakai metadata backend');
    assert.match(service, /'label' => 'Layanan Pelanggan'/);
    assert.match(service, /'label' => 'Pengiriman'/);
    assert.doesNotMatch(layout, /lf-admin(?!-)/);
});
test('catalog shows checkout readiness and Digiflazz owns the only sync switch', () => {
    const catalog = read('Catalog.vue');
    const controller = repo('app/Http/Controllers/AdminCatalogController.php');
    const dashboard = read('Dashboard.vue');
    assert.match(catalog, /row\.storefront_status/);
    assert.match(catalog, /row\.buyable_nominal_count/);
    assert.match(controller, /forPackages\(\$pagePackageIds\)/);
    assert.doesNotMatch(catalog, /toggleAutoSync/, 'global sync switch must live in Digiflazz');
    assert.match(catalog, /href="\/admin\/digiflazz"/);
    assert.match(dashboard, /label: 'Pesanan Selesai'/);
    assert.doesNotMatch(dashboard, /label: 'Pembayaran Berhasil'/);
});

test('support and fulfillment lead with the work list instead of metric cards', () => {
    assert.match(read('Support.vue'), /class="adm-summary"/);
    for (const file of ['Support.vue', 'Fulfillment.vue', 'Settings.vue']) {
        assert.ok(!read(file).includes('lf-admin-summary'), file);
    }
});
test('wallet adjustment asks for the password before posting from either page', () => {
    const detail = read('CustomerDetail.vue');
    assert.match(detail, /const adjustWallet = \(\) => \{/);
    assert.match(detail, /if \(problem\) return;/);
    assert.match(detail, /router\.post\('\/admin\/customers\/' \+ props\.customer\.id \+ '\/wallet'/);
    assert.doesNotMatch(detail, /window\.confirm/);

    // Form saldo di daftar pelanggan juga wajib lewat konfirmasi sandi lebih dulu.
    const list = read('Customers.vue');
    assert.match(list, /import \{ withSensitive \} from '\.\.\/\.\.\/lib\/sensitivePrompt'/);
    assert.match(list, /withSensitive\(`Konfirmasi \$\{direction\} saldo/);
    const gate = list.indexOf('withSensitive(`Konfirmasi');
    const post = list.indexOf("router.post('/admin/customers/' + editor.value.row.id + '/wallet'");
    assert.ok(gate > 0 && post > gate, 'wallet POST must sit inside withSensitive');
    assert.match(list, /walletAdjustmentError\(editor\.value\.amount_idr, editor\.value\.reason\)/);
});
test('product notices are edited in a modal and render a preview', () => {
    const source = read('Catalog.vue');
    assert.doesNotMatch(source, /border-slate-200 bg-white/);
    assert.match(source, /<AdminModal v-model:open="noticeOpen"/);
    assert.match(source, /v-if="product\?\.notices\?\.length" class="adm-list"/);
    assert.match(source, /const preview = computed\(\(\) => \{/);
    assert.match(source, /\{\{ preview \|\| '—' \}\}/);
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
    assert.match(source, /applied\.range === 'custom' \? `\$\{applied\.from\}_\$\{applied\.to\}`/);
    assert.doesNotMatch(source, /const range = form\.range === 'custom'/);
    // Jejak audit harus sukses SEBELUM CSV diunduh; ekspor tanpa audit dilarang.
    const auditAt = source.indexOf("await fetch('/admin/reports/export-audit'");
    const downloadAt = source.indexOf('URL.createObjectURL');
    assert.ok(auditAt > 0 && downloadAt > auditAt, 'CSV must be downloaded only after the audit call succeeds');
    assert.match(source, /if \(!response\.ok\) throw new Error/);
    // Formula setelah whitespace juga dinetralkan.
    assert.match(source, /const safe = \/\^\\s\*\[=\+@-\]\/\.test\(text\)/);
});
test('notification open waits for mark-read and report export posts JSON by fetch', () => {
    const notifications = read('Notifications.vue');
    // Kunjungan Inertia kedua membatalkan POST pertama, jadi navigasi menunggu markRead.
    assert.match(notifications, /void markRead\(item\)\.finally\(\(\) => router\.visit\(href\)\)/);
    assert.doesNotMatch(notifications, /markRead\(item\);\s*\n\s*const href = notificationHref\(item\);\s*\n\s*if \(href\) router\.visit\(href\);/);
    const reports = read('Reports.vue');
    // Endpoint audit membalas JSON, bukan halaman Inertia, dan ditunggu sebelum unduhan.
    assert.match(reports, /await fetch\('\/admin\/reports\/export-audit'/);
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
            if (new RegExp('\\b' + name + '\\.value\\b').test(template)) offenders.push(file.pathname + ' → ' + name);
        }
    }
    assert.deepEqual(offenders, []);
});

test('round-two admin fixes keep their UI entry points', () => {
    const catalog = read('Catalog.vue');
    // Nominal manual tetap didukung; seller Digiflazz memakai markup per nominal.
    assert.match(catalog, /manual_instructions/);
    assert.doesNotMatch(catalog, /info\.price_markup_type|info\.price_markup_fixed_idr/);
    assert.match(catalog, /isDigiflazzNominal/);
    // Global pricing remains available only for manual and voucher markup.
    assert.match(catalog, /\/admin\/catalog\/pricing/);
    assert.match(catalog, /Markup Manual \/ Voucher/);
    assert.match(catalog, /Digiflazz memakai markup per nominal/);
    assert.doesNotMatch(catalog, /margin\/preview|Margin global|catalog\/margin/);
    assert.match(catalog, /\/packages\/\$\{row\.id\}\/duplicate/);
    assert.match(catalog, /\/voucher-stock/);
    assert.match(catalog, /removeKind === 'product'/);
    assert.match(catalog, /\/admin\/catalog\/products\/\$\{/);
    const fulfillment = read('Fulfillment.vue');
    assert.match(fulfillment, /can_reconcile/);
    assert.match(fulfillment, /can_retry/);
    assert.match(fulfillment, /can_fail/);
    const access = read('Access.vue');
    assert.match(access, /withSensitive\('Menghapus akun admin/);
    const customer = read('CustomerDetail.vue');
    assert.match(customer, /deletable && isSuperAdmin/);
});

// CR-01 / CR-04: membuka lalu menyimpan nominal hanya untuk mengubah nama,
// grup, urutan, atau status tidak boleh menyentuh harga yang tersimpan.
test('editing a nominal keeps stored pricing and nominal_value', async () => {
    const { nominalMode, nominalPayload, nominalPriceValue } = await import('../../resources/js/lib/catalogNominal.js');
    const rows = {
        PRODUCT_MARGIN: { id: 1, code: 'PM-1', name: 'Kode 10', group_name: 'Umum', sort_order: 1, is_active: true, nominal_value: 10, pricing_mode: 'PRODUCT_MARGIN', sell_price_idr: null, sell_markup_percent: null, margin_percent: null, margin_fixed_idr: null },
        PERCENT: { id: 2, code: 'PC-1', name: 'Kode 20', group_name: 'Umum', sort_order: 2, is_active: true, nominal_value: 20, pricing_mode: 'PERCENT', sell_price_idr: null, sell_markup_percent: null, margin_percent: 12.5, margin_fixed_idr: null },
        FIXED: { id: 3, code: 'FX-1', name: 'Kode 30', group_name: 'Umum', sort_order: 3, is_active: true, nominal_value: 30, pricing_mode: 'FIXED', sell_price_idr: null, sell_markup_percent: null, margin_percent: null, margin_fixed_idr: 3500 },
        SELL_PRICE: { id: 4, code: 'SP-1', name: 'Kode 40', group_name: 'Umum', sort_order: 4, is_active: true, nominal_value: 40, pricing_mode: 'SELL_PRICE', sell_price_idr: 21000, sell_markup_percent: 18, margin_percent: null, margin_fixed_idr: null },
    };
    const form = row => ({
        name: row.name + ' baru', group_name: 'Promo', sort_order: row.sort_order + 1, is_active: !row.is_active,
        price_mode: nominalMode(row), price_value: nominalPriceValue(row), cost_idr: '',
    });
    for (const [mode, row] of Object.entries(rows)) {
        const payload = nominalPayload(row, form(row), 'generated');
        for (const key of ['pricing_mode', 'sell_price_idr', 'margin_percent', 'margin_fixed_idr', 'nominal_value', 'note']) {
            assert.equal(key in payload, false, `${mode}: ${key} tidak boleh ikut terkirim`);
        }
        assert.equal(payload.name, row.name + ' baru');
        assert.equal(payload.sort_order, row.sort_order + 1);
        assert.equal(payload.is_active, !row.is_active);
    }
    // Nilai yang tampil harus mengikuti mode tersimpan: PERCENT dari
    // margin_percent, FIXED dari margin_fixed_idr — bukan kolom lain.
    assert.equal(nominalMode(rows.PERCENT), 'MARKUP');
    assert.equal(nominalPriceValue(rows.PERCENT), 12.5);
    assert.equal(nominalMode(rows.FIXED), 'FIXED');
    assert.equal(nominalPriceValue(rows.FIXED), 3500);
    assert.equal(nominalPriceValue(rows.PRODUCT_MARGIN), '');
    // Harga tetap terkirim saat admin memang mengubahnya.
    const changed = nominalPayload(rows.SELL_PRICE, { ...form(rows.SELL_PRICE), price_value: 25000 }, 'x');
    assert.equal(changed.pricing_mode, 'SELL_PRICE');
    assert.equal(changed.sell_price_idr, 25000);

    // Nominal FIXED yang harganya diubah tetap FIXED: angkanya masuk ke
    // margin_fixed_idr, bukan margin_percent (3.500% = 350.000%).
    const fixedEdit = nominalPayload(rows.FIXED, { ...form(rows.FIXED), price_value: 4000 }, 'x');
    assert.equal(fixedEdit.pricing_mode, 'FIXED');
    assert.equal(fixedEdit.margin_fixed_idr, 4000);
    assert.equal(fixedEdit.margin_percent, undefined);

    // Nominal PERCENT yang harganya diubah tetap PERCENT.
    const percentEdit = nominalPayload(rows.PERCENT, { ...form(rows.PERCENT), price_value: 15 }, 'x');
    assert.equal(percentEdit.pricing_mode, 'PERCENT');
    assert.equal(percentEdit.margin_percent, 15);
    assert.equal(percentEdit.margin_fixed_idr, undefined);

    // Admin MENGGANTI mode di form tetap dihormati (bukan dikunci ke mode lama).
    const fixedToMarkup = nominalPayload(rows.FIXED, { ...form(rows.FIXED), price_mode: 'MARKUP', price_value: 15 }, 'x');
    assert.equal(fixedToMarkup.pricing_mode, 'PERCENT');
    assert.equal(fixedToMarkup.margin_percent, 15);
    const percentToFixed = nominalPayload(rows.PERCENT, { ...form(rows.PERCENT), price_mode: 'FIXED', price_value: 20000 }, 'x');
    assert.equal(percentToFixed.pricing_mode, 'SELL_PRICE');
    assert.equal(percentToFixed.sell_price_idr, 20000);
    // Ganti mode tidak boleh meninggalkan angka mode lama: kolom harga yang
    // bukan milik mode baru dikirim null, supaya tidak ada sisa 3.500 di
    // margin_fixed_idr saat nominal sudah jadi markup.
    assert.equal(percentToFixed.margin_percent, null);
    assert.equal(percentToFixed.margin_fixed_idr, null);
    assert.equal(fixedToMarkup.sell_price_idr, null);
    assert.equal(fixedToMarkup.margin_fixed_idr, null);
    // Panel tidak punya markup produk/nominal terpisah: payload harga tidak
    // boleh membawa kolom sell_markup_percent sama sekali.
    for (const payload of [changed, fixedEdit, percentEdit, fixedToMarkup, percentToFixed]) {
        assert.equal('sell_markup_percent' in payload, false);
    }
    // Ganti mode dengan ANGKA yang sama juga harus tetap tercatat (bukan
    // dianggap tidak berubah hanya karena nilainya kebetulan sama).
    const sameNumberSwitch = nominalPayload(rows.SELL_PRICE, { ...form(rows.SELL_PRICE), price_mode: 'MARKUP', price_value: 21000 }, 'x');
    assert.equal(sameNumberSwitch.pricing_mode, 'PERCENT');
    assert.equal(sameNumberSwitch.margin_percent, 21000);
    // Catalog.vue memakai helper itu, bukan payload lama yang menimpa null.
    const catalog = read('Catalog.vue');
    assert.match(catalog, /import \{ nominalMode, nominalPayload, nominalPriceValue \} from '\.\.\/\.\.\/lib\/catalogNominal'/);
    assert.match(catalog, /const payload = nominalPayload\(savedRow, nominal,/);
    assert.match(catalog, /legacy_voucher_sell_price_idr/);
    assert.doesNotMatch(catalog, /nominal_value: null,\s*\n\s*pricing_mode:/);
});

// Harga Digiflazz dari SKU seller + markup nominal independen.
test('import defaults new nominal markup to twelve percent without overwriting product names', () => {
    const catalog = read('Catalog.vue');
    assert.match(catalog, /Markup nominal baru/);
    assert.match(catalog, /Math\.ceil\(price \* percentage \/ 100\)/);
    assert.match(catalog, /: 12;/);
    assert.doesNotMatch(catalog, /info\.price_markup_type|info\.price_markup_fixed_idr/);
    assert.match(catalog, /nama produk dan nama nominal lama tetap milik toko/i);
    assert.match(catalog, /seller Digiflazz/i);
    assert.doesNotMatch(catalog, /importUplift|importMargin/);
    const importSubmit = catalog.split('const submitImport = () => {')[1]?.split('\n};')[0];
    assert.ok(importSubmit, 'SKU import submission must exist');
    assert.doesNotMatch(importSubmit, /cost_uplift_percent|sell_markup_percent|margin_percent/);
    // The separate global settings form must remain available for local
    // voucher and manual PRODUCT_MARGIN pricing.
    assert.match(catalog, /cost_uplift_percent: Number\(/);
});

// Tab Sumber hanya bisa mengubah modal sumber manual; harga seller
// Digiflazz read-only dan saklar sinkron di menu Digiflazz.
test('source tab edits store cost and links to canonical Digiflazz sync settings', () => {
    const catalog = read('Catalog.vue');
    const digiflazz = read('Digiflazz.vue');
    assert.match(catalog, /canEditCost = mapping => mapping.provider_code === 'MANUAL'/);
    assert.match(catalog, /saveMapping\(mapping, \{ store_cost_idr: Number\(\$event\.target\.value \|\| 0\) \}\)/);
    assert.doesNotMatch(catalog, /const toggleAutoSync =/);
    assert.doesNotMatch(catalog, /router\.put\('\/admin\/catalog\/auto-sync'/);
    assert.match(catalog, /href="\/admin\/digiflazz"/);
    assert.match(digiflazz, /settings\.put\('\/admin\/digiflazz\/settings'/);
    assert.doesNotMatch(catalog, /products\/\$\{product\.value\.id\}\/sync/);
});

test('Digiflazz and local voucher nominals each keep their price controls', () => {
    const catalog = read('Catalog.vue');
    assert.match(catalog, /const isDigiflazzNominal = computed/);
    assert.match(catalog, /Nominal Digiflazz|Jenis tambahan harga nominal/);
    assert.match(catalog, /margin_fixed_idr = nominal\.price_mode === 'FIXED'/);
    const nominal = repo('resources/js/lib/catalogNominal.js');
    assert.match(nominal, /sell_price_idr: form\.price_mode === 'FIXED'/);
    assert.match(nominal, /margin_percent: form\.price_mode === 'MARKUP'/);
});

// CR-03: membuat akun admin baru (termasuk Super Admin) juga menanyakan kata
// sandi di UI, bukan hanya perubahan peran/izin.
test('creating an admin account asks for password confirmation', () => {
    const access = read('Access.vue');
    // Akun yang sudah nonaktif lalu diganti namanya bukan "menonaktifkan":
    // alasan khusus hanya untuk transisi aktif -> nonaktif.
    assert.match(access, /wasActive: row\.is_active/);
    assert.match(access, /target\.is_active === false && target\.wasActive/);
    // Server menjaga semua mutasi, jadi UI selalu bertanya (bukan bersyarat).
    assert.match(access, /void withSensitive\(sensitiveReason\(\), run\);/);
    assert.doesNotMatch(access, /if \(needsPassword\)/);
    assert.match(access, /if \(!target\.id\) return 'Membuat akun admin baru memerlukan konfirmasi kata sandi\.'/);
    assert.match(repo('routes/web.php'), /Route::post\('\/admin\/access'[\s\S]{0,160}admin\.password\.confirm/);
    assert.match(repo('routes/web.php'), /Route::put\('\/admin\/access\/\{admin\}'[\s\S]{0,160}admin\.password\.confirm/);
    assert.match(repo('routes/web.php'), /Route::delete\('\/admin\/access\/\{admin\}'[\s\S]{0,160}admin\.password\.confirm/);
});

// CR-05: tab Sumber pulih memakai route yang sudah ada, tanpa menghapus route
// sumber/sync/gateway.
test('catalog source tab uses existing mapping and sync routes', () => {
    const catalog = read('Catalog.vue');
    assert.match(catalog, /editTab === 'sources'/);
    assert.match(catalog, /Sumber<\/button>/);
    assert.match(catalog, /\/admin\/catalog\/mappings\/\$\{mapping\.id\}/);
    assert.match(catalog, /\/admin\/catalog\/mappings\/\$\{mapping\.id\}\/sync/);
    // Sinkron seluruh katalog milik menu Digiflazz: tidak ada tombol maupun
    // route sinkron per produk.
    assert.doesNotMatch(catalog, /products\/\$\{product\.value\.id\}\/sync/);
    // Daftar, prioritas, status, dan sinkron ada di panel.
    assert.match(catalog, /movePriority\(mapping, -1\)/);
    assert.match(catalog, /toggleMapping\(mapping\)/);
    assert.match(catalog, /mappingStatusLabel\(mapping\)/);
    // Nama route ditulis di dalam group ber-prefix, jadi yang dicek nama lokalnya.
    const routes = repo('routes/web.php');
    for (const route of ['mappings.sync', 'mappings.update']) {
        assert.ok(routes.includes("'" + route + "'"), 'route ' + route + ' harus tetap ada');
    }
    assert.ok(!routes.includes("'products.sync'"), 'route sinkron per produk harus dibuang');
});


// CR-05: mapping Digiflazz tidak lagi memakai harga/kunci manual.
test('catalog source payload leaves Digiflazz seller price read-only', () => {
    const manual = { id: 1, provider_code: 'MANUAL', cost_idr: 9000, store_cost_idr: 9500, priority: 4, is_active: true };
    assert.equal(mappingPayload(manual, { priority: 2 }).cost_idr, 9000);
    assert.equal(mappingPayload(manual, { priority: 2 }).priority, 2);

    const locked = { id: 2, provider_code: 'DIGIFLAZZ', is_manual_price: true, cost_idr: 8000, priority: 1, is_active: true };
    assert.deepEqual(Object.keys(mappingPayload(locked, { is_active: false })).sort(), ['is_active', 'priority']);

    // Mapping biasa tidak mengirim field harga sama sekali.
    const plain = { id: 3, provider_code: 'DIGIFLAZZ', is_manual_price: false, cost_idr: 8000, priority: 1, is_active: true };
    assert.deepEqual(Object.keys(mappingPayload(plain, { priority: 0 })).sort(), ['is_active', 'priority']);

    assert.deepEqual(sortMappings([{ id: 1, priority: 5, is_active: false }, { id: 2, priority: 9, is_active: true }]).map(m => m.id), [2, 1]);
    assert.equal(mappingStatusLabel({ provider_code: 'VOUCHER_STOCK', is_active: true, stock_counts: { available: 3 } }), '3 kode siap');
    // Mapping nonaktif harus terbaca nonaktif, bukan "3 kode siap".
    assert.equal(mappingStatusLabel({ provider_code: 'VOUCHER_STOCK', is_active: false, stock_counts: { available: 3 } }), 'Nonaktif');
    assert.equal(mappingStatusLabel({ is_active: false, disabled_by_sync: true }), 'Nonaktif (sinkron)');
});


test('audit controls: cancellation reasons, top-up review, manual completion, overdue queue', () => {
    const order = read('OrderDetail.vue');
    assert.match(order, /reason_code: reasonCode/);
    assert.match(order, /reason_note:/);
    assert.match(order, /props\.cancelReasons/);
    assert.match(order, /reason_code: reasonCode/);
    assert.match(order, /:disabled="busy === 'cancel' \|\| !cancelReason/);

    const payments = read('Payments.vue');
    assert.match(payments, /<h2>Top up perlu ditinjau<\/h2>/);
    assert.match(payments, /TIDAK mengubah saldo pelanggan/);
    assert.match(payments, /penyesuaian saldo manual/);

    const fulfillment = read('Fulfillment.vue');
    assert.match(fulfillment, /Lewat \{\{ manualOverdueMinutes \}\} menit/);
    assert.match(fulfillment, /menit jam layanan/);
    assert.match(fulfillment, /:disabled="busy \|\| \(!form\.delivery_code\.trim\(\) && !form\.note\.trim\(\)\)"/);
    assert.match(order, /:disabled="busy === 'complete' \|\| \(!form\.delivery_code\.trim\(\) && !form\.note\.trim\(\)\)"/);

    const dashboard = read('Dashboard.vue');
    assert.match(dashboard, /manual_overdue/);
    assert.match(dashboard, /lewat \{\{ manualOverdueMinutes \}\} menit jam layanan/);
});
