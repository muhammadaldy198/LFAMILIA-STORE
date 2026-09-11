import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const read = (file) => fs.readFileSync(path.join(process.cwd(), file), "utf8");

test("admin product nominal artwork reaches the customer checkout", () => {
  const api = read("app/api/products/route.ts");
  const checkout = read("app/checkout/page.tsx");
  assert.match(api, /imageUrl: pkg\.imageUrl/);
  assert.match(checkout, /item\.imageUrl/);
});

test("moderated customer reviews are available on the storefront home page", () => {
  const reviewsApi = read("app/api/reviews/route.ts");
  const reviews = read("components/home-reviews-preview.tsx");
  const home = read("app/page.tsx");
  assert.match(reviewsApi, /listFeaturedReviews/);
  assert.match(reviews, /\/api\/reviews\?featured=1/);
  assert.match(home, /<HomeReviewsPreview/);
});

test("managed home content uses public endpoints rather than admin-only state", () => {
  assert.match(read("components/home-banner-carousel.tsx"), /\/api\/home-content/);
  assert.match(read("components/global-home-popup.tsx"), /\/api\/home-content/);
  assert.match(read("components/home-news-preview.tsx"), /\/api\/news/);
  assert.match(read("hooks/use-storefront.ts"), /\/api\/storefront/);
  assert.match(read("components/promotion-showcase.tsx"), /\/api\/promotions/);
});
