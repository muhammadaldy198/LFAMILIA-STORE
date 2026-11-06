import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const output = 'catalog-fields-visual-results';
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({
    executablePath: process.argv[2],
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

const cases = [
    ['desktop', { width: 1440, height: 900 }],
    ['mobile', { width: 390, height: 844 }],
];

try {
    for (const [name, viewport] of cases) {
        const page = await browser.newPage({ viewport });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://127.0.0.1:8000/catalog/browser-genshin-field-types', {
            waitUntil: 'networkidle', timeout: 45000,
        });

        const account = page.locator('#lfx-account');
        await account.waitFor();
        const select = account.locator('select');
        const options = await select.locator('option').allTextContents();
        assert.deepEqual(options.map(s => s.trim()), [
            'Pilih server', 'Asia', 'America', 'Europe', 'TW, HK, MO',
        ]);
        assert.equal(await select.inputValue(), '');
        assert.equal(await select.locator('option[value="os_asia"]').getAttribute('value'), 'os_asia');

        const password = account.locator('input[autocomplete="new-password"]');
        assert.equal(await password.getAttribute('type'), 'password');
        await password.fill('fixture-only-not-a-real-password');
        await account.locator('.lfx-password-field button').click();
        assert.equal(await password.getAttribute('type'), 'text');
        await account.locator('.lfx-password-field button').click();
        assert.equal(await password.getAttribute('type'), 'password');
        await password.fill('');
        await select.selectOption('os_asia');
        assert.equal(await select.inputValue(), 'os_asia');

        const geometry = await page.evaluate(() => ({
            scrollWidth: document.documentElement.scrollWidth,
            width: window.innerWidth,
            selectHeight: document.querySelector('#lfx-account select')?.getBoundingClientRect().height,
            passwordHeight: document.querySelector('#lfx-account input[autocomplete="new-password"]')?.getBoundingClientRect().height,
        }));
        assert.ok(geometry.scrollWidth <= geometry.width + 1, 'horizontal overflow');
        assert.ok(geometry.selectHeight >= 44, 'select touch target');
        assert.ok(geometry.passwordHeight >= 44, 'password touch target');
        assert.deepEqual(errors, [], 'browser page errors');

        await page.screenshot({ path: `${output}/show-${name}.png`, fullPage: true });
        console.log('PASS select/password visual '+name+' '+viewport.width+'x'+viewport.height);
        await page.close();
    }
} finally {
    await browser.close();
}
