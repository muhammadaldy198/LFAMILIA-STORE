// Bukti tampilan halaman produk: nomor langkah mengikuti panel yang tampil,
// dan field Data Akun dalam satu baris grid sama lebar dan semuanya 40px tinggi.
// Semua halaman diukur dan difoto dulu, baru diperiksa, supaya laporan dan
// screenshot tetap tersedia sebagai bukti walau ada pemeriksaan yang gagal.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const output = 'catalog-steps-visual-results';
await fs.mkdir(output, { recursive: true });
const base = 'http://127.0.0.1:8000';
const browser = await chromium.launch({
    executablePath: process.argv[2],
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

const products = [
    // Pembanding Mobile Legends: dua field teks (User ID + Zone ID).
    { name: 'mobile-legends', slug: 'browser-checkout-game', panels: ['lfx-account', 'lfx-nominal', 'lfx-payment', 'lfx-voucher', 'lfx-contact'] },
    // Pembanding Genshin Impact: tepat dua field, input teks + select Server.
    { name: 'genshin-impact', slug: 'browser-steps-two-fields', panels: ['lfx-account', 'lfx-nominal', 'lfx-payment', 'lfx-voucher', 'lfx-contact'] },
    // Pembanding Google Play Indonesia: kategori voucher, tanpa Data Akun.
    { name: 'google-play', slug: 'browser-steps-voucher', panels: ['lfx-nominal', 'lfx-payment', 'lfx-voucher', 'lfx-contact'] },
    // Tiga field (teks + select + password) tetap rapi di grid yang sama.
    { name: 'three-fields', slug: 'browser-genshin-field-types', panels: ['lfx-account', 'lfx-nominal', 'lfx-payment', 'lfx-voucher', 'lfx-contact'], screenshot: false },
];
const viewports = [
    ['desktop', { width: 1440, height: 900 }],
    ['mobile', { width: 390, height: 844 }],
    ['mobile-360', { width: 360, height: 780 }],
];

function measurePage() {
    const round = value => Math.round(value * 100) / 100;
    const gridFields = selector => {
        const grid = document.querySelector(selector);
        if (!grid) return null;
        return {
            columns: getComputedStyle(grid).gridTemplateColumns,
            single: grid.classList.contains('single'),
            fields: [...grid.children].map(label => {
                const control = label.querySelector('select, input');
                const labelBox = label.getBoundingClientRect();
                const controlBox = control.getBoundingClientRect();
                const style = getComputedStyle(control);
                return {
                    control: control.tagName.toLowerCase() + (control.type ? ':' + control.type : ''),
                    labelX: round(labelBox.x), labelY: round(labelBox.y), labelWidth: round(labelBox.width),
                    controlWidth: round(controlBox.width), controlHeight: round(controlBox.height),
                    fontSize: style.fontSize, paddingInline: style.paddingLeft + ' ' + style.paddingRight,
                };
            }),
        };
    };
    return {
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
        panels: [...document.querySelectorAll('.lfx-sections > .lfx-panel')].map(panel => ({
            id: panel.id,
            number: panel.querySelector(':scope > .lfx-panel-header > .lfx-number')?.textContent.trim() ?? null,
            title: panel.querySelector(':scope > .lfx-panel-header h2')?.textContent.trim(),
        })),
        account: gridFields('#lfx-account .lfx-field-grid'),
        contact: gridFields('#lfx-contact .lfx-field-grid'),
    };
}

const report = [];
try {
    for (const product of products) {
        for (const [viewportName, viewport] of viewports) {
            const page = await browser.newPage({ viewport });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(`${base}/catalog/${product.slug}`, { waitUntil: 'networkidle', timeout: 45000 });
            await page.locator('#lfx-nominal').waitFor();
            const measured = await page.evaluate(measurePage);
            report.push({ product: product.name, slug: product.slug, viewport: viewportName, ...viewport, errors, ...measured });
            if (product.screenshot !== false && viewportName !== 'mobile-360') {
                await page.screenshot({ path: `${output}/${product.name}-${viewportName}.png`, fullPage: true });
                const firstPanel = page.locator('.lfx-sections > .lfx-panel').first();
                await firstPanel.screenshot({ path: `${output}/${product.name}-${viewportName}-first-panel.png` });
            }
            await page.close();
        }
    }
} finally {
    await browser.close();
    await fs.writeFile(`${output}/report.json`, JSON.stringify(report, null, 2));
}

const failures = [];
const check = (condition, message) => { if (!condition) failures.push(message); };
const spread = values => Math.max(...values) - Math.min(...values);
const rowsOf = fields => Object.values(fields.reduce((rows, field) => {
    (rows[Math.round(field.labelY)] ||= []).push(field);
    return rows;
}, {}));

for (const entry of report) {
    const where = `${entry.product} ${entry.width}x${entry.height}`;
    const expected = products.find(product => product.slug === entry.slug).panels;
    check(entry.errors.length === 0, `${where}: browser page errors ${JSON.stringify(entry.errors)}`);
    check(entry.scrollWidth <= entry.innerWidth + 1, `${where}: horizontal overflow`);
    check(JSON.stringify(entry.panels.map(panel => panel.id)) === JSON.stringify(expected),
        `${where}: panels ${JSON.stringify(entry.panels.map(panel => panel.id))}, expected ${JSON.stringify(expected)}`);
    const numbers = entry.panels.map(panel => panel.number);
    check(JSON.stringify(numbers) === JSON.stringify(expected.map((_, index) => String(index + 1))),
        `${where}: step numbers ${JSON.stringify(numbers)} must run 1..${expected.length}`);

    if (!entry.account) continue;
    const fields = entry.account.fields;
    // Keputusan pemilik PR #417: setiap kontrol grid Data Akun tepat 40px.
    for (const field of fields) {
        check(Math.abs(field.controlHeight - 40) <= 0.5, `${where}: ${field.control} height ${field.controlHeight}, expected 40`);
    }
    if (fields.length === 1) {
        check(entry.account.single, `${where}: one field must keep the full-width .single grid`);
        continue;
    }
    for (const row of rowsOf(fields)) {
        if (row.length < 2) continue;
        check(spread(row.map(field => field.labelWidth)) <= 1, `${where}: field widths differ ${JSON.stringify(row)}`);
        check(spread(row.map(field => field.controlWidth)) <= 1, `${where}: control widths differ ${JSON.stringify(row)}`);
        check(spread(row.map(field => field.controlHeight)) <= 1, `${where}: control heights differ ${JSON.stringify(row)}`);
    }
}

for (const entry of report) {
    console.log(`${entry.product} ${entry.width}x${entry.height}: steps ${entry.panels.map(panel => panel.number + ' ' + panel.title).join(' | ')}`);
    if (entry.account) console.log('  account ' + entry.account.fields.map(field => `${field.control} ${field.controlWidth}x${field.controlHeight}`).join(', '));
    if (entry.contact) console.log('  contact ' + entry.contact.fields.map(field => `${field.control} ${field.controlWidth}x${field.controlHeight}`).join(', '));
}
assert.deepEqual(failures, [], 'catalog step/field visual regressions');
console.log('PASS catalog step numbers and Data Akun field geometry (desktop + mobile)');
