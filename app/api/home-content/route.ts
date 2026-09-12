import { listHomeBanners, listSitePopups } from "@/lib/server/content";
import { readStorefrontSettings } from "@/lib/server/storefront";
import { defaultStorefrontSettings, type StorefrontSettings } from "@/lib/store-data";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const [storedBanners, popups, settings] = await Promise.all([listHomeBanners(false), listSitePopups(false), readStorefrontSettings()]);
    const banners = settings.bannerEnabled ? storedBanners : [];
    const visiblePopups = popups;
    return Response.json({ banners, popups: visiblePopups }, { headers: { "Cache-Control": "public, max-age=30" } });
  } catch {
    return Response.json({ banners: fallbackBanners(defaultStorefrontSettings), popups: fallbackPopups }, { headers: { "Cache-Control": "public, max-age=15" } });
  }
}

const fallbackPopups = [{ id: null, title: "Selamat datang di LFAMILIA STORE", body: "Temukan top up game, voucher digital, berita terbaru, dan komunitas LFAMILIA dalam satu tempat.", primaryLabel: "Lihat produk", primaryHref: "/catalog", secondaryLabel: "Baca berita", secondaryHref: "/news", dismissDays: 7, isActive: true, sortOrder: 0 }];

function fallbackBanners(settings: StorefrontSettings) {
  if (!settings.bannerEnabled) return [];
  return [
    ...(settings.bannerImageUrl ? [{ id: null, title: `${settings.bannerTitle} ${settings.bannerHighlight}`.trim(), subtitle: settings.bannerDescription, imageUrl: settings.bannerImageUrl, mobileImageUrl: settings.bannerImageUrl, ctaLabel: settings.bannerCtaLabel, ctaHref: settings.bannerCtaHref, isActive: true, sortOrder: 0 }] : []),
    { id: null, title: "Top up game favoritmu lebih praktis", subtitle: "Pilih produk, nominal, dan metode pembayaran yang paling nyaman.", imageUrl: "/products/mobile-legends-banner.webp", mobileImageUrl: "/products/mobile-legends-cover.webp", ctaLabel: "Lihat katalog", ctaHref: "/catalog", isActive: true, sortOrder: 1 },
    { id: null, title: "Roblox, voucher, dan game populer", subtitle: "Produk otomatis maupun manual tersedia dengan informasi proses yang jelas.", imageUrl: "/products/roblox-gamepass-banner.webp", mobileImageUrl: "/products/roblox-gamepass-cover.webp", ctaLabel: "Pilih produk", ctaHref: "/catalog", isActive: true, sortOrder: 2 },
  ];
}
