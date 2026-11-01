import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

// Uses the ephemeral browser fixture; never creates a financial transaction.
const base = 'http://127.0.0.1:8000';
const output = 'checkout-visual-results';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({
    executablePath: process.argv[2],
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
    headless: true,
});

const inspect = page => page.locator('.lf-payment-group-card').evaluateAll(cards => cards.map(card => {
    const header = card.querySelector('.lf-payment-group-main');
    const title = header.querySelector('.lf-payment-group-copy strong');
    const description = header.querySelector('.lf-payment-group-copy small');
    const strip = card.querySelector('.lf-payment-brand-strip--logos');
    const logo = strip?.querySelector('.lf-payment-brand-logo');
    const image = logo?.querySelector('img');
    const grid = card.querySelector('.lfx-payment-method-grid');
    const bounds = element => {
        const rect = element.getBoundingClientRect();
        return { x: rect.x, width: rect.width, height: rect.height, bottom: rect.bottom };
    };
    const headerRect = bounds(header);
    const titleRect = bounds(title);
    const descriptionRect = description ? bounds(description) : null;
    const logoStyle = logo ? getComputedStyle(logo) : null;
    const imageStyle = image ? getComputedStyle(image) : null;
    const gridStyle = grid ? getComputedStyle(grid) : null;
    return {
        title: title.textContent.trim(),
        disabled: header.disabled,
        selected: card.classList.contains('selected'),
        titleInset: titleRect.x - headerRect.x,
        headerHeight: headerRect.height,
        descriptionInside: !descriptionRect || descriptionRect.bottom <= headerRect.bottom + 1,
        chip: logo ? {
            width: bounds(logo).width, height: bounds(logo).height,
            padding: logoStyle.padding, border: logoStyle.borderWidth, ratio: logoStyle.aspectRatio,
            imageWidth: bounds(image).width, imageHeight: bounds(image).height,
            imageMaxHeight: imageStyle.maxHeight, imageFit: imageStyle.objectFit,
        } : null,
        grid: grid ? {
            count: grid.querySelectorAll('.lfx-payment-method-card').length,
            columns: gridStyle.gridTemplateColumns.split(' ').length,
            padding: gridStyle.padding, gap: gridStyle.gap,
            selected: grid.querySelectorAll('[role=radio][aria-checked=true]').length,
        } : null,
    };
}));

const near = (a, b, hint) => assert.ok(Math.abs(a - b) <= 1, hint + ': ' + a + ' vs ' + b);
try {
    for (const width of [390, 768, 1440]) {
        const viewport = { width, height: width === 390 ? 844 : width === 768 ? 1024 : 900 };
        const checkout = await browser.newPage({ viewport });
        const wallet = await browser.newPage({ viewport });
        try {
            await checkout.goto(base + '/catalog/browser-checkout-game', { waitUntil: 'networkidle' });
            await wallet.goto(base + '/login', { waitUntil: 'domcontentloaded' });
            const loginStatus = await wallet.evaluate(async () => {
                const response = await fetch('/login', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ email: 'browser-account@example.test', password: 'Browser-test-password-123' }),
                });
                return response.status;
            });
            assert.ok([200, 204].includes(loginStatus), 'Fixture customer login failed: ' + loginStatus);
            await wallet.goto(base + '/account/wallet', { waitUntil: 'networkidle' });
            await wallet.locator('.lf-payment-group-main').first().waitFor();
            const baseline = await inspect(checkout);
            const candidates = (await inspect(wallet))
                .filter(row => !row.disabled && baseline.some(item => item.title === row.title && !item.disabled))
                .map(row => row.title);
            assert.ok(candidates.length >= 2, 'At least two matching wallet/checkout groups required: ' + width);

            // Changing groups measures each group both open/selected and closed.
            for (const title of candidates) {
                await checkout.locator('.lf-payment-group-main').filter({ hasText: title }).first().click();
                await wallet.locator('.lf-payment-group-main').filter({ hasText: title }).first().click();
                const expected = await inspect(checkout);
                const actual = await inspect(wallet);
                for (const item of expected) {
                    const observed = actual.find(row => row.title === item.title);
                    if (!observed) continue;
                    near(item.titleInset, observed.titleInset, 'Title left inset ' + width + ' ' + item.title);
                    near(item.headerHeight, observed.headerHeight, 'Header height ' + width + ' ' + item.title);
                    assert.ok(item.descriptionInside && observed.descriptionInside, 'Description must stay inside header: ' + width + ' ' + item.title);
                    assert.equal(observed.selected, item.selected, 'Selected state mismatch ' + item.title);
                    assert.equal(Boolean(observed.chip), Boolean(item.chip), 'Collapsed logo mismatch ' + item.title);
                    if (item.chip) {
                        for (const key of ['width', 'height', 'imageWidth', 'imageHeight'])
                            near(item.chip[key], observed.chip[key], 'Chip geometry ' + width + ' ' + item.title + ' ' + key);
                        for (const key of ['padding', 'border', 'ratio', 'imageMaxHeight', 'imageFit'])
                            assert.equal(observed.chip[key], item.chip[key], 'Chip style ' + width + ' ' + item.title + ' ' + key);
                    }
                    assert.equal(Boolean(observed.grid), Boolean(item.grid), 'Expanded grid state ' + item.title);
                    if (item.grid) for (const key of ['count', 'columns', 'padding', 'gap', 'selected'])
                        assert.equal(observed.grid[key], item.grid[key], 'Expanded cards ' + width + ' ' + item.title + ' ' + key);
                }
            }
            assert.equal(await wallet.locator('.lf-wallet-logo-strip,.lf-wallet-logo').count(), 0);
            // Intercept both requests before any click; no money-moving endpoint is reached.
            await wallet.route('**/account/wallet/topups/quote', async route => {
                const request = route.request().postDataJSON();
                await route.fulfill({
                    status: 200, contentType: 'application/json',
                    body: JSON.stringify({
                        amount_idr: request.amount_idr,
                        payment_channel_code: request.payment_channel_code,
                        total_idr: request.amount_idr + 500,
                    }),
                });
            });
            await wallet.getByRole('button', { name: 'Hitung total' }).click();
            await wallet.getByText('Biaya pembayaran').waitFor();
            // A different payment selection must invalidate the old quote.
            const currentGroup = candidates[candidates.length - 1];
            const nextGroup = candidates.find(title => title !== currentGroup);
            await wallet.locator('.lf-payment-group-main').filter({ hasText: nextGroup }).first().click();
            await wallet.getByText('Biaya pembayaran').waitFor({ state: 'detached' });
            await wallet.getByRole('button', { name: 'Hitung total' }).click();
            await wallet.getByText('Biaya pembayaran').waitFor();
            if (width !== 768) {
                await checkout.screenshot({ path: output + '/pr10-checkout-' + width + '.png', fullPage: true });
                await wallet.screenshot({ path: output + '/pr10-wallet-' + width + '.png', fullPage: true });
            }
            // Force an uncertain top-up response without sending a transaction.
            const attemptedKeys = [];
            await wallet.route('**/account/wallet/topups', async route => {
                attemptedKeys.push(route.request().postDataJSON().idempotency_key);
                await route.fulfill({
                    status: 422, contentType: 'application/json',
                    body: JSON.stringify({ message: 'Status top up perlu diperiksa.' }),
                });
            });
            await wallet.getByRole('button', { name: 'Top up', exact: true }).click();
            await wallet.getByText('Top up sebelumnya masih diproses.', { exact: false }).waitFor();
            assert.ok(await wallet.locator('.lf-payment-group-main').first().isDisabled(), 'Shared group header must lock');
            assert.ok(await wallet.locator('.lfx-payment-method-card[aria-disabled=true]').count() > 0, 'Shared radio cards must lock');
            assert.ok(await wallet.locator('input[type=number]').isDisabled(), 'Wallet amount must lock');
            assert.ok(await wallet.getByRole('button', { name: 'Hitung total' }).isDisabled(), 'Quote action must lock');
            const retry = wallet.getByRole('button', { name: 'Cek status top up' });
            assert.ok(await retry.isEnabled(), 'Same-key status recovery must remain accessible');
            await retry.click();
            await wallet.waitForTimeout(200);
            assert.equal(attemptedKeys.length, 2, 'Uncertain top-up must permit one more idempotent status check');
            assert.ok(attemptedKeys[0] && attemptedKeys[0] === attemptedKeys[1], 'Status check must reuse original idempotency key');
            console.log('PASS PR10 picker parity, quote invalidation, locked inputs, and idempotent retry at ' + width);
        } finally {
            await checkout.close();
            await wallet.close();
        }
    }
} finally {
    await browser.close();
}
