# PR #266 — Codex Cloud SPEC FINAL V3 QA

SPEC: [owner comment](https://github.com/muhammadaldy198/LFAMILIA-STORE/pull/266#issuecomment-6122546351), with the later instruction to leave the footer unchanged. All 16 repository reference screenshots and their README were inspected. No production/VPS/SSH access was used.

These are **original Chromium screenshots from Codex Cloud**, using synthetic local artwork and data. They establish the measured geometry and interaction behavior, not pixel identity with production artwork or the reference store. No Ourastore assets were copied. All six sections below passed at the five required viewports; this is not a claim of universal/device-independent 100% visual equivalence.

## Six-section result

| Section | Result | Browser assertions |
|---|---|---|
| Top banner | PASS | Full viewport width; height >=224px; centered crop; contain at >=1024px; gradient begins at 70% |
| Product icon 3D | PASS | CSS 128/240px square; 64/112px overlap at 768px breakpoint; DOMMatrix equals rotateY(20deg) rotateX(-4deg); origin 0px 64/120px |
| Numbered section headers | PASS | 40x40px tiles; title 14px/24px; section body padding 16px |
| Package cards | PASS | 2/3/2/3 columns at 640/768/1024; gap/padding 16px; radius 12px; height >=85px; long label unclipped; info control does not cover label |
| Payment methods | PASS | Radius 8px; padding 10px; logo max-height 48px; QRIS/E-Wallet/VA interaction |
| Mobile sticky checkout | PASS | Fixed bottom/full width below 1024px, z-index 40; padding 16px; dashed info 4em; full-width bag CTA; disabled before selection, enabled after valid server quote |

## Measured geometry

CSS layout sizes and transformed bounding boxes are different: the 3D image is 128/240px in CSS, but its projected rectangle is narrower. Raw x/y/width/height, computed styles, 3D transform and functional quote responses are in [measurements.json](measurements.json).

| Viewport | Banner W x H | Icon projected W x H | Icon overlap | Number tile | Card padding / gap | Payment padding | Sticky info H / CTA W |
|---|---|---|---|---|---|---|---|
| 360×800 | 360.00×224.00 | 123.33×127.69 | 64px | 40×40 | 16px / 16px | 10px | 48px / 328px |
| 390×844 | 390.00×224.00 | 123.33×127.69 | 64px | 40×40 | 16px / 16px | 10px | 48px / 358px |
| 768×1024 | 768.00×224.00 | 231.25×239.42 | 112px | 40×40 | 16px / 16px | 10px | 52px / 736px |
| 1280×800 | 1280.00×294.39 | 231.25×239.42 | 112px | 40×40 | 16px / 16px | 10px | hidden (desktop) |
| 1440×900 | 1440.00×331.19 | 231.25×239.42 | 112px | 40×40 | 16px / 16px | 10px | hidden (desktop) |

## Screenshots

| Viewport | Initial viewport | Full page | Selected payment | Product info | Confirmation |
|---|---|---|---|---|---|
| 360×800 | [initial](checkout-360-initial.png) | [full](checkout-360-full.png) | [selected](checkout-360-selected.png) | [modal](checkout-360-modal.png) | [confirmation](checkout-360-confirmation.png) |
| 390×844 | [initial](checkout-390-initial.png) | [full](checkout-390-full.png) | [selected](checkout-390-selected.png) | [modal](checkout-390-modal.png) | [confirmation](checkout-390-confirmation.png) |
| 768×1024 | [initial](checkout-768-initial.png) | [full](checkout-768-full.png) | [selected](checkout-768-selected.png) | [modal](checkout-768-modal.png) | [confirmation](checkout-768-confirmation.png) |
| 1280×800 | [initial](checkout-1280-initial.png) | [full](checkout-1280-full.png) | [selected](checkout-1280-selected.png) | [modal](checkout-1280-modal.png) | [confirmation](checkout-1280-confirmation.png) |
| 1440×900 | [initial](checkout-1440-initial.png) | [full](checkout-1440-full.png) | [selected](checkout-1440-selected.png) | [modal](checkout-1440-modal.png) | [confirmation](checkout-1440-confirmation.png) |

## Functional evidence

Each viewport uses an independent synthetic client IP behind an explicitly trusted loopback proxy, retaining the real rate limiter. The browser only permits loopback resources, and blocks order creation/payment mutation routes. No external payment API is called.

- ID/server fields filled, package selected, actual scrollIntoView observed with behavior=smooth and nonzero scroll.
- QRIS, E-Wallet and Virtual Account choices exercised against the real local Laravel routes.
- Actual /checkout/quote response: dummy package subtotal Rp20.000; total matches subtotal + server fee. Existing fee calculations remain unchanged.
- Missing buyer contact rejected; synthetic contact supplied; CLOUDQA1000 voucher produces Rp1.000 server discount. Confirmation displays the exact resulting server total, not a browser-invented price.
- Product information dialog opened/dismissed and measured inside the viewport; before/after sticky states captured; confirmation dismissed without creating an order.
- No horizontal overflow, clipped package/payment titles, intersecting label/info controls, broken images, or page exceptions at the tested sizes.
- Database check after the run: orders=0, payment_transactions=0.

## Bugs corrected

1. High-specificity generic control sizing overrode payment padding and the sticky summary height. Exclude those checkout controls from the generic rules.
2. A more specific desktop rule kept body padding at 20px. Match its specificity for the V3 16px body padding.
3. Promo/info cards changed their outer padding to 30px and long names were line-clamped. Keep outer padding 16px, place promo in flow, reserve internal space for the info control, and wrap full names.
4. The tablet sticky container had a 720px cap. Remove that cap below the desktop breakpoint.
5. Integration tests faked HTTP but still depended on live DNS for example.com. Add a PHPUnit-only DNS fixture; other hosts continue through the real resolver. Production application code is unchanged.
6. Two admin order tests ran email jobs synchronously without fixtures. Fake the queue before completing their dummy order, preserving existing queue assertions.
7. Browser favicon fixture had a PNG IDAT CRC error. Replace it with a valid synthetic PNG.
8. Browser smoke assigned APP_URL without exporting it; local localhost media was cross-origin against 127.0.0.1 and CSP blocked it. Export the local test URL without weakening CSP.

## Reproduce

From rebuild/, PHP 8.4 with extensions including GD, local MySQL/Redis, Node >=22 and Chromium/Chrome are required. Use a fresh temporary database for the existing full smoke suite: it imports fixture packages and is not a database reset command.

```bash
composer install --no-interaction --prefer-dist
HUSKY=0 npm ci
npm run lint
npm run check
npm run build
node --test tests/Browser/ordering.test.mjs tests/Browser/customer-ui-guards.test.mjs tests/Browser/admin-ui-guards.test.mjs
vendor/bin/phpunit
vendor/bin/pint --test
bash tests/Browser/smoke.sh
# Create local MySQL database lfamilia_checkout_preview_v3 first.
# This guard-protected fixture refuses a nonlocal DB host.
export PLAYWRIGHT_HOME=/tmp/lfamilia-playwright
npm install --prefix "$PLAYWRIGHT_HOME" --ignore-scripts --no-package-lock playwright@1.64.0
bash tests/Browser/checkout-v3.sh
```

The GitHub workflow now executes this five-viewport Playwright suite and publishes screenshots/measurements as the checkout-spec-v3 artifact. The PR comment records final local test results and CI run links, which cannot be known before the commit is pushed. No application pricing, fee, seller, SKU, mappings, categories, quote/payment business logic, footer markup or footer artwork was changed; synthetic fixture rows are confined to the explicitly named local QA database.
