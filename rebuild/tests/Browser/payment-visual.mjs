import { chromium } from 'playwright';
import { mkdir, readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';

const base = process.env.CHECKOUT_STORE_URL || 'http://127.0.0.1:8000';
const signedUrl = new URL((await readFile('/tmp/lfamilia-browser-payment-url.txt', 'utf8')).trim());
const url = base + signedUrl.pathname + signedUrl.search;
const output = 'payment-visual-results';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({
    executablePath: process.argv[2],
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
    headless: true,
});
let financialPosts = 0;
try {
    for (const width of [360, 390, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: width === 1440 ? 900 : 844 } });
        page.on('request', request => {
            if (request.method() !== 'GET' && /\/(checkout\/orders|payments\/orders)/.test(new URL(request.url()).pathname)) financialPosts++;
        });
        await page.goto(url, { waitUntil: 'networkidle' });
        await page.locator('.lf-payment-invoice-chip').waitFor();
        const layout = await page.evaluate(() => {
            const chip = document.querySelector('.lf-payment-invoice-chip');
            const label = chip.querySelector('.lf-payment-invoice-label');
            const number = chip.querySelector('.lf-payment-invoice-number');
            const buttons = [...document.querySelectorAll('.lf-payment-actions > *')];
            const rect = el => el.getBoundingClientRect();
            const style = el => getComputedStyle(el);
            return {
                scrollWidth: document.documentElement.scrollWidth,
                viewport: innerWidth,
                chip: { right: rect(chip).right, left: rect(chip).left },
                labelBottom: rect(label).bottom,
                numberTop: rect(number).top,
                numberRight: rect(number).right,
                numberScrollWidth: number.scrollWidth,
                numberClientWidth: number.clientWidth,
                numberText: number.textContent.trim(),
                buttons: buttons.map(el => ({
                    tag: el.tagName, width: rect(el).width, height: rect(el).height,
                    font: style(el).fontSize, weight: style(el).fontWeight,
                    href: el.getAttribute('href'),
                })),
                live: document.querySelector('.lf-payment-check-feedback')?.getAttribute('aria-live'),
                timer: Boolean(document.querySelector('.lf-payment-expiry')),
                copy: Boolean([...document.querySelectorAll('button')].find(el => el.textContent.includes('Salin Invoice'))),
                total: document.querySelector('.lf-payment-body dl')?.textContent,
            };
        });
        assert.equal(layout.numberText, 'LF-BROWSER-INVOICE-20261101');
        assert.ok(layout.numberTop - layout.labelBottom >= 4, 'Chip label and invoice need visible spacing: ' + JSON.stringify(layout));
        assert.ok(layout.numberScrollWidth <= layout.numberClientWidth + 1, 'Invoice is truncated or overflowing');
        assert.ok(layout.numberRight <= layout.chip.right - 7, 'Invoice touches chip right edge');
        assert.ok(layout.scrollWidth <= width + 1, 'Horizontal overflow at ' + width);
        assert.equal(layout.buttons[0].tag, 'BUTTON');
        assert.equal(layout.buttons[1].tag, 'A');
        assert.ok(layout.buttons[1].href.includes('/orders/guest/LF-BROWSER-INVOICE-20261101'));
        assert.ok(Math.abs(layout.buttons[0].width - layout.buttons[1].width) < 1);
        assert.ok(Math.abs(layout.buttons[0].height - layout.buttons[1].height) < 1);
        assert.ok(layout.buttons[0].height >= 44);
        assert.equal(layout.buttons[0].font, layout.buttons[1].font);
        assert.equal(layout.buttons[0].weight, layout.buttons[1].weight);
        assert.equal(layout.live, 'polite');
        assert.ok(layout.timer && layout.copy && layout.total.includes('11.000'));

        await page.screenshot({ path: `${output}/after-${width}.png`, fullPage: true });

        if (width === 390) {
            let statusRequests = 0;
            await page.route('**/payment?**', async route => {
                if (route.request().headers()['x-inertia']) {
                    statusRequests++;
                    await new Promise(resolve => setTimeout(resolve, 450));
                }
                await route.continue();
            });
            await page.evaluate(() => {
                const button = document.querySelector('.lf-payment-actions > button');
                button.click();
                button.click(); // A second click during the same request must be ignored.
            });
            await page.waitForFunction(() => document.querySelector('.lf-payment-actions > button')?.textContent.includes('Memeriksa…'));
            assert.equal(await page.locator('.lf-payment-actions > button').isDisabled(), true);
            await page.waitForFunction(() => document.querySelector('.lf-payment-check-feedback')?.textContent.includes('Terakhir diperiksa'), null, { timeout: 10000 });
            assert.match(await page.locator('.lf-payment-check-feedback').textContent(), /WIB — masih menunggu pembayaran/);
            assert.equal(statusRequests, 1, 'Duplicate manual status reloads');
            const beforeAuto = statusRequests;
            await page.waitForTimeout(3700);
            assert.ok(statusRequests > beforeAuto, '3-second automatic polling stopped after manual check');

            await page.unroute('**/payment?**');
            await page.route('**/payment?**', async route => {
                if (route.request().headers()['x-inertia']) await route.abort('failed');
                else await route.continue();
            });
            await page.locator('.lf-payment-actions > button').click();
            await page.waitForFunction(() => document.querySelector('.lf-payment-check-feedback')?.textContent.includes('Status belum berhasil diperiksa.'), null, { timeout: 10000 });
            assert.match(await page.locator('.lf-payment-check-feedback').textContent(), /Coba lagi/);
            await page.unroute('**/payment?**');
            // Fresh page prevents a simulated network error overlay from entering the baseline capture.
            const beforePage = await browser.newPage({ viewport: { width: 390, height: 844 } });
            await beforePage.goto(url, { waitUntil: 'networkidle' });
            await beforePage.locator('.lf-payment-invoice-chip').waitFor();
            // Reconstruct the exact old chip/action classes for a documented visual baseline.
            // This is not a production 'before' capture: it reuses the unchanged PR1 markup/styling.
            await beforePage.evaluate(() => {
                const chip = document.querySelector('.lf-payment-invoice-chip');
                const label = chip.querySelector('.lf-payment-invoice-label');
                const number = chip.querySelector('.lf-payment-invoice-number');
                chip.removeAttribute('id');
                chip.className = 'rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-right';
                label.className = 'block text-[8px] font-bold uppercase tracking-[0.16em] text-white/35';
                number.className = 'mt-0.5 block max-w-40 truncate font-mono text-[10px] font-black';
                const status = document.querySelector('.lf-payment-actions > button');
                const invoice = document.querySelector('.lf-payment-actions > a');
                status.removeAttribute('id');
                status.className = 'h-10 rounded-lg border border-white/10 bg-white/[0.03] text-[10px] font-bold text-white';
                invoice.removeAttribute('id');
                invoice.className = 'flex h-10 items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-[10px] font-bold text-white';
            });
            await beforePage.screenshot({ path: `${output}/before-reconstructed-pr1-390.png`, fullPage: true });
            await beforePage.close();
        }
        await page.close();
        console.log('PASS payment page F3/F11 ' + width);
    }
    assert.equal(financialPosts, 0, 'Payment UI smoke must not POST a transaction');
} finally {
    await browser.close();
}
