import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';

const base = process.env.CHECKOUT_STORE_URL || 'http://127.0.0.1:8000';
const output = 'wallet-payment-parity-results';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({
    executablePath: process.argv[2],
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
    headless: true,
});

const difference = (a, b) => Math.abs(a - b);
function near(a, b, label) {
    if (difference(a, b) > 1) throw Error(label + ': ' + JSON.stringify({ wallet: a, checkout: b }));
}
const capture = async (page, label) => page.screenshot({ path: output + '/' + label + '.png', fullPage: true });
const geometry = async (page, title) => page.evaluate(name => {
    const groups = [...document.querySelectorAll('.lf-payment-group-card')];
    const group = groups.find(el => el.querySelector('.lf-payment-group-copy strong')?.textContent.trim() === name);
    if (!group) return null;
    const rect = el => {
        const r = el.getBoundingClientRect();
        return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
    };
    const css = el => {
        if (!el) return null;
        const style = getComputedStyle(el);
        return {
            padding: style.padding,
            border: style.borderWidth,
            borderStyle: style.borderStyle,
            maxHeight: style.maxHeight,
            objectFit: style.objectFit,
            minHeight: style.minHeight,
            gap: style.gap,
            boxSizing: style.boxSizing,
        };
    };
    const header = group.querySelector('.lf-payment-group-main');
    const titleNode = group.querySelector('.lf-payment-group-copy strong');
    const description = group.querySelector('.lf-payment-group-copy small');
    const logo = group.querySelector('.lf-payment-brand-logo');
    const image = logo?.querySelector('img');
    const grid = group.querySelector('.lfx-payment-method-grid');
    const first = grid?.querySelector('.lfx-payment-method-card');
    const inner = rect(group);
    const headerBox = rect(header);
    const titleBox = rect(titleNode);
    return {
        header: { height: headerBox.height, bottom: headerBox.bottom },
        titleX: titleBox.x - inner.x,
        descriptionBottom: description ? rect(description).bottom : null,
        logo: logo ? { ...rect(logo), style: css(logo) } : null,
        image: image ? { ...rect(image), style: css(image) } : null,
        expanded: Boolean(grid),
        compact: Boolean(grid?.classList.contains('lfx-payment-method-grid--compact')),
        columns: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : null,
        selected: group.classList.contains('selected'),
        firstCardSelected: Boolean(first?.classList.contains('is-selected')),
        firstCard: first ? { ...rect(first), style: css(first) } : null,
    };
}, title);

const assertParity = (wallet, checkout, label) => {
    if (!wallet || !checkout) throw Error('Missing shared payment group ' + label);
    near(wallet.titleX, checkout.titleX, label + ' title x relative to card');
    near(wallet.header.height, checkout.header.height, label + ' header height');
    if (wallet.descriptionBottom != null && wallet.descriptionBottom > wallet.header.bottom + 1) {
        throw Error(label + ' wallet description overflows its header: ' + JSON.stringify(wallet));
    }
    if (checkout.descriptionBottom != null && checkout.descriptionBottom > checkout.header.bottom + 1) {
        throw Error(label + ' checkout description overflows its header: ' + JSON.stringify(checkout));
    }
    if (wallet.selected !== checkout.selected || wallet.expanded !== checkout.expanded) {
        throw Error(label + ' selection/expansion mismatch: ' + JSON.stringify({ wallet, checkout }));
    }
    if (wallet.logo || checkout.logo) {
        if (!wallet.logo || !checkout.logo || !wallet.image || !checkout.image) throw Error(label + ' missing logo strip');
        for (const key of ['width', 'height']) {
            near(wallet.logo[key], checkout.logo[key], label + ' logo ' + key);
            near(wallet.image[key], checkout.image[key], label + ' logo image ' + key);
        }
        for (const key of ['padding', 'border', 'borderStyle']) {
            if (wallet.logo.style[key] !== checkout.logo.style[key]) throw Error(label + ' logo ' + key + ' differs');
        }
        if (wallet.image.style.maxHeight !== checkout.image.style.maxHeight ||
            wallet.image.style.objectFit !== checkout.image.style.objectFit) {
            throw Error(label + ' logo image styling differs');
        }
    }
    if (wallet.expanded) {
        if (wallet.compact !== checkout.compact || wallet.columns !== checkout.columns ||
            wallet.firstCardSelected !== checkout.firstCardSelected) {
            throw Error(label + ' expanded channel grid differs: ' + JSON.stringify({ wallet, checkout }));
        }
        if (wallet.firstCard && checkout.firstCard) {
            // Card content is data-dependent (different channel names/prices may wrap).
            // Match CSS geometry, not the auto-height of two different contents.
            for (const key of ['padding', 'border', 'borderStyle', 'minHeight', 'gap', 'boxSizing']) {
                if (wallet.firstCard.style[key] !== checkout.firstCard.style[key]) {
                    throw Error(label + ' channel card ' + key + ' differs: ' + JSON.stringify({
                        wallet: wallet.firstCard.style[key], checkout: checkout.firstCard.style[key],
                    }));
                }
            }
        }
    }
};

try {
    const report = [];
    for (const width of [390, 768, 1440]) {
        const viewport = { width, height: width === 390 ? 844 : 900 };
        const wallet = await browser.newPage({ viewport });
        const checkout = await browser.newPage({ viewport });
        await wallet.goto(base + '/login', { waitUntil: 'networkidle' });
        const login = await wallet.evaluate(async () => {
            const response = await fetch('/login', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ email: 'browser-account@example.test', password: 'Browser-test-password-123' }),
            });
            return response.status;
        });
        if (login < 200 || login >= 300) throw Error('Customer fixture login failed: ' + login);
        await wallet.goto(base + '/account/wallet', { waitUntil: 'networkidle' });
        await checkout.goto(base + '/catalog/browser-checkout-game', { waitUntil: 'networkidle' });
        await wallet.locator('.lf-payment-group-main').first().waitFor();
        await checkout.locator('.lf-payment-group-main').first().waitFor();

        const titles = await wallet.locator('.lf-payment-group-copy strong').allTextContents();
        if (titles.length < 2) throw Error('Wallet lacks payment groups ' + JSON.stringify(titles));
        const shared = [];
        const checkoutTitles = await checkout.locator('.lf-payment-group-copy strong').allTextContents();
        for (const title of titles.map(s => s.trim())) {
            if (!checkoutTitles.map(s => s.trim()).includes(title)) continue;
            shared.push(title);
        }
        if (shared.length < 2) throw Error('No shared Wallet/Checkout channels');
        // Both pages start on the same component; compare every available group.
        const initial = [];
        for (const title of shared) {
            const w = await geometry(wallet, title);
            const c = await geometry(checkout, title);
            // Initial selections can differ because checkout auto-selects a method.
            if (!w?.selected && !c?.selected) assertParity(w, c, width + ' ' + title + ' closed');
            else {
                near(w.titleX, c.titleX, width + ' ' + title + ' initial heading');
                near(w.header.height, c.header.height, width + ' ' + title + ' initial header');
            }
            initial.push({ title, wallet: w, checkout: c });
        }
        await capture(wallet, 'wallet-' + width);
        await capture(checkout, 'checkout-' + width);

        for (const title of shared) {
            await wallet.locator('.lf-payment-group-card').filter({ hasText: title }).first().locator('.lf-payment-group-main').click();
            await checkout.locator('.lf-payment-group-card').filter({ hasText: title }).first().locator('.lf-payment-group-main').click();
            const w = await geometry(wallet, title);
            const c = await geometry(checkout, title);
            assertParity(w, c, width + ' ' + title + ' selected');
            if (!w.selected) throw Error('Wallet failed to select group ' + title);
        }
        await capture(wallet, 'wallet-expanded-' + width);
        await capture(checkout, 'checkout-expanded-' + width);

        // Make a quote request remain pending: busy must lock the shared header and radios.
        // The request is intercepted; no payment/top-up is ever created.
        if (width === 390) {
            let releaseQuote;
            await wallet.route('**/account/wallet/topups/quote', async route => {
                await new Promise(resolve => { releaseQuote = resolve; });
                await route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"Unavailable"}' });
            });
            await wallet.getByRole('button', { name: 'Hitung total' }).click();
            await wallet.waitForFunction(() => [...document.querySelectorAll('[aria-label="Pilih metode pembayaran top up"] .lf-payment-group-main')]
                .length > 0 && [...document.querySelectorAll('[aria-label="Pilih metode pembayaran top up"] .lf-payment-group-main')]
                .every(button => button.disabled));
            const locked = await wallet.evaluate(() => ({
                headers: [...document.querySelectorAll('[aria-label="Pilih metode pembayaran top up"] .lf-payment-group-main')]
                    .every(button => button.disabled),
                radios: [...document.querySelectorAll('[aria-label="Pilih metode pembayaran top up"] .lfx-payment-method-card')]
                    .every(radio => radio.getAttribute('aria-disabled') === 'true' && radio.tabIndex === -1),
            }));
            if (!locked.headers || !locked.radios) throw Error('Shared picker not disabled during wallet busy state: ' + JSON.stringify(locked));
            if (releaseQuote) releaseQuote();
            await wallet.waitForFunction(() => [...document.querySelectorAll('[aria-label="Pilih metode pembayaran top up"] .lf-payment-group-main')]
                .some(button => !button.disabled));
            await wallet.unroute('**/account/wallet/topups/quote');
        }


        // PR11: real browser interaction, with every quote/top-up POST intercepted.
        // Selecting another group invalidates the previous channel quote.
        const quoteRequests = [];
        await wallet.route('**/account/wallet/topups/quote', async route => {
            const request = route.request().postDataJSON();
            quoteRequests.push(request);
            await route.fulfill({
                status: 200, contentType: 'application/json',
                body: JSON.stringify({
                    amount_idr: request.amount_idr,
                    payment_channel_code: request.payment_channel_code,
                    total_idr: request.amount_idr + 500,
                }),
            });
        });
        const calculate = wallet.getByRole('button', { name: 'Hitung total' });
        await calculate.click();
        await wallet.getByText('Biaya pembayaran').waitFor();
        if (quoteRequests.length !== 1) throw Error('Initial wallet quote was not requested');
        const formattedTotal = 'Rp ' + Number(quoteRequests[0].amount_idr + 500).toLocaleString('id-ID');
        const pickerTotal = await wallet.locator('.lf-payment-group-card.selected .lfx-payment-amount').first().textContent();
        if (pickerTotal?.trim() !== formattedTotal)
            throw Error('Wallet quote total not shown in selected Checkout channel: ' + JSON.stringify({ pickerTotal, formattedTotal }));

        const nextTitle = shared.find(title => title !== shared[shared.length - 1]);
        if (!nextTitle) throw Error('No alternate wallet payment group to test invalidation');
        await wallet.locator('.lf-payment-group-card').filter({ hasText: nextTitle }).first()
            .locator('.lf-payment-group-main').click();
        await wallet.getByText('Biaya pembayaran').waitFor({ state: 'detached' });
        if (!(await wallet.getByRole('button', { name: 'Top up', exact: true }).isDisabled()))
            throw Error('Changing payment channel must invalidate the old quote');
        await calculate.click();
        await wallet.getByText('Biaya pembayaran').waitFor();
        if (quoteRequests.length !== 2 || quoteRequests[0].payment_channel_code === quoteRequests[1].payment_channel_code)
            throw Error('Wallet must quote the newly selected payment channel');

        // recovery reuses original top-up idempotency key
        // 422 mimics an uncertain response; no POST reaches the financial backend.
        const attemptedKeys = [];
        await wallet.route('**/account/wallet/topups', async route => {
            attemptedKeys.push(route.request().postDataJSON().idempotency_key);
            await route.fulfill({
                status: 422, contentType: 'application/json',
                body: JSON.stringify({ message: 'Status top up perlu diperiksa.' }),
            });
        });
        const initialAttempt = wallet.waitForResponse(response =>
            response.url().endsWith('/account/wallet/topups') && response.request().method() === 'POST');
        await wallet.getByRole('button', { name: 'Top up', exact: true }).click();
        await initialAttempt;
        await wallet.getByText('Top up sebelumnya masih diproses.', { exact: false }).waitFor();
        const lockedState = await wallet.evaluate(() => {
            const headers = [...document.querySelectorAll('[aria-label="Pilih metode pembayaran top up"] .lf-payment-group-main')];
            const radios = [...document.querySelectorAll('[aria-label="Pilih metode pembayaran top up"] .lfx-payment-method-card')];
            return { headers: headers.length > 0 && headers.every(header => header.disabled),
                radios: radios.every(radio => radio.getAttribute('aria-disabled') === 'true' && radio.tabIndex === -1) };
        });
        if (!lockedState.headers || !lockedState.radios) throw Error('Wallet picker must stay locked during uncertain top-up');
        if (!(await wallet.locator('input[type=number]').isDisabled()) || !(await calculate.isDisabled()))
            throw Error('Wallet amount and quote calculator must remain locked');
        // A synthetic click on a locked radio must never change the selected channel.
        const selectedBefore = await wallet.locator('.lf-payment-group-card.selected .lf-payment-group-copy strong').first().textContent();
        await wallet.locator('.lfx-payment-method-card:not(.is-selected)').first().evaluate(element => element.click()).catch(() => {});
        const selectedAfter = await wallet.locator('.lf-payment-group-card.selected .lf-payment-group-copy strong').first().textContent();
        if (selectedBefore !== selectedAfter || quoteRequests.length !== 2)
            throw Error('Locked picker changed payment channel or requested a fresh quote');
        const retry = wallet.getByRole('button', { name: 'Cek status top up' });
        if (!(await retry.isEnabled())) throw Error('Status recovery action disabled while attempt is locked');
        const recoveryResponse = wallet.waitForResponse(response =>
            response.url().endsWith('/account/wallet/topups') && response.request().method() === 'POST');
        await retry.click();
        await recoveryResponse;
        if (attemptedKeys.length !== 2 || !attemptedKeys[0] || attemptedKeys[0] !== attemptedKeys[1])
            throw Error('Status recovery did not reuse original top-up idempotency key: ' + JSON.stringify(attemptedKeys));
        await wallet.unroute('**/account/wallet/topups');
        await wallet.unroute('**/account/wallet/topups/quote');
        console.log('PASS PR11 wallet quote invalidation, channel total, locks and idempotent recovery ' + width);

        // Never create top ups or execute a payment in browser tests.
        report.push({ width, sharedGroups: shared, initial, financialActions: false });
        await wallet.close();
        await checkout.close();
        console.log('PASS wallet-checkout payment parity ' + width + 'px');
    }
    await writeFile(output + '/report.json', JSON.stringify(report, null, 2));
} finally {
    await browser.close();
}
