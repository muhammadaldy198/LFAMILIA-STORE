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
            near(wallet.firstCard.height, checkout.firstCard.height, label + ' channel card height');
            if (wallet.firstCard.style.padding !== checkout.firstCard.style.padding) {
                throw Error(label + ' channel card padding differs');
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
