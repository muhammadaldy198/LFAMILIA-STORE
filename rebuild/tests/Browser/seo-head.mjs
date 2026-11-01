// Compare the no-JS HTTP head with the hydrated DOM in real Chromium.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const chrome = process.argv[2];
const base = 'http://127.0.0.1:8000';

const head = html => html.match(/<head\b[^>]*>([\s\S]*?)<\/head>/i)?.[1] || '';
const all = (html, re) => [...html.matchAll(re)];
const title = html => all(head(html), /<title\b[^>]*>([\s\S]*?)<\/title>/gi)[0]?.[1] || '';
const description = html => all(head(html), /<meta\b(?=[^>]*name="description")[^>]*>/gi);
const structured = html => all(head(html), /<script\b(?=[^>]*type="application\/ld\+json")[^>]*>([\s\S]*?)<\/script>/gi)
    .map(item => JSON.parse(item[1]));

for (const path of ['/', '/catalog/browser-checkout-game', '/faq']) {
    const response = await fetch(base + path);
    assert.equal(response.status, 200, path + ' initial HTTP 200');
    const initial = await response.text();
    assert.equal(all(head(initial), /<title\b[^>]*>/gi).length, 1, path + ' one server title');
    assert.equal(description(initial).length, 1, path + ' one server description');
    assert.match(head(initial), /<link\b[^>]*rel="canonical"/, path + ' canonical');
    assert.match(head(initial), /name="robots" content="index,follow"/, path + ' indexable');

    if (path === '/') {
        assert.equal(structured(initial)[0]['@type'], 'WebSite');
        const image = head(initial).match(/<meta\b[^>]*property="og:image"[^>]*content="([^"]+)"/);
        assert.ok(image?.[1]?.startsWith('http'), 'home absolute OG image URL');
        const asset = await fetch(image[1]);
        assert.equal(asset.status, 200, 'home OG image responds HTTP 200');
    }
    if (path.startsWith('/catalog/')) {
        const product = structured(initial)[0];
        assert.equal(product['@type'], 'Product');
        assert.equal(product.offers?.priceCurrency, 'IDR');
        assert.ok(product.offers?.lowPrice > 0);
    }

    const hydrated = execFileSync(chrome, [
        '--headless=new', '--no-sandbox', '--disable-dev-shm-usage',
        '--disable-gpu', '--virtual-time-budget=3000',
        '--dump-dom', base + path,
    ], { timeout: 35000, maxBuffer: 15 * 1024 * 1024 }).toString();
    // The initial Blade document has no <main>; this proves Vue actually mounted.
    assert.match(hydrated, /<main\b/i, path + ' Vue page mounted');
    assert.equal(all(head(hydrated), /<title\b[^>]*>/gi).length, 1, path + ' one hydrated title');
    assert.equal(description(hydrated).length, 1, path + ' one hydrated description');
    assert.equal(title(hydrated), title(initial), path + ' same server and client title');
    console.log('PASS SEO server + hydration '+path);
}
