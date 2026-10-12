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

test('nominal cards keep equal row height with long names and optional artwork', () => {
    const product = read('Pages/Catalog/Show.vue');
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const section = css.split('/* Product nominal cards: uniform rows regardless of label length or package artwork. */')[1];

    assert.ok(section, 'uniform card layout rules must exist');
    assert.match(product, /class="lf-nominal-grid"/);
    assert.match(product, /class="lf-nominal-top"/);
    assert.match(product, /loading="lazy" decoding="async"/);
    assert.match(section, /grid-auto-rows:128px!important/);
    assert.match(section, /height:128px!important/);
    assert.match(section, /display:flex!important/);
    assert.match(section, /flex-direction:column!important/);
    assert.match(section, /justify-content:space-between!important/);
    assert.match(section, /-webkit-line-clamp:4/);
    assert.match(section, /width:38px!important/);
    assert.match(section, /height:38px!important/);
    assert.match(section, /object-fit:contain!important/);
});

test('V2 checkout quote remains server-authoritative and payment section scrolls smoothly', () => {
    const source = read('Pages/Catalog/Show.vue');
    assert.match(source, /const canQuote = computed\(\(\) => Boolean\(selectedPackage\.value && selectedPaymentChannel\.value\)\)/);
    assert.match(source, /await requestJson\('\/checkout\/quote'/);
    assert.match(source, /quoteStatusText = computed/);
    assert.match(source, /quoteError\.value = error\.message/);
    assert.match(source, /scrollIntoView\(\{/);
    assert.match(source, /behavior: 'smooth'/);
    assert.match(source, /displayTotal = computed\(\(\) => quote\.value\?\.total_idr \?\? null\)/);
});
