<?php

namespace App\Http\Controllers;

use App\Models\NewsArticle;
use App\Models\Product;
use Illuminate\Http\Response;

class SeoController
{
    public function robots(): Response
    {
        // Never present the checkout, private accounts, or payment URLs as search results.
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin/',
            'Disallow: /account/',
            'Disallow: /auth/',
            'Disallow: /checkout',
            'Disallow: /payment',
            'Disallow: /orders/',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Sitemap: '.route('seo.sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function sitemap(): Response
    {
        $urls = [
            route('catalog.index'),
            route('content.news'),
            route('content.faq'),
            route('content.contact'),
            route('content.terms'),
            route('content.refund'),
            route('content.privacy'),
            route('content.promo'),
        ];

        $products = Product::query()
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereHas('packages', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->pluck('slug');
        foreach ($products as $slug) {
            $urls[] = route('catalog.show', ['slug' => $slug]);
        }

        $articles = NewsArticle::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderBy('id')
            ->pluck('slug');
        foreach ($articles as $slug) {
            $urls[] = route('content.article', ['slug' => $slug]);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach (array_unique($urls) as $url) {
            $xml .= '  <url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=900');
    }
}
