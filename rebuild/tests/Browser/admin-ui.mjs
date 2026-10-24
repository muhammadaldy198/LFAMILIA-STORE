import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Smoke panel admin: 21 menu + 3 halaman detail pada 8 lebar layar, pop-up
// tengah, laci menu ponsel, pencarian Ctrl+K, dan satu impor Digiflazz nyata.
// Fixture lokal (tests/Browser/fixture.php) wajib sudah dijalankan.
const group = process.argv[3] || 'all';
const baseUrl = process.env.ADMIN_UI_BASE_URL || 'http://127.0.0.1:8000';
const groups = {
    shell: ['/admin/panel'],
    catalog: ['/admin/catalog', '/admin/catalog/categories'],
    orders: ['/admin/orders', '/admin/fulfillment'],
    content: ['/admin/content', '/admin/content/presentation'],
    operations: ['/admin/providers', '/admin/vouchers', '/admin/support', '/admin/reports', '/admin/digiflazz'],
    customers: ['/admin/customers'],
    payments: ['/admin/payments'],
    settings: ['/admin/access', '/admin/settings', '/admin/integrations', '/admin/health', '/admin/audit', '/admin/nickname-tools', '/admin/notifications'],
};
if (group !== 'all' && !groups[group]) throw Error('Unknown group: ' + group);
const paths = group === 'all' ? Object.values(groups).flat() : groups[group];

const profile = await mkdtemp(join(tmpdir(), 'lfamilia-admin-ui-'));
const chrome = spawn(process.argv[2], ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--remote-debugging-port=9228', '--user-data-dir=' + profile], { stdio: 'ignore' });
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const errors = [];
let ws;
try {
    let target;
    for (let i = 0; i < 40; i++) { try { target = await (await fetch('http://127.0.0.1:9228/json/new?about:blank', { method: 'PUT' })).json(); break; } catch { await pause(250); } }
    if (!target) throw Error('Chrome CDP unavailable');
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
    let id = 0; const waiting = new Map();
    ws.onmessage = event => {
        const m = JSON.parse(event.data);
        if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
        if (m.id) { const p = waiting.get(m.id); waiting.delete(m.id); m.error ? p.reject(Error(m.error.message)) : p.resolve(m.result); }
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => { const next = ++id; waiting.set(next, { resolve, reject }); ws.send(JSON.stringify({ id: next, method, params })); });
    await send('Page.enable'); await send('Runtime.enable');
    const evaluate = async expression => {
        const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) throw Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
        return r.result.value;
    };
    const navigate = async path => {
        await send('Page.navigate', { url: baseUrl + path });
        for (let i = 0; i < 60; i++) { await pause(100); if (await evaluate(`location.pathname + location.search === ${JSON.stringify(path)} && document.readyState === 'complete' && Boolean(document.querySelector('.adm-body'))`)) break; }
        await pause(300);
    };
    const click = async label => {
        const found = await evaluate(`(() => { const b = [...document.querySelectorAll('button, a')].find(b => b.getBoundingClientRect().height > 0 && b.textContent.trim() === ${JSON.stringify(label)}); if (!b) return false; b.click(); return true; })()`);
        if (!found) throw Error('Missing button: ' + label);
        await pause(450);
    };
    const escape = async () => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await pause(400);
    };
    const inspect = async label => {
        const info = await evaluate(`(() => {
            const visible = e => e.getBoundingClientRect().width > 0 && e.getBoundingClientRect().height > 0;
            const tables = [...document.querySelectorAll('main table')].filter(visible);
            return {
                width: innerWidth,
                scroll: document.documentElement.scrollWidth,
                shell: Boolean(document.querySelector('.adm-side')) && Boolean(document.querySelector('.adm-body')),
                heading: document.querySelector('h1')?.innerText.trim(),
                tables: tables.length,
                uncontained: tables.filter(t => !t.closest('.adm-scroll')).length,
                broken: [...document.querySelectorAll('main img')].filter(i => visible(i) && i.currentSrc && i.complete && !i.naturalWidth).map(i => i.currentSrc),
                outside: [...document.querySelectorAll('main input, main select, main textarea, main button')].filter(visible).filter(e => !e.closest('table, [role=tablist]')).filter(e => { const r = e.getBoundingClientRect(); return r.left < -2 || r.right > innerWidth + 2; }).map(e => e.textContent?.trim() || e.tagName),
            };
        })()`);
        if (!info.shell || !info.heading || info.scroll > info.width + 2 || info.uncontained || info.broken.length || info.outside.length || errors.length) throw Error(JSON.stringify({ label, ...info, errors }));
        console.log('PASS ' + label);
    };
    const inspectDialog = async label => {
        await pause(500);
        const dialog = await evaluate(`(() => { const d = document.querySelector('[role=dialog]'); if (!d) return null; const r = d.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, viewport: innerWidth, viewportHeight: innerHeight, locked: getComputedStyle(document.body).overflow, focus: d.contains(document.activeElement), title: d.querySelector('h2')?.textContent.trim() }; })()`);
        if (!dialog || dialog.width > dialog.viewport + 2 || dialog.left < -2 || dialog.right > dialog.viewport + 2 || dialog.top < -2 || dialog.bottom > dialog.viewportHeight + 2 || dialog.locked !== 'hidden' || !dialog.focus || !dialog.title) throw Error('Dialog regression ' + label + ': ' + JSON.stringify(dialog));
        console.log('PASS dialog ' + label + ' (' + dialog.title + ')');
    };

    await navigate('/admin/login');
    const login = await evaluate(`(async () => { const token = document.querySelector('meta[name="csrf-token"]').content; const r = await fetch('/admin/login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ email: 'browser-admin@example.test', password: 'Browser-admin-test-password-123' }) }); return r.status; })()`);
    if (![200, 204].includes(login)) throw Error('Admin fixture login failed: ' + login);

    const propsOf = async path => { await navigate(path); return evaluate(`JSON.parse(document.getElementById('app').dataset.page).props`); };
    const orders = await propsOf('/admin/orders');
    const customers = await propsOf('/admin/customers');
    const support = await propsOf('/admin/support');
    const orderId = orders.orders?.data?.[0]?.id;
    const customerId = customers.customers?.data?.[0]?.id;
    const ticketId = support.tickets?.data?.[0]?.id;
    if (!orderId || !customerId || !ticketId) throw Error('Fixture detail rows missing: ' + JSON.stringify({ orderId, customerId, ticketId }));
    const detailPaths = ['/admin/orders/' + orderId, '/admin/customers/' + customerId, '/admin/support?ticket=' + ticketId];

    const nav = await evaluate(`[...document.querySelectorAll('.adm-nav a')].map(a => a.getAttribute('href'))`);
    if (nav.length !== 21) throw Error('Sidebar menu count changed: ' + nav.length + ' ' + JSON.stringify(nav));

    const sizes = process.env.ADMIN_UI_SIZES
        ? process.env.ADMIN_UI_SIZES.split(',').map(pair => pair.split('x').map(Number))
        : group === 'all' ? [[360, 800], [390, 844], [412, 915], [430, 932], [1024, 768], [1280, 800], [1440, 900], [1920, 1080]] : [[390, 844], [1440, 900]];
    let imported = false, importedPrice = '', importedCost = '', importedSku = '';
    for (const [width, height] of sizes) {
        await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 768 });
        for (const path of [...paths, ...detailPaths]) {
            await navigate(path); await inspect(width + '-' + path);

            if (width < 768 && path === '/admin/panel') {
                await click('☰');
                if (!(await evaluate(`Boolean(document.querySelector('.adm-side.open')) && Boolean(document.querySelector('.adm-scrim'))`))) throw Error('Mobile drawer did not open at ' + width);
                await escape();
                if (await evaluate(`Boolean(document.querySelector('.adm-side.open'))`)) throw Error('Mobile drawer did not close with Escape at ' + width);
                console.log('PASS drawer ' + width);
            }

            if (path === '/admin/panel' && width >= 1024) {
                await evaluate(`window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true }))`);
                if (await evaluate(`document.activeElement?.getAttribute('aria-label')`) !== 'Pencarian admin') throw Error('Ctrl+K did not focus admin search');
                await evaluate(`(() => { const i = document.querySelector('input[aria-label="Pencarian admin"]'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(i, 'pesanan'); i.dispatchEvent(new Event('input', { bubbles: true })); })()`);
                await pause(1200);
                const results = await evaluate(`document.querySelector('.adm-search-results')?.innerText || ''`);
                if (!results.includes('Pesanan')) throw Error('Admin search returned no Pesanan result: ' + results.slice(0, 120));
                await evaluate(`(() => { const i = document.querySelector('input[aria-label="Pencarian admin"]'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(i, ''); i.dispatchEvent(new Event('input', { bubbles: true })); })()`);
                await pause(600);
                console.log('PASS search ' + width);
            }

            if (path === '/admin/catalog') {
                await click('Tambah produk'); await inspectDialog(width + '-catalog-add'); await escape();
                await click('Impor dari Digiflazz'); await inspectDialog(width + '-catalog-import'); await escape();
                await evaluate(`(() => { const b = [...document.querySelectorAll('tbody tr button')].find(b => b.getBoundingClientRect().height > 0); b?.click(); })()`);
                await pause(600);
                for (const tab of ['Informasi', 'Nominal & Harga', 'Sumber', 'Tampilan Produk', 'Data Pelanggan']) {
                    await click(tab); await inspectDialog(width + '-product-' + tab);
                }
                await escape();

                if (!imported) {
                    await click('Impor dari Digiflazz');
                    const row = await evaluate(`(() => {
                        const tr = [...document.querySelectorAll('[role=dialog] tbody tr')].find(r => r.querySelector('input[type=checkbox]'));
                        if (!tr) return null;
                        const cells = [...tr.cells].map(c => c.innerText.trim());
                        // Kolom: SKU, harga seller, modal toko, harga jual.
                        return { sku: cells[2], seller: cells[3], modal: cells[4], sell: cells[5] };
                    })()`);
                    if (!row) throw Error('Import picker has no available SKU to import');
                    await evaluate(`document.querySelector('[role=dialog] tbody tr input[type=checkbox]').click()`);
                    await pause(400);
                    await evaluate(`[...document.querySelectorAll('[role=dialog] button')].find(b => /^Impor 1 nominal$/.test(b.textContent.trim())).click()`);
                    let done = false;
                    for (let i = 0; i < 80; i++) { await pause(100); done = await evaluate(`!document.querySelector('[role=dialog]')`); if (done) break; }
                    if (!done) throw Error('Digiflazz import did not finish: ' + JSON.stringify(await evaluate(`({ alerts: [...document.querySelectorAll('[role=alert]')].map(e => e.innerText), buttons: [...document.querySelectorAll('[role=dialog] button')].map(b => b.textContent.trim()) })`)));
                    let status = '';
                    for (let i = 0; i < 30; i++) { status = await evaluate(`document.querySelector('[role=status]')?.innerText || ''`); if (status.includes('berhasil diimpor')) break; await pause(100); }
                    if (!status.includes('berhasil diimpor')) throw Error('Import status missing: ' + JSON.stringify({ status, alerts: await evaluate(`[...document.querySelectorAll('[role=alert]')].map(e => e.innerText)`) }));
                    if (await evaluate(`Boolean(document.querySelector('[role=dialog]'))`)) await escape();
                    imported = true;
                    importedSku = row.sku;
                    importedPrice = row.sell.replace(/[^0-9]/g, '');
                    importedCost = row.modal.replace(/[^0-9]/g, '');
                    console.log('PASS import digiflazz (' + row.sku + ': ' + row.seller + ' -> ' + row.sell + ')');
                }
            }

            if (path === '/admin/orders') { await click('Catat pesanan manual'); await inspectDialog(width + '-manual-order'); await escape(); }
            if (path === '/admin/access') { await click('Tambah admin'); await inspectDialog(width + '-admin-create'); await escape(); }
        }
    }

    if ((group === 'all' || group === 'catalog') && !imported) throw Error('Digiflazz import flow never ran');
    if (imported) {
        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        await navigate('/admin/catalog');
        // Harga jual impor disimpan sebagai margin nominal (mode persen), bukan
        // kolom harga tetap. Modal toko dan harga jual hasil hitungan server
        // harus sama dengan dua kolom yang ditampilkan di pop-up impor.
        const stored = await evaluate(`JSON.parse(document.getElementById('app').dataset.page).props.products
            .flatMap(p => (p.packages || []).map(k => ({
                sku: (k.mappings || []).map(m => m.external_sku).join(','),
                mode: k.pricing_mode,
                margin: Number(k.margin_percent ?? 0),
                cost: Number((k.mappings || [])[0]?.store_cost_idr ?? 0),
                active: k.is_active,
            })))
            .filter(k => k.sku === ${JSON.stringify(importedSku)})`);
        const nominal = stored[0];
        // Rumus yang sama dengan server: persen disimpan 4 desimal, dibulatkan ke atas.
        const units = Math.round(nominal.margin * 10000);
        const expectedSell = Math.ceil((nominal.cost * (1000000 + units)) / 1000000);
        if (!nominal || nominal.mode !== 'PERCENT'
            || String(nominal.cost) !== importedCost
            || String(expectedSell) !== importedPrice) {
            throw Error('Imported nominal not stored with the shown price: '
                + JSON.stringify({ stored, importedCost, importedPrice }));
        }
        console.log('PASS imported nominal stored (' + nominal.sku + ': modal ' + nominal.cost + ' + ' + nominal.margin + '% = ' + expectedSell + ')');
    }
    console.log('Admin UI smoke passed (' + paths.length + ' menu + ' + detailPaths.length + ' detail, ' + sizes.length + ' viewports).');
} finally {
    try { ws?.close(); } catch {}
    chrome.kill('SIGTERM');
    await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 }).catch(() => {});
}
