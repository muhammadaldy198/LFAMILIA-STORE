<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $seoIndexable = request()->routeIs(
            'catalog.index', 'catalog.show', 'content.news', 'content.article',
            'content.faq', 'content.contact', 'content.terms', 'content.refund',
            'content.privacy', 'content.promo', 'content.tools', 'content.tool',
            'content.status', 'content.leaderboard'
        );
        $seo = $seoIndexable ? ($page['props']['seo'] ?? null) : null;
    @endphp
    @if ($seo)
        {{-- Inertia v2 adopts these keyed tags during hydration. --}}
        <title inertia>{{ $seo['title'] }}</title>
        <meta inertia="seo-robots" name="robots" content="index,follow">
        <meta inertia="seo-description" name="description" content="{{ $seo['description'] }}">
        <link inertia="seo-canonical" rel="canonical" href="{{ $seo['canonical'] }}">
        <meta inertia="seo-site" property="og:site_name" content="{{ $seo['siteName'] }}">
        <meta inertia="seo-locale" property="og:locale" content="id_ID">
        <meta inertia="seo-type" property="og:type" content="{{ $seo['type'] }}">
        <meta inertia="seo-og-title" property="og:title" content="{{ $seo['title'] }}">
        <meta inertia="seo-og-description" property="og:description" content="{{ $seo['description'] }}">
        <meta inertia="seo-og-url" property="og:url" content="{{ $seo['canonical'] }}">
        @if ($seo['image'])
            <meta inertia="seo-og-image" property="og:image" content="{{ $seo['image'] }}">
        @endif
        <meta inertia="seo-twitter-card" name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}">
        <meta inertia="seo-twitter-title" name="twitter:title" content="{{ $seo['title'] }}">
        <meta inertia="seo-twitter-description" name="twitter:description" content="{{ $seo['description'] }}">
        @if ($seo['structuredData'])
            <script inertia="seo-jsonld" type="application/ld+json">@json($seo['structuredData'])</script>
        @endif
    @else
        <meta name="robots" content="noindex,nofollow">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if (request()->is('admin', 'admin/*'))
        @vite('resources/css/admin.css')
    @endif
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
