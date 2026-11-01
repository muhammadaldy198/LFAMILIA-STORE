# PR 7 — Media performance roll-out (no production actions in this PR)

## What changes

- Spatie Media Library now creates **synchronous WebP conversions** for product cover images (card: 288×432 at quality 55, detail: 720×960 at quality 62) and both home-banner viewports (card: max 480×360, display: max 1440×900 at quality 48). The images keep their natural aspect ratio. Product detail banners and legacy fallback banners have equivalent WebP conversions.
- Category icons, package/group art, news covers, welcome popups, logo and footer banner also get smaller WebP variants where rendered in the customer storefront. Admin image previews and original uploaded files are unchanged.
- The storefront returns a conversion only after Media Library reports it generated. Missing/old conversions retain the original URL until a **separate approved** backfill. This is deliberate to prevent broken images; it means the no-original-image target is **not yet met** for unbackfilled uploads.
- Images below the initial viewport use lazy loading and dimension attributes. First visible banners and the first popular cards are eager; existing CSS layout remains unchanged.
- Vite already uses `import.meta.glob('./Pages/**/*.vue')` with `return page()`, so rarely visited pages already load through dynamic chunks. No unproven dependency removal or manual chunking was introduced.

## After an approved deploy, before claiming production targets

Run each missing-only backfill once in the application release with filesystem storage present (these are **not run by this PR**):

```sh
php artisan media-library:regenerate "App\\Models\\Product" --only-missing
php artisan media-library:regenerate "App\\Models\\HomeBanner" --only-missing
php artisan media-library:regenerate "App\\Models\\StoreAsset" --only-missing
php artisan media-library:regenerate "App\\Models\\Category" --only-missing
php artisan media-library:regenerate "App\\Models\\NewsArticle" --only-missing
php artisan media-library:regenerate "App\\Models\\SitePopup" --only-missing
php artisan media-library:regenerate "App\\Models\\ProductPackage" --only-missing
php artisan media-library:regenerate "App\\Models\\ProductPackageGroup" --only-missing
```

Do not delete original uploads or run `media-library:clean`. Verify every previously used media item reports the expected conversions. Capture a real browser network waterfall for homepage, filtered catalog, search, and product detail on mobile and desktop. Check transferred per-image sizes: product card ≤ 61,440 B (60 KiB), homepage banner ≤ 307,200 B (300 KiB), and no raw JPG/PNG product/banner request after backfill. If an image exceeds the limit, adjust the conversion settings and regenerate it, then remeasure. No assumed percentage savings or production-size claims.

## Frontend build evidence

Reproducible Vite 7.3.7 GitHub Actions builds at PR6 HEAD `861e8a29` (job `124843295894`) and PR7 first commit `fe560b2e` (job `124847884268`) both transformed 3,337 modules.

| Asset (Vite output, uncompressed) | Before | After | Delta |
|---|---:|---:|---:|
| Main `app.css` | 341.29 kB | 341.29 kB | 0.00 kB |
| Main `app.js` | 284.95 kB | 284.95 kB | 0.00 kB |
| Homepage `Index.js` dynamic chunk | 15.85 kB | 16.30 kB | +0.45 kB |
| CustomerShell dynamic chunk | 28.49 kB | 28.70 kB | +0.21 kB |
| Product page `Show.js` dynamic chunk | 48.66 kB | 48.96 kB | +0.30 kB |

Main CSS gzip stays 45.31 kB; main JS gzip changes 99.58 → 99.57 kB. These are **actual CI build log values**, distinct from 2026-11-01 production audit estimates (336 KB CSS / 285 KB JS). The extra view markup changes chunk output slightly. Vite's `import.meta.glob` already splits rarely-used pages. No blind bundling changes without demonstrated startup benefit. Keep byte savings on network images separate from JS/CSS sizes.

## Cache ownership

`/storage/**` is normally served directly by CloudPanel/nginx, not by the Laravel response middleware. The repo can change URL selection and generated conversion files but **cannot increase Cache-Control for these files without server configuration**. No nginx/Cloudflare settings changed in this PR. After explicit production approval, apply a versioned-media-only nginx caching rule to generated `/storage/*/conversions/*` URLs, after verifying whether the public URLs are truly immutable/versioned; do **not** assign year-long immutable caching to URLs overwritten in place. Prefer hashed file names or `version_urls=true` before long `immutable` policy, and leave ordinary originals with safer TTL. Verify `Cache-Control` and `ETag` via HTTP headers on real traffic.

## Release checks

- Verify production PHP GD/Imagick WebP support before uploading fresh media.
- Confirm backfill succeeded and no missing conversion on customer-facing images.
- Confirm real transfer sizes against the acceptance limits (fake test images are not a substitute for production measurements).
- Run existing desktop/mobile visual regression and browser smoke, observe CLS and broken images, keep the PR unmerged and undeployed until owner approval.
