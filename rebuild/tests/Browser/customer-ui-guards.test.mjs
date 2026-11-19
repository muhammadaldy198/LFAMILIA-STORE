import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { manualHelpMessage, nextOrderAction, orderStatusInfo, paymentStatusLabel } from '../../resources/js/lib/orderStatus.js';
import { formatTicketDate, ticketStatusLabel } from '../../resources/js/lib/ticketPresentation.js';

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');

test('home load-more resets a hard-refreshed deep pagination URL safely', () => {
    const home = read('Pages/Catalog/Index.vue');
    assert.match(home, /if\(Number\(p\.products\?\.current_page\|\|1\)>1\)/);
    assert.match(home, /router\.get\(q\(\{page:''\}\),\{\},/);
    assert.match(home, /replace:true,preserveState:true,preserveScroll:true/);
});

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
    assert.match(checkout, /v-else type="button" :disabled="Boolean\(busy\)\|\|regionMismatch" @click="prepareOrder"/);
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


test('PR10 wallet reuses the Checkout picker and original .lfx contents geometry', () => {
    const wallet = read('Pages/Customer/Wallet.vue');
    const picker = read('Components/Checkout/PaymentMethods.vue');
    const checkout = read('Pages/Catalog/Show.vue');
    assert.match(wallet, /import CheckoutPaymentMethods from '\.\.\/\.\.\/Components\/Checkout\/PaymentMethods\.vue'/);
    assert.match(wallet, /<div class="lfx contents">\s*<CheckoutPaymentMethods/s);
    assert.doesNotMatch(wallet, /import ['"].*checkout\.css|lf-wallet-logo|v-for="group in paymentGroups"/);
    assert.match(wallet, /:payment-channels="paymentChannels"/);
    assert.match(wallet, /:selected-code="paymentChannelCode"/);
    assert.match(wallet, /:locked="Boolean\(busy\) \|\| attemptLocked"/);
    assert.match(wallet, /@select="selectWalletPayment"/);
    assert.match(picker, /class="lf-payment-group-main"/);
    assert.match(picker, /class="lf-payment-brand-strip"/);
    assert.match(picker, /class="lf-payment-brand-logo"/);
    assert.match(picker, /class="lfx-payment-method-grid"/);
    assert.match(picker, /v-for="channel in group\.items"/);
    assert.match(picker, /if \(props\.locked\) return/);
    assert.match(wallet, /watch\(\[amountIdr, paymentChannelCode\], \(\) => \{ quote\.value = null/);
    assert.match(wallet, /quote\.total_idr/);
    assert.match(wallet, /Biaya pembayaran/);
    assert.match(wallet, /await postJson\('\/account\/wallet\/topups\/quote'/);
    assert.match(wallet, /await postJson\('\/account\/wallet\/topups'/);
    // Checkout uses the default (selection-driven) behavior; Wallet explicitly controls expansion.
    assert.doesNotMatch(checkout, /:expanded-group=|@toggle-group=/);
});

test('PR11 wallet recovery uses the same key and keeps only the status button actionable during uncertainty', () => {
    const wallet = read('Pages/Customer/Wallet.vue');
    const picker = read('Components/Checkout/PaymentMethods.vue');
    assert.match(wallet, /:disabled="busy \|\| result \|\| !quoteMatches"/);
    assert.doesNotMatch(wallet, /:disabled="busy \|\| result \|\| attemptLocked \|\| !quoteMatches"/);
    assert.match(wallet, /async function createTopup\(\)/);
    assert.match(wallet, /idempotency_key: idempotencyKey\.value/);
    assert.match(wallet, /:disabled="Boolean\(busy\) \|\| attemptLocked \|\| !paymentChannelCode"/);
    assert.match(wallet, /type="number"[^>]*:disabled="Boolean\(busy\) \|\| attemptLocked"/);
    assert.match(wallet, /:locked="Boolean\(busy\) \|\| attemptLocked"/);
    assert.match(wallet, /:expanded-group="openedPaymentGroup"/);
    assert.match(wallet, /@toggle-group="toggleWalletPaymentGroup"/);
    assert.match(wallet, /:loading-quotes="busy === 'quote'"/);
    assert.match(wallet, /:current-quote="quoteMatches \? quote : null"/);
    assert.match(wallet, /quote\.value = null/);
    assert.match(picker, /expandedGroup: \{ type: String, default: null \}/);
    assert.match(picker, /defineEmits\(\['select', 'toggleGroup'\]\)/);
    assert.match(picker, /props\.expandedGroup === null/);
    assert.match(picker, /groupExpanded\(group\)/);
    assert.match(picker, /emit\('toggleGroup', group\.key\)/);
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


test('QRIS heading and channel explanation wrap without mid-word truncation', () => {
    const methods = read('Components/Checkout/PaymentMethods.vue');
    assert.match(methods, /lf-payment-brand-strip--qris/);
    assert.match(methods, /lf-payment-group-card--qris/);
    assert.match(methods, /white-space:normal;overflow:visible;text-overflow:clip/);
});


test('F14/PR3 Keterangan keeps one tab (v-else) with description, reviews and FAQ as separate panels in order', () => {
    const product = read('Pages/Catalog/Show.vue');
    assert.match(product, /<template v-else>\s*<section class="lfx-panel lfx-details lfx-panel-description">/);
    const description = product.indexOf('lf-product-description-legacy');
    const reviews = product.indexOf('lf-product-review-section lf-review-legacy');
    const faq = product.indexOf('<div class="lf-product-faq">');
    assert.ok(description >= 0 && reviews > description && faq > reviews);
});

test('product FAQ renders one accordion card per question, one open at a time, without <details>', () => {
    const product = read('Pages/Catalog/Show.vue');
    const css = readFileSync(new URL('../../resources/css/checkout.css', import.meta.url), 'utf8');
    const faqPanel = product.slice(product.indexOf('lfx-panel-faq'), product.indexOf('</section>', product.indexOf('lfx-panel-faq')));
    assert.ok(faqPanel.length > 0);

    assert.ok(!product.includes('lf-product-faq-legacy'));
    assert.ok(!faqPanel.includes('<details'), 'FAQ list still uses <details>');
    assert.ok(!faqPanel.includes('<summary'), 'FAQ list still uses <summary>');
    assert.match(faqPanel, /<div class="lf-product-faq">\s*<div v-for="\(faq, index\) in productFaqs" :key="index \+ ':' \+ faq\.question" class="lf-faq-card" :class="\{open: openFaqIndex === index\}">/);
    assert.match(faqPanel, /<button type="button" class="lf-faq-question" :aria-expanded="openFaqIndex === index"[^>]*@click="toggleFaq\(index\)">/);
    assert.match(faqPanel, /<svg class="lf-faq-chevron"[^>]*stroke="currentColor"/);
    assert.match(faqPanel, /<div v-show="openFaqIndex === index"[^>]*class="lf-faq-answer"><p>\{\{ faq\.answer \}\}<\/p><\/div>/);

    // Semua tertutup secara bawaan; klik kartu terbuka menutupnya; klik kartu lain menggantikannya.
    assert.match(product, /const openFaqIndex = ref\(null\);/);
    assert.match(product, /openFaqIndex\.value = openFaqIndex\.value === index \? null : index;/);
    // Pindah produk lewat Inertia (komponen dipakai ulang) menutup semua kartu lagi.
    assert.match(product, /watch\(\(\) => props\.product\?\.slug, \(\) => \{ openFaqIndex\.value = null; \}\);/);

    // Kotak tunggal lama hilang; tiap kartu punya kotaknya sendiri.
    assert.match(css, /\.lfx \.lf-product-faq\{display:flex;flex-direction:column;gap:10px\}/);
    assert.doesNotMatch(css, /\.lfx \.lf-product-faq\{[^}]*(padding|border|background)/);
    assert.doesNotMatch(css, /,\.lfx \.lf-product-faq\{/);
    assert.match(css, /\.lfx \.lf-faq-card\{border:1px solid var\(--edge\);border-radius:12px;background:var\(--lf-color-surface\);overflow:hidden\}/);
    assert.match(css, /\.lfx \.lf-faq-card\.open \.lf-faq-chevron\{transform:rotate\(180deg\)\}/);
    assert.match(css, /\.lfx \.lf-faq-question:focus-visible\{outline:2px solid var\(--lf-color-primary\);outline-offset:-2px\}/);
});


test('F16 review count uses a separate line and supports zero reviews', () => {
    const product = read('Pages/Catalog/Show.vue');
    assert.match(product, /<p class="lf-review-hero-total">dari \{\{formatCountId\(reviewStats\?\.total \|\| 0\)\}\} ulasan<\/p>/);
    assert.match(product, /props\.reviewStats\?\.total \? Number\(props\.reviewStats\.average \|\| 0\)\.toFixed\(1\) : '–'/);
    assert.match(product, /Belum ada ulasan untuk produk ini\./);
});

test('review panel: centred hero, star bars, flat list of 5, member form after the list, no guest box', () => {
    const product = read('Pages/Catalog/Show.vue');
    const css = readFileSync(new URL('../../resources/css/checkout.css', import.meta.url), 'utf8');
    const controller = readFileSync(new URL('../../app/Http/Controllers/CatalogController.php', import.meta.url), 'utf8');
    const panel = product.slice(product.indexOf('lfx-panel-reviews'), product.indexOf('lfx-panel-faq'));
    assert.ok(panel.length > 0);

    // A1: guest box, old eyebrow, old explanation and old average box are gone.
    for (const removed of ['lf-review-guest-secure', 'lf-review-secure-link', '/orders/check', 'cd75c0db', 'PENILAIAN PELANGGAN', 'c47f9331', 'lf-review-average', 'lf-review-legacy-head']) {
        assert.ok(!panel.includes(removed), 'review panel still contains ' + removed);
    }
    assert.doesNotMatch(css, /\.lf-review-average/);

    // A2: big centred hero + "/ 5.0".
    assert.match(panel, /<div class="lf-review-hero">\s*<p class="lf-review-hero-score"><span class="lf-review-hero-star" aria-hidden="true">★<\/span><strong>\{\{reviewAverageLabel\}\}<\/strong><small>\/ 5\.0<\/small><\/p>/);
    assert.match(css, /\.lfx \.lf-review-hero\{[^}]*justify-items:center[^}]*text-align:center/);
    assert.match(css, /\.lfx \.lf-review-hero-score strong\{[^}]*color:#fde047;font-size:44px/);
    assert.match(product, /Number\(value \|\| 0\)\.toLocaleString\('id-ID'\)/);

    // A3: five bars from reviewStats.distribution.
    assert.match(product, /props\.reviewStats\?\.distribution/);
    assert.match(product, /\[5, 4, 3, 2, 1\]\.map\(\(star\)/);
    assert.match(css, /\.lfx \.lf-review-bar-track\{[^}]*background:rgba\(255,255,255,\.08\)/);
    assert.match(css, /\.lfx \.lf-review-bar-fill\{[^}]*background:#fde047/);
    assert.match(controller, /'distribution' => \$reviewDistribution/);

    // A4: flat rows, no old card classes, review-only time format, list limited to 5.
    assert.match(panel, /<article v-for="review in reviews" :key="review\.id" class="lf-review-row">/);
    assert.ok(!panel.includes('lf-review-card-head') && !panel.includes('lf-review-rating') && !panel.includes('lf-review-verified"'));
    assert.doesNotMatch(css, /\.lfx \.lf-review-list article\b/);
    assert.match(css, /\.lfx \.lf-review-list \.lf-review-row\{[^}]*border:0;border-bottom:1px solid rgba\(255,255,255,\.07\);border-radius:0;background:none/);
    assert.match(css, /\.lfx \.lf-review-list \.lf-review-row:last-of-type\{border-bottom:0\}/);
    assert.match(css, /\.lfx \.lf-review-row-stars \.on\{color:#fde047\}/);
    assert.match(css, /\.lfx \.lf-review-row-stars \.off\{color:rgba\(255,255,255,\.18\)\}/);
    assert.match(css, /\.lfx \.lf-review-row-verified\{color:#a9e34d/);
    assert.match(panel, /formatReviewTime\(review\.published_at \|\| review\.created_at\)/);
    assert.match(product, /function formatDateId\(value\) \{\n    if \(!value\) return '';\n    const date = new Date\(value\);\n    if \(Number\.isNaN\(date\.getTime\(\)\)\) return '';\n    return date\.toLocaleDateString\('id-ID', \{ timeZone: 'Asia\/Jakarta', day: 'numeric', month: 'short', year: 'numeric' \}\);\n\}/);
    assert.match(controller, /->orderByDesc\('published_at'\)->orderByDesc\('id'\)->limit\(5\)/);

    // A5: member form kept and placed after the list; guests get no replacement box.
    const list = panel.indexOf('<div class="lf-review-list">');
    const form = panel.indexOf('<form v-if="customer" class="lf-review-form" @submit.prevent="submitReview">');
    assert.ok(list >= 0 && form > list);
    assert.doesNotMatch(panel, /v-else class="lf-review-form/);
});

test('review time keeps formatDateId output and appends the WIB clock', () => {
    const product = read('Pages/Catalog/Show.vue');
    const source = product.slice(product.indexOf('function formatDateId(value)'), product.indexOf('const reviewAverageLabel'));
    const formatReviewTime = new Function(source.replace(/function formatCountId[\s\S]*?\n}\n/, '') + '\nreturn formatReviewTime;')();
    assert.equal(formatReviewTime('2026-11-17T12:24:00+00:00'), '17 Nov 2026, 19.24');
    assert.equal(formatReviewTime('2026-11-17T17:05:00Z'), '18 Nov 2026, 00.05');
    assert.equal(formatReviewTime(null), '');
    assert.equal(formatReviewTime('bukan tanggal'), '');
});


test('F15 wallet uses customer language and protects the total from floating chat', () => {
    const wallet = read('Pages/Customer/Wallet.vue');
    assert.match(wallet, /Cek status top up/);
    assert.match(wallet, /Top up sebelumnya masih diproses\. Cek statusnya dulu/);
    assert.doesNotMatch(wallet, /Cek percobaan yang sama|coba cek percobaan yang sama/);
    assert.match(wallet, /<CheckoutPaymentMethods\s/);
    assert.match(wallet, /pb-32 sm:pb-5/);
    assert.match(wallet, /:disabled="Boolean\(busy\) \|\| attemptLocked"/);
    assert.match(wallet, /idempotency_key: idempotencyKey\.value/);
});


test('F17 ticket list, detail and messages use Indonesian local dates and statuses', () => {
    assert.equal(formatTicketDate('2026-10-25T07:00:50.000000Z'), '25 Okt 2026, 14.00');
    assert.equal(formatTicketDate('not-a-date'), '–');
    assert.equal(ticketStatusLabel('OPEN'), 'Terbuka');
    assert.equal(ticketStatusLabel('IN_PROGRESS'), 'Sedang ditangani');
    assert.equal(ticketStatusLabel('RESOLVED'), 'Selesai');
    assert.equal(ticketStatusLabel('CLOSED'), 'Ditutup');
    for (const page of ['Pages/Customer/Tickets.vue', 'Pages/Customer/TicketDetail.vue']) {
        const source = read(page);
        assert.match(source, /ticketStatusLabel\(ticket\.status\)/);
        assert.match(source, /formatTicketDate\(ticket\.created_at\)/);
        assert.doesNotMatch(source, /\{\{\s*ticket\.(status|created_at)\s*\}\}/);
    }
    assert.match(read('Pages/Customer/TicketDetail.vue'), /formatTicketDate\(message\.created_at\)/);
});


test('F18 saved game accounts provide real accessible 40px edit and danger buttons', () => {
    const game = read('Pages/Customer/GameAccounts.vue');
    assert.match(game, /min-h-10 items-center justify-center/);
    assert.match(game, /border-red-400\/40 bg-red-400\/10/);
    assert.match(game, /:aria-label="'Edit '\+account\.label"/);
    assert.match(game, /:aria-label="'Hapus '\+account\.label"/);
    assert.match(game, /@click="edit\(account\)"/);
    assert.match(game, /@click="remove\(account\)"/);
});

test('verified nickname uses a one-line flagged success without changing the region mismatch warning', () => {
    const product = read('Pages/Catalog/Show.vue');
    const validWithRegion = product.match(/<div v-else-if="nicknameResult\?\.verified && nicknameResult\?\.region"[\s\S]*?<\/div>/)?.[0];
    assert.ok(validWithRegion);
    assert.match(validWithRegion, /class="lfx-region-line" role="status"/);
    assert.match(validWithRegion, /✅ <strong>\{\{nicknameResult\.nickname\}\}<\/strong> from \{\{regionName\(nicknameResult\.region\)\}\} \{\{flagEmoji\(nicknameResult\.region\)\}\}/);
    assert.doesNotMatch(validWithRegion, /lfx-region-banner|Region akun:|· Cocok/);
    assert.match(product, /v-else-if="nicknameResult\?\.verified" class="lfx-region-line" role="status">✅ <strong>/);
    assert.match(product, /const flagEmoji = \(code\) => \{/);
    assert.match(product, /String\.fromCodePoint/);
    assert.match(product, /v-else-if="regionWarning" class="lfx-region-banner lfx-region-mismatch" role="alert"/);
    assert.match(product, /\.lfx-region-line \{[^}]*color:#a7f3c4;[^}]*white-space:nowrap;[^}]*overflow-x:auto/);
});

test('checkout locks nominal selection until the account data is complete', () => {
    const product = read('Pages/Catalog/Show.vue');
    const css = readFileSync(new URL('../../resources/css/checkout.css', import.meta.url), 'utf8');
    // The gate reuses the submission's own account validation and never
    // applies to products without an account step (vouchers).
    assert.match(product, /const accountComplete = computed\(\(\) => Object\.keys\(localAccountErrors\(\)\)\.length === 0\);/);
    assert.match(product, /const nominalLocked = computed\(\(\) => hasAccountStep\.value && !accountComplete\.value\);/);
    // A tap on a locked nominal guides the buyer back up instead of selecting.
    assert.match(product, /if \(nominalLocked\.value\) \{\s+guideToAccount\(\);\s+return;\s+\}/);
    assert.match(product, /accountEl\.scrollIntoView\(\{ behavior: 'smooth', block: 'start' \}\);/);
    // A successful choice moves the buyer down to the payment section.
    assert.match(product, /if \(hasAccountStep\.value\) scrollToPayment\(\);/);
    assert.match(product, /document\.getElementById\('lfx-payment'\)\?\.scrollIntoView\(\{ behavior: 'smooth', block: 'start' \}\);/);
    // Locked cards stay tappable (the tap is the guidance trigger). The
    // owner's rule (11 Nov 2026): locked means only "cannot be chosen" —
    // no dimming or opacity styling at all. The hint line that used to sit
    // above the grid was removed on the owner's later order (screenshot
    // 11 Nov 2026, executed 14 Nov 2026): the scroll-up guidance on tap
    // replaces it, so the hint must never come back.
    assert.doesNotMatch(product, /Isi data akun dulu untuk memilih nominal/);
    assert.doesNotMatch(product, /locked:/);
    assert.doesNotMatch(css, /lfx-nominal\.locked/);
});

test('checkout presents required account errors as one shared line and drops the label info icon', () => {
    const product = read('Pages/Catalog/Show.vue');
    // Required-but-empty errors render once as a shared line; the
    // classifier keys off the identical wording the local validator and
    // the server validator both generate per field.
    assert.match(product, /function isRequiredAccountError\(field, message\)/);
    assert.match(product, /<p v-if="hasRequiredAccountError" role="alert" class="lfx-error">Data akun wajib diisi\.<\/p>/);
    assert.match(product, /<p v-for="entry in specificAccountErrors"/);
    // The hover-only info icon on account field labels is gone for good.
    assert.doesNotMatch(product, /<i title="Pastikan data akun benar">/);
    // The page-level alert must not repeat what the account panel already
    // shows (Codex P2 on PR #395): it skips panel-covered account errors,
    // while the confirmation modal keeps the translating banner.
    assert.match(product, /const firstPageError = computed\(\(\) =>/);
    // …but only while the account panel is actually shown: on the
    // Keterangan tab the panel unmounts and the banner must speak again.
    assert.match(product, /const panelShown = hasAccountStep\.value && activeTab\.value === 'transaction';/);
    // The panel's own error lines are live regions (Codex P2 on PR #395):
    // with the visual banner suppressed on the Transaksi tab, screen
    // readers still get the announcement when the errors appear.
    assert.match(product, /<p v-for="entry in specificAccountErrors" :key="entry\.field\.field_key" role="alert" class="lfx-error">/);
    assert.equal((product.match(/\{\{firstPageError\}\}/g) || []).length, 1);
    assert.equal((product.match(/\{\{firstCheckoutError\}\}/g) || []).length, 1);
});

test('locked nominal cards stay clickable for automation and assistive tech', () => {
    const product = read('Pages/Catalog/Show.vue');
    // Playwright (and some assistive tech) refuses to activate elements
    // with aria-disabled=true; a locked card must stay activatable
    // because the tap itself is what guides the buyer to the account form.
    assert.doesNotMatch(product, /:aria-disabled="[^"]*nominalLocked/);
});

test('guided account errors clear per field once that field is valid', () => {
    const product = read('Pages/Catalog/Show.vue');
    // One pruning helper serves both input watchers: an error survives
    // until its own field passes local validation, and the password
    // watcher never routes the secret through nickname validation.
    assert.match(product, /function pruneResolvedAccountErrors\(\)/);
    assert.match(product, /delete next\[key\];/);
    assert.match(product, /watch\(\(\) => props\.fields\.filter\(field => field\.type === 'password'\)[\s\S]*?pruneResolvedAccountErrors\(\);/);
    // Both input watchers prune; neither blanket-wipes account errors.
    assert.equal((product.match(/pruneResolvedAccountErrors\(\);/g) || []).length, 2);
});

test('deep-linked nominal pre-selection does not bypass the account gate', () => {
    const product = read('Pages/Catalog/Show.vue');
    assert.match(product, /if \(nominalLocked\.value\) selectedPackageId\.value = '';/);
});

test('PR2 WDP panel uses exact locked text and is_wdp flag without inventing behavior', () => {
    const product = read('Pages/Catalog/Show.vue');
    
    // Panel ditrigger oleh is_wdp flag dari backend
    assert.match(product, /pendingWdpPackage\.value = item;/);
    assert.match(product, /wdpInfoOpen\.value = true;/);
    
    // Judul panel terkunci
    assert.match(product, /<h2 id="wdp-info-title">Sebelum Beli Weekly Diamond Pass<\/h2>/);
    
    // Isi panel terkunci (teks lengkap dalam satu string)
    assert.match(product, /Weekly Diamond Pass ada batas pembelian dari Moonton, ngikutin kuota di akun kamu\. Cek dulu di dalam game: buka logo diamond, pilih Weekly Diamond Pass — angka kecil di samping sisa hari itu sisa kuota kamu\. Contoh \(2\/10\) artinya masih bisa beli 2x lagi\. Kalau \(0\/10\), jangan checkout dulu karena WDP barunya bisa gagal masuk\./);
    
    // Kedua tombol dengan label terkunci
    assert.match(product, /<button type="button" @click="cancelWdpSelection">Cek Nanti<\/button>/);
    assert.match(product, /<button type="button" class="lfx-primary" @click="confirmWdpSelection">Sudah Saya Cek, Lanjut<\/button>/);
    
    // Perilaku "Cek Nanti" membatalkan pilihan
    assert.match(product, /function cancelWdpSelection\(\) \{[\s\S]*?closeWdpInfo\(\);[\s\S]*?\}/);
    assert.match(product, /function closeWdpInfo\(\) \{[\s\S]*?wdpInfoOpen\.value = false;[\s\S]*?pendingWdpPackage\.value = null;[\s\S]*?\}/);
    
    // Perilaku "Sudah Saya Cek, Lanjut" mempertahankan pilihan dan scroll ke pembayaran
    assert.match(product, /function confirmWdpSelection\(\) \{[\s\S]*?selectedPackageId\.value = String\(item\.id\);[\s\S]*?scrollToPayment\(\);[\s\S]*?\}/);
    
    // Escape juga membatalkan (lewat closeWdpInfo di onDialogKey)
    assert.match(product, /closeWdpInfo\(\);/);
    
    // Panel muncul saat memilih WDP (currentIsWdp -> targetIsWdp transition)
    assert.match(product, /const currentIsWdp = selectedPackage\.value\?\.is_wdp === true;/);
    assert.match(product, /const targetIsWdp = item\.is_wdp === true;/);
    assert.match(product, /if \(targetIsWdp && \(!currentIsWdp \|\| String\(selectedPackageId\.value\) !== String\(item\.id\)\)\) \{/);
    
    // TEMUAN 1: Deep link WDP diarahkan ke panel, tidak langsung terpilih
    assert.match(product, /if \(selectedPackageId\.value\) \{[\s\S]*?const seededPackage = \(props\.packages \|\| \[\]\)\.find\(item => String\(item\.id\) === String\(selectedPackageId\.value\)\);[\s\S]*?if \(seededPackage\?\.is_wdp === true\) \{[\s\S]*?pendingWdpPackage\.value = seededPackage;[\s\S]*?wdpInfoOpen\.value = true;[\s\S]*?selectedPackageId\.value = '';[\s\S]*?\}[\s\S]*?\}/);
    
    // TEMUAN 2: confirmWdpSelection mengecek ulang packageDisabled dan is_available
    assert.match(product, /function confirmWdpSelection\(\) \{[\s\S]*?if \(item\.is_available === false \|\| packageDisabled\(item\)\) \{[\s\S]*?closeWdpInfo\(\);[\s\S]*?return;[\s\S]*?\}/);

    // Panel WDP yang terbuka membatalkan penyelesaian prepareOrder (tidak ada dua dialog bersamaan),
    // dan status checkout yang berubah selama persiapan membuat snapshot basi dan dibatalkan
    assert.match(product, /const preparedStateSignature = confirmationStateSignature\(\);/);
    assert.match(product, /if \(wdpInfoOpen\.value \|\| confirmationStateSignature\(\) !== preparedStateSignature\) return;/);
});

test('PR3 product page splits Keterangan into three panels with hardcoded two-layer FAQ', () => {
    const product = read('Pages/Catalog/Show.vue');
    const controller = readFileSync(new URL('../../app/Http/Controllers/CatalogController.php', import.meta.url), 'utf8');
    const faq = readFileSync(new URL('../../app/Support/ProductFaq.php', import.meta.url), 'utf8');

    // (a) tiga panel terpisah, tanpa nomor langkah
    assert.match(product, /lfx-panel-description"><header class="lfx-panel-header lfx-panel-header-plain"><div><h2>Deskripsi \{\{product\.name\}\}<\/h2>/);
    assert.equal((product.match(/Deskripsi \{\{product\.name\}\}/g) || []).length, 1);
    assert.match(product, /lfx-panel-reviews"><header class="lfx-panel-header lfx-panel-header-plain"><div><h2>\{\{ customerText\("pages\.catalog\.show\.d4d282c6", "Ulasan & rating"\) \}\}<\/h2>/);
    assert.equal((product.match(/d4d282c6/g) || []).length, 1);
    assert.match(product, /lfx-panel-faq"><header class="lfx-panel-header lfx-panel-header-plain"><div><h2>\{\{ customerText\("pages\.catalog\.show\.cc3c98ca", "Pertanyaan umum"\) \}\}<\/h2>/);
    assert.doesNotMatch(product, /lf-product-details"/);

    // (b) string FAQ terkunci
    assert.ok(faq.includes("'Apa batas pembelian Weekly Diamond Pass?'"));
    assert.ok(faq.includes("'Apakah saya bisa meminta pengembalian dana (refund)?'"));
    assert.ok(faq.includes("'Bagaimana cara memakai kode voucher?', 'answer' => 'Masukkan kode di kolom voucher diskon sebelum membayar, tekan gunakan, dan potongan harganya langsung tampil di ringkasan pesanan.'"));
    assert.ok(faq.includes('sesuai yang sedang aktif'));
    assert.ok(faq.includes('invoice pesanan dikirim ke email kamu'));
    assert.ok(faq.includes('nomor invoice atau nomor WhatsApp yang kamu pakai saat checkout'));
    assert.ok(!faq.includes('kode akses'));
    assert.ok(faq.includes('Diproses manual oleh admin toko setelah pembayaran terkonfirmasi'));
    assert.ok(!faq.includes('dikirim ke keduanya'));
    assert.ok(faq.includes('bila kegagalannya sudah terkonfirmasi, memproses pengiriman ulang'));
    assert.ok(faq.includes('setelah kegagalannya terkonfirmasi, dukungan memproses pengiriman ulang'));
    assert.equal((faq.match(/'question' =>/g) || []).length, 17);

    // (c) tidak lagi memakai prop faqs dari database
    assert.doesNotMatch(product, /faqs\.slice/);
    assert.doesNotMatch(product, /^\s*faqs: Array/m);
    assert.match(product, /v-for="\(faq, index\) in productFaqs"/);
    assert.doesNotMatch(product, /slice\(0,4\)/);

    // (d) controller memakai ProductFaq
    assert.ok(controller.includes('ProductFaq::forProduct($product)'));
    assert.doesNotMatch(controller, /'faqs' =>/);
});

test('cashback coin pill sits right of the price, only when cashback_coin > 0, and DIPILIH stays', () => {
    const product = read('Pages/Catalog/Show.vue');
    const css = readFileSync(new URL('../../resources/css/checkout.css', import.meta.url), 'utf8');
    const controller = readFileSync(new URL('../../app/Http/Controllers/CatalogController.php', import.meta.url), 'utf8');
    const bottom = product.match(/<span class="lfx-nominal-bottom">[\s\S]*?DIPILIH<\/small><\/span><\/span>/);
    assert.ok(bottom, 'baris harga kartu nominal tidak ditemukan');
    const row = bottom[0];

    // Urutan posisi B: harga <b>, lalu pill, lalu DIPILIH di paling kanan.
    assert.ok(row.indexOf('formatIdr(item.price_idr)') < row.indexOf('lfx-coin-pill'));
    assert.ok(row.indexOf('lfx-coin-pill') < row.indexOf('DIPILIH'));

    // Pill hanya dirender bila backend memberi cashback_coin > 0 (dan harga tampil).
    assert.match(row, /<\/b><span class="lfx-nominal-tags"><span v-if="item\.cashback_coin>0&&item\.is_available!==false&&!packageDisabled\(item\)" class="lfx-coin-pill">\+\{\{item\.cashback_coin\}\}<span class="lfx-coin-unit"> Coin<\/span><\/span>/);
    assert.equal((product.match(/class="lfx-coin-pill"/g) || []).length, 1);

    // Teks DIPILIH tetap ada saat kartu terpilih.
    assert.match(row, /<small v-if="String\(selectedPackageId\)===String\(item\.id\)">DIPILIH<\/small><\/span><\/span>$/);

    // Saat terpilih, kata "Coin" disembunyikan visual saja (pill menyingkat jadi "+{n}").
    assert.match(css, /\.lfx \.lfx-nominal\.selected \.lfx-coin-unit\{position:absolute;width:1px;height:1px;[^}]*clip:rect\(0 0 0 0\)/);
    assert.match(css, /\.lfx \.lfx-coin-pill\{[^}]*flex:0 0 auto;[^}]*background:rgba\(16,185,129,\.16\);color:#34d399;/);
    // Grup pill + DIPILIH rata kanan; angka coin tidak dipotong, baris boleh turun.
    assert.match(css, /\.lfx \.lfx-nominal-tags\{margin-left:auto;[^}]*flex:0 0 auto\}/);
    assert.match(css, /\.lfx \.lfx-nominal-bottom\{flex-wrap:wrap;row-gap:2px;padding-block:4px\}/);
    assert.doesNotMatch(css, /\.lfx \.lfx-coin-pill\{[^}]*overflow:hidden/);

    // Angka lencana memakai aturan earn yang sama di backend.
    assert.ok(controller.includes("'cashback_coin' => CashbackCoinService::coinsForAmount((int) ($price ?? 0)),"));
});

// ---- PR #406: podium leaderboard (keadaan kosong, 1/2/3 entri, daftar sisa) ----
// Ekspresi computed diambil dari sumber Leaderboard.vue lalu dijalankan dengan data
// contoh, jadi yang diuji adalah perilaku nyata, bukan sekadar teks kelas.
const leaderboardSource = () => read('Pages/Content/Leaderboard.vue');
const leaderboardTemplate = () => leaderboardSource().split('<template>')[1] || '';
const lbEntries = n => Array.from({ length: n }, (_, i) => ({ rank: i + 1, name: 'Alias ' + (i + 1), total_spent: (n - i) * 1000, order_count: n - i }));
const lbComputed = name => {
    const match = leaderboardSource().match(new RegExp('const ' + name + ' = computed\\(\\(\\) => (.+)\\);\\n'));
    assert.ok(match, 'computed ' + name + ' tidak ditemukan di Leaderboard.vue');
    return match[1];
};
const lbState = entries => {
    const props = { entries };
    const podium = { value: new Function('props', 'return ' + lbComputed('podium'))(props) };
    const rest = { value: new Function('props', 'return ' + lbComputed('rest'))(props) };
    return { podium: podium.value, rest: rest.value, entries };
};
const lbCss = () => readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const lbCssRule = selector => {
    const css = lbCss();
    const prefix = selector + ':where(:not(.lfx, .lfx *)){';
    const lines = css.split('\n').filter(line => line.startsWith(prefix));
    assert.equal(lines.length, 1, 'aturan dasar ' + selector + ' harus tepat satu');
    const body = lines[0].slice(prefix.length, lines[0].lastIndexOf('}'));
    return Object.fromEntries(body.split(';').filter(Boolean).map(decl => {
        const i = decl.indexOf(':');
        return [decl.slice(0, i).trim(), decl.slice(i + 1).trim()];
    }));
};

test('PR406 podium: empty state hides the podium and keeps the empty block for 0 entries', () => {
    const template = leaderboardTemplate();
    assert.match(template, /<ol v-if="podium\.length" class="lf-podium">/);
    assert.equal((template.match(/class="lf-podium"/g) || []).length, 1);
    const rankingIf = template.match(/<div v-if="([^"]+)" class="lf-ranking">/);
    assert.ok(rankingIf, 'blok .lf-ranking dengan v-if tidak ditemukan');
    assert.match(template, /<div v-if="!entries\?\.length" class="lf-empty">\{\{ customerText\("pages\.content\.leaderboard\.8aacad7d", "Belum ada peringkat\."\) \}\}<\/div>/);
    // v-if memakai truthiness, jadi hasil ekspresi dinormalisasi ke boolean.
    const showRanking = state => Boolean(new Function('rest', 'entries', 'return ' + rankingIf[1])(state.rest, state.entries));

    for (const entries of [[], undefined, null]) {
        const state = lbState(entries);
        assert.equal(state.podium.length, 0, 'podium harus kosong untuk ' + JSON.stringify(entries));
        assert.equal(state.rest.length, 0);
        assert.equal(showRanking(state), true, 'blok kosong harus tampil untuk ' + JSON.stringify(entries));
    }
    // 1-3 entri: hanya podium, tanpa wadah daftar dan tanpa pesan kosong.
    for (const n of [1, 2, 3]) assert.equal(showRanking(lbState(lbEntries(n))), false, n + ' entri tidak boleh merender .lf-ranking');
    // >3 entri: wadah daftar tampil untuk sisa peringkat.
    assert.equal(showRanking(lbState(lbEntries(5))), true);
});

test('PR406 podium: podium takes only rank <= 3 and rest only rank > 3, as separate computeds', () => {
    assert.equal(lbComputed('podium'), '(props.entries || []).filter(e => e.rank <= 3)');
    assert.equal(lbComputed('rest'), '(props.entries || []).filter(e => e.rank > 3)');
    for (const n of [1, 2, 3, 4, 20]) {
        const state = lbState(lbEntries(n));
        assert.deepEqual(state.podium.map(e => e.rank), [1, 2, 3].slice(0, Math.min(n, 3)), 'podium untuk ' + n + ' entri');
        assert.deepEqual(state.rest.map(e => e.rank), Array.from({ length: Math.max(n - 3, 0) }, (_, i) => i + 4), 'rest untuk ' + n + ' entri');
    }
    // Daftar sisa hanya mengulang `rest`, tidak pernah seluruh entries.
    const template = leaderboardTemplate();
    assert.match(template, /<article v-for="e in rest" :key="e\.rank">/);
    assert.doesNotMatch(template, /v-for="e in entries"/);
});

test('PR406 podium: no count-based modifier (spec: fixed 3-slot podium, owner decision 19 Nov 2026)', () => {
    // Pengubah kolom per jumlah entri (is-count-*) sengaja ditolak: kerangka podium selalu 3 slot.
    assert.doesNotMatch(lbCss(), /is-count-/);
    const source = leaderboardSource();
    assert.doesNotMatch(source, /podiumClass/);
    assert.doesNotMatch(source, /is-count-/);
    // Elemen podium hanya memakai kelas statis, tanpa binding :class.
    const podiumTag = leaderboardTemplate().match(/<ol\b[^>]*\bclass="lf-podium"[^>]*>/);
    assert.ok(podiumTag, 'elemen <ol class="lf-podium"> tidak ditemukan');
    assert.doesNotMatch(podiumTag[0], /:class=|v-bind:class=/);
});

test('PR406 podium: v-for keeps data order (rank ascending) with one is-rank-<rank> class per entry', () => {
    const source = leaderboardSource();
    const template = leaderboardTemplate();
    assert.match(template, /<li v-for="e in podium" :key="e\.rank" :class="\['lf-podium-col', 'is-rank-' \+ e\.rank\]">/);
    assert.doesNotMatch(source, /\.sort\(|\.reverse\(|toSorted|toReversed/);
    const classOf = new Function('e', 'return ' + template.match(/<li v-for="e in podium" :key="e\.rank" :class="([^"]+)">/)[1]);
    for (const n of [1, 2, 3]) {
        const classes = lbState(lbEntries(n)).podium.map(e => classOf(e).join(' '));
        assert.deepEqual(classes, ['lf-podium-col is-rank-1', 'lf-podium-col is-rank-2', 'lf-podium-col is-rank-3'].slice(0, n));
    }
});

test('PR406 podium: fixed 3-column grid; 1 entry = centre only, 2 entries = left + centre with right empty, 3 entries = 2-1-3', () => {
    const base = lbCssRule('.lf-podium');
    assert.equal(base['grid-template-columns'], 'repeat(3,minmax(0,1fr))');
    assert.equal(lbCssRule('.lf-podium-col.is-rank-1')['grid-column'], '2');
    assert.equal(lbCssRule('.lf-podium-col.is-rank-2')['grid-column'], '1');
    assert.equal(lbCssRule('.lf-podium-col.is-rank-3')['grid-column'], '3');

    // Simulasi penempatan dari state nyata: peringkat yang ter-render (hasil computed podium)
    // diletakkan di kolom CSS-nya; grid selalu 3 kolom, slot tanpa entri tetap kosong.
    const cols = Number(base['grid-template-columns'].match(/^repeat\((\d+),/)[1]);
    const placement = n => {
        const rendered = lbState(lbEntries(n)).podium.map(e => e.rank);
        const visual = Array(cols).fill(null);
        for (const rank of rendered) {
            const col = Number(lbCssRule('.lf-podium-col.is-rank-' + rank)['grid-column']);
            assert.ok(col >= 1 && col <= cols, 'peringkat ' + rank + ' keluar dari grid ' + cols + ' kolom (kolom ' + col + ')');
            assert.equal(visual[col - 1], null, 'dua peringkat berbagi kolom ' + col);
            visual[col - 1] = rank;
        }
        return { cols, rendered, visual };
    };
    // 1 entri: hanya rank 1, di kolom tengah.
    assert.deepEqual(placement(1), { cols: 3, rendered: [1], visual: [null, 1, null] });
    // 2 entri: rank 2 kiri, rank 1 tengah, kolom kanan kosong, tidak ada elemen rank 3.
    assert.deepEqual(placement(2), { cols: 3, rendered: [1, 2], visual: [2, 1, null] });
    // 3 entri: visual 2-1-3.
    assert.deepEqual(placement(3), { cols: 3, rendered: [1, 2, 3], visual: [2, 1, 3] });

    // Media query hanya mengubah ukuran, bukan kolom atau penempatan.
    const css = lbCss();
    const media = css.split('\n').find(line => line.startsWith('@media(min-width:768px){.lf-podium:'));
    assert.ok(media, 'media query podium tidak ditemukan');
    assert.doesNotMatch(media, /grid-template-columns|grid-column/);
});

test('PR406 podium: crown only for rank 1 and no medal emoji in the template', () => {
    const source = leaderboardSource();
    const template = leaderboardTemplate();
    assert.match(template, /<svg v-if="e\.rank === 1" class="lf-podium-crown" viewBox="0 0 24 16" aria-hidden="true">/);
    assert.equal((template.match(/lf-podium-crown/g) || []).length, 1);
    assert.doesNotMatch(source, /🥇|🥈|🥉/u);
    const crownIf = new Function('e', 'return ' + template.match(/<svg v-if="([^"]+)" class="lf-podium-crown"/)[1]);
    assert.deepEqual(lbEntries(3).map(e => crownIf(e)), [true, false, false]);
});
