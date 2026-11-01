import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { manualHelpMessage, nextOrderAction, orderStatusInfo, paymentStatusLabel } from '../../resources/js/lib/orderStatus.js';

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');

test('homepage never renders hardcoded preview news', () => {
    const source = read('Pages/Catalog/Index.vue');
    assert.doesNotMatch(source, /fallbackNews|Contoh Berita|is_preview/);
    assert.match(source, /sectionEnabled\('news'\) && p\.news\?\.length/);
    assert.match(source, /v-for="a in p\.news"/);
});

test('order detail shows real failed/closed payment statuses, not an unused error ref', () => {
    const source = read('Pages/Customer/OrderDetail.vue');
    assert.match(source, /\['REJECTED', 'FAILED', 'DENIED', 'DENY'\]/);
    assert.match(source, /\['EXPIRED', 'EXPIRE', 'CANCELLED', 'CANCEL'\]/);
    assert.match(source, /v-if="paymentIssue" role="alert"/);
    assert.doesNotMatch(source, /errors\.payment/);
    assert.match(source, /paymentState\?\.status === 'UNKNOWN'/);
});

test('disabled, current and live pagination links have distinct non-dummy semantics', () => {
    const component = read('Components/CustomerPagination.vue');
    assert.match(component, /v-if="!link\.url \|\| link\.active"/);
    assert.match(component, /:aria-current="link\.active \? 'page' : undefined"/);
    assert.match(component, /:aria-disabled="!link\.url \? 'true' : undefined"/);
    assert.match(component, /<Link\s+v-else\s+:href="link\.url"/);
    assert.doesNotMatch(component, /href="#"/);
    for (const [path, prop] of [
        ['Pages/Customer/Orders.vue', 'orders'],
        ['Pages/Customer/Tickets.vue', 'tickets'],
        ['Pages/Customer/Wallet.vue', 'entries'],
    ]) {
        const page = read(path);
        assert.match(page, new RegExp('<CustomerPagination :links="' + prop + '\\.links \\|\\| \\[\\]"'));
        assert.doesNotMatch(page, /link\.url \|\| '#'/);
    }
});

test('guest tracking reports HTTP failures without suppressing older valid status', () => {
    const source = read('Pages/Guest/OrderStatus.vue');
    assert.match(source, /if\(!response\.ok\)throw new Error/);
    assert.match(source, /if\(!disposed\)errors\.value=\{status:/);
    assert.match(source, /v-if="errors\.status" role="alert"/);
});

test('guest support ticket heading is editable through storefront language settings', () => {
    assert.match(read('Pages/Guest/Support.vue'), /customerText\('pages\.guest\.support\.ticket_for', 'Tiket untuk'\)/);
});

test('unreferenced page favicon props and paymentResult are removed without changing payment request', () => {
    const home = read('Pages/Catalog/Index.vue');
    const product = read('Pages/Catalog/Show.vue');
    assert.doesNotMatch(home, /faviconUrl/);
    assert.doesNotMatch(product, /faviconUrl|paymentResult/);
    assert.match(product, /await requestJson\('\/payments\/orders\//);
    assert.match(product, /window\.location\.assign\('\/payment\?invoice='/);
});

test('checkout uses Lab nominal geometry inside an explicit legacy CSS boundary', () => {
    const product = read('Pages/Catalog/Show.vue');
    const css = readFileSync(new URL('../../resources/css/checkout.css', import.meta.url), 'utf8');
    const global = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(product, /class="lfx-nominal-grid"/);
    assert.match(product, /class="lfx-nominal-top"/);
    assert.match(product, /role="radio"/);
    assert.match(css, /justify-content:space-between/);
    assert.match(css, /-webkit-line-clamp:2/);
    assert.match(css, /object-fit:contain/);
    assert.match(global, /:where\(:not\(\.lfx, \.lfx \*\)\)/);
    assert.doesNotMatch(global, /@scope\s*\(/);
    assert.doesNotMatch(global, /@import ['"]\.\/admin.css/);
    assert.doesNotMatch(product, /lab-pricing|demo-data|mock-api|successModal|lf-order-summary/);
});

test('customer searches show real product suggestions instead of linking to the homepage section', () => {
    const header = read('Components/CustomerShell.vue');
    const home = read('Pages/Catalog/Index.vue');
    const search = read('Components/ProductSearch.vue');
    const styles = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(header, /<ProductSearch class="lf-header-search"/);
    assert.match(header, /mobileSearchOpen/);
    assert.match(header, /onMobileSearchKeydown/);
    assert.match(header, /mobileSearchTrigger\.value\?\.focus\(\)/);
    assert.match(header, /event\.key === 'Escape'/);
    assert.match(header, /event\.key !== 'Tab'/);
    assert.match(home, /<ProductSearch class="lf-search"/);
    assert.match(search, /fetch\('\/catalog\/search\?q='/);
    assert.match(search, /'\/catalog\/' \+ encodeURIComponent\(product.slug\)/);
    assert.match(search, /@pointerdown.prevent/);
    assert.match(search, /activeIndex\.value < 0/);
    assert.match(search, /step < 0 \? results\.value\.length - 1 : 0/);
    assert.match(search, /:aria-activedescendant=/);
    assert.match(search, /function dismissSuggestions\(\)/);
    assert.match(search, /@keydown\.esc="dismissSuggestions"/);
    assert.match(search, /@input="focused=true"/);
    assert.match(search, /function focusSearch\(\)/);
    assert.match(search, /@focus="focusSearch"/);
    assert.match(search, /query\.value\.trim\(\) && !ready\.value && !loading\.value/);
    assert.match(search, /:id="menuId \+ '-option-' \+ index"/);
    assert.doesNotMatch(header, /<Link href="\/#produk" class="lf-header-search"/);
    assert.doesNotMatch(header, /<Link href="\/#produk" class="lf-search-mobile"/);
    assert.match(styles, /padding:0 14px 0 46px!important/);
    assert.match(styles, /height:40px;padding:0;overflow:visible;border:0;border-radius:0;background:transparent/);
});

test('wallet payment status is never a fake perpetual quote and checkout description is not duplicated', () => {
    const methods = read('Components/Checkout/PaymentMethods.vue');
    const checkout = read('Pages/Catalog/Show.vue');
    assert.match(methods, /channel\?\.group === 'wallet' && !props\.customer/);
    assert.match(methods, /return props\.loadingQuotes \? 'Menghitung…' : 'Pilih metode'/);
    assert.match(checkout, /:loading-quotes="busy==='quote'"/);
    assert.doesNotMatch(checkout, /lfx-description-box/);
    assert.match(checkout, /lf-product-description-legacy/);
});

test('all eight order statuses display Indonesian labels and descriptions', () => {
    for (const status of ['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED', 'BLOCKED']) {
        const label = orderStatusInfo(status);
        assert.ok(label.label && label.description);
        assert.notEqual(label.label, status);
    }
    for (const status of ['REFUND', 'REFUNDED']) {
        const label = orderStatusInfo(status);
        assert.notEqual(label.label, 'Status sedang diperbarui');
        assert.match(label.description, /admin/i);
    }
    const detail = read('Pages/Customer/OrderDetail.vue');
    assert.doesNotMatch(detail, /{{\s*order\.status\s*}}|{{\s*paymentState\.status\s*}}/);
    assert.match(detail, /statusInfo\.label/);
    assert.match(detail, /paymentStatusLabel\(paymentState\.status\)/);
    assert.equal(paymentStatusLabel('REJECTED'), 'Pembayaran ditolak');
});

test('paid or uncertain failed orders cannot be encouraged to pay again', () => {
    for (const status of ['failed', 'expired', 'cancelled']) {
        assert.equal(nextOrderAction(status, 'failed'), 'new');
        assert.equal(nextOrderAction(status, 'paid'), 'support');
        assert.equal(nextOrderAction(status, 'unknown'), null);
    }
    assert.equal(nextOrderAction('FAILED', null, '2026-10-14T01:00:00Z'), 'support');
    assert.equal(nextOrderAction('blocked', 'paid'), 'support');
    assert.equal(nextOrderAction('blocked', 'failed'), 'support');
    assert.equal(nextOrderAction('cancelled', 'cancelled', true), 'support');
    assert.equal(nextOrderAction('processing', 'paid'), null);
    assert.match(manualHelpMessage, /ID order dan bukti bayar/);
    const tracking = read('Pages/Guest/Track.vue');
    assert.match(tracking, /nextOrderAction\(detail\.value\.fulfillmentStatus,detail\.value\.paymentStatus,detail\.value\.wasPaid\)/);
    assert.match(tracking, /nextAction==='support'/);
    assert.match(tracking, /Buat pesanan baru/);
    assert.match(tracking, /Hubungi admin/);
    assert.doesNotMatch(tracking, /Pengembalian dana sedang atau telah diproses/);
});

test('modal summary payment button is disabled during busy checkout', () => {
    const checkout = read('Pages/Catalog/Show.vue');
    assert.match(checkout, /v-else type="button" :disabled="Boolean\(busy\)" @click="prepareOrder"/);
    assert.match(checkout, /id="lfx-bottom-pay"/);
});

test('customer payment page never promises an automatic refund', () => {
    const source = read('Pages/Payment/Show.vue');
    // BLOCKED tidak lagi status publik (butir 4): kondisi setara = refunded atau perlu dukungan admin.
    assert.match(source, /if \(refunded\.value \|\| requiresSupport\.value\)/);
    assert.doesNotMatch(source, /Pesanan masuk proses pengembalian dana/);
});

test('payment page shows a separate fee row between subtotal and total', () => {
    const source = read('Pages/Payment/Show.vue');
    assert.match(source, /Biaya pembayaran/);
    assert.match(source, /formatIdr\(order\.fee_idr\)/);
    // Urutan: subtotal -> fee -> total. Total tetap dari server.
    const subtotal = source.indexOf('order.product_total_idr');
    const fee = source.indexOf('order.fee_idr');
    const total = source.indexOf('order.total_idr');
    assert.ok(subtotal > -1 && fee > subtotal && total > fee);
    assert.doesNotMatch(source, /order\.total_idr\s*=\s*order\.product_total_idr/);
});

test('guest access code is optional on the ticket form and never blocks first contact', () => {
    const support = read('Pages/Guest/Support.vue');
    // Kode akses hanya untuk membuka riwayat dari perangkat lain.
    assert.match(support, /<details v-if="!order" class="lf-guest-support-access">/);
    assert.match(support, /v-model="form\.access_code" minlength="64" maxlength="64"/);
    assert.doesNotMatch(support, /v-model="form\.access_code" required/);
    assert.match(support, /dari perangkat yang sama riwayat tiket langsung terbuka/);
});

test('guest ticket status is translated instead of showing raw enum values', () => {
    const support = read('Pages/Guest/Support.vue');
    assert.match(support, /const statusLabel = status => \(\{ OPEN:/);
    assert.match(support, /Status: \{\{ statusLabel\(ticket\.status\) \}\}/);
    assert.doesNotMatch(support, /Status: \{\{ ticket\.status \}\}/);
    // Status yang benar-benar bisa disetel admin harus punya terjemahan;
    // PENDING/ANSWERED bukan status tiket yang sah.
    const controller = readFileSync(new URL('../../app/Http/Controllers/AdminSupportController.php', import.meta.url), 'utf8');
    const statuses = controller.match(/private const STATUSES = \[([^\]]+)\]/)[1].match(/'([A-Z_]+)'/g).map(s => s.replaceAll("'", ''));
    for (const status of statuses) {
        assert.ok(support.includes(status + ':'), `status ${status} belum diterjemahkan`);
    }
    assert.doesNotMatch(support, /PENDING: |ANSWERED: /);
});

test('payment statuses never borrow the six order-status labels', () => {
    // Status pembayaran (UNKNOWN/REFUNDED/...) bukan status pesanan: kalau dinormalkan
    // lewat orderStatusInfo, pembayaran belum pasti tampil "Gagal" dan pembeli membayar ulang.
    for (const page of ['Pages/Guest/OrderStatus.vue', 'Pages/Guest/Track.vue', 'Pages/Customer/OrderDetail.vue']) {
        const source = read(page);
        assert.match(source, /paymentStatusLabel/, `${page} harus memakai paymentStatusLabel`);
        assert.doesNotMatch(source, /statusLabel\(paymentState\.status\)/, `${page} masih menormalkan status pembayaran`);
        assert.doesNotMatch(source, /statusMeta\(detail\.paymentStatus\)/, `${page} masih menormalkan status pembayaran`);
    }
});

test('resend-verification control is not nested inside the login form', () => {
    const login = read('Pages/Auth/Login.vue');
    // Submit bersarang membuat klik "kirim ulang" ikut mengirim form login.
    assert.doesNotMatch(login, /<form[^>]*@submit\.prevent="resendVerification"/);
    assert.match(login, /type="button"[^>]*@click="resendVerification"/);
    const loginFormEnd = login.indexOf('</form>');
    assert.ok(loginFormEnd > -1, 'form login harus ada penutupnya');
    assert.ok(login.indexOf('@click="resendVerification"') > loginFormEnd, 'tombol kirim ulang harus di luar form login');
});


test('wallet top-up payment groups show only logos while collapsed and compact two-column cards while expanded', () => {
    const wallet = read('Pages/Customer/Wallet.vue');
    assert.match(wallet, /openedPaymentGroup !== group\.code && \['va', 'ewallet'\]\.includes\(group\.code\)/);
    assert.match(wallet, /v-for="channel in group\.items\.filter\(item => item\.logo_url\)"/);
    assert.match(wallet, /:alt="channel\.name" loading="lazy"/);
    assert.match(wallet, /openedPaymentGroup === group\.code/);
    assert.match(wallet, /'grid-cols-2 p-2'/);
    assert.match(wallet, /\{\{ channel\.name \}\}/);
    assert.match(wallet, /quote\.total_idr/);
    assert.match(wallet, /Biaya pembayaran/);
    assert.match(wallet, /await postJson\('\/account\/wallet\/topups\/quote'/);
    assert.match(wallet, /await postJson\('\/account\/wallet\/topups'/);
});

test('payment F3/F11 uses an untruncated chip and paired 44px check buttons', () => {
    const source = read('Pages/Payment/Show.vue');
    for (const marker of [
        'class="lf-payment-invoice-chip',
        'class="lf-payment-invoice-number block font-mono',
        'overflow-wrap: anywhere',
        '.lf-payment-actions > .lf-payment-check-action',
        'height: 44px !important',
        ':focus-visible',
        '<button type="button" class="lf-payment-check-action"',
        '<Link :href="statusUrl" class="lf-payment-check-action"',
    ]) assert.ok(source.includes(marker), 'Missing payment F3/F11 marker: ' + marker);
    assert.ok(!source.includes('max-w-40 truncate'), 'Invoice number must not truncate');
});

test('manual check shares the 3-second Inertia status read and reports errors', () => {
    const source = read('Pages/Payment/Show.vue');
    for (const marker of [
        'if (activeStatusReload) return activeStatusReload',
        "only: ['order', 'payment']",
        "manualChecking ? 'Memeriksa…'",
        'aria-live="polite"',
        'Status belum berhasil diperiksa. Coba lagi.',
        'Terakhir diperiksa',
        '}, 3000)',
    ]) assert.ok(source.includes(marker), 'Missing payment feedback guard: ' + marker);
    const checkMethod = source.slice(source.indexOf('async function checkStatusManually()'), source.indexOf('async function resumePayment()'));
    assert.ok(!checkMethod.includes('fetch(') && !checkMethod.includes('payments/orders')
        && !checkMethod.includes('checkout/orders'), 'Status check cannot create transactions');
});


test('popular cards show a two-line title and only a real publisher below it', () => {
    const source = read('Pages/Catalog/Index.vue');
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(source, /class="lf-popular-title"/);
    assert.match(source, /v-if="x\.publisher" class="lf-popular-publisher"/);
    const popularSection = source.split('<div class="lf-popular">')[1].split('</section>')[0];
    assert.doesNotMatch(popularSection, /<small>\{\{x\.category_name\}\}<\/small>/);
    assert.match(css, /-webkit-line-clamp:unset!important/);
    assert.match(css, /max-height:2\.7em/);
    assert.match(css, /\.lf-popular-title:[^{]*\{[^}]*white-space:normal!important/s);
    assert.match(css, /@media\(min-width:901px\)\{[^}]*grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/s);
    assert.match(css, /@media\(max-width:900px\)\{[^}]*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/s);
});


test('F12 checkout VA and e-wallet logos share padded, contained chips', () => {
    const source = read('Components/Checkout/PaymentMethods.vue');
    assert.match(source, /\.lf-payment-brand-logo img \{/);
    assert.match(source, /max-height: 18px;/);
    assert.match(source, /padding: 4px 6px;/);
    assert.match(source, /object-fit: contain;/);
});


test('F13 country controls stretch to match input instead of raising input height', () => {
    const picker = read('Components/PhoneCountryPicker.vue');
    const checkout = readFileSync(new URL('../../resources/css/checkout.css', import.meta.url), 'utf8');
    assert.match(picker, /lf-country-picker flex flex-wrap items-stretch/);
    assert.match(picker, /shrink-0 self-stretch items-center/);
    assert.match(checkout, /\.lfx \.lfx-phone-entry\{[^}]*align-items:stretch/);
    assert.match(checkout, /\.lfx \.lfx-country-trigger\{[^}]*height:auto;min-height:0;align-self:stretch/);
});
