import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

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
