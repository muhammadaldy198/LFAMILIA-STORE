<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        // Render essential metadata server-side so crawlers do not depend on Vue hydration.
        $seoIndexable = request()->routeIs(
            'catalog.index', 'catalog.show', 'content.news', 'content.article',
            'content.faq', 'content.contact', 'content.terms', 'content.refund',
            'content.privacy', 'content.promo'
        );
        $seoProduct = request()->routeIs('catalog.show') ? ($page['props']['product'] ?? []) : [];
        $seoArticle = request()->routeIs('content.article') ? ($page['props']['article'] ?? []) : [];
        $seoName = $seoProduct['name'] ?? $seoArticle['title'] ?? 'LFAMILIA STORE';
        $seoDescription = $seoProduct['description'] ?? $seoArticle['summary']
            ?? 'LFAMILIA STORE menyediakan top up game, voucher digital, pulsa, paket data, dan layanan digital dengan pilihan pembayaran praktis.';
        $seoDescription = mb_substr(trim(strip_tags((string) $seoDescription)), 0, 160);
        $seoCanonical = request()->routeIs('catalog.index') ? route('catalog.index') : url()->current();
        $seoImage = $seoProduct['image_url'] ?? $seoArticle['cover_url'] ?? null;
    @endphp
    @if ($seoIndexable)
        <meta name="robots" content="index,follow">
        <meta name="description" content="{{ $seoDescription }}">
        <link rel="canonical" href="{{ $seoCanonical }}">
        <meta property="og:site_name" content="LFAMILIA STORE">
        <meta property="og:locale" content="id_ID">
        <meta property="og:type" content="{{ $seoArticle ? 'article' : 'website' }}">
        <meta property="og:title" content="{{ $seoName }}">
        <meta property="og:description" content="{{ $seoDescription }}">
        <meta property="og:url" content="{{ $seoCanonical }}">
        @if ($seoImage)
            <meta property="og:image" content="{{ $seoImage }}">
        @endif
        <meta name="twitter:card" content="{{ $seoImage ? 'summary_large_image' : 'summary' }}">
        <meta name="twitter:title" content="{{ $seoName }}">
        <meta name="twitter:description" content="{{ $seoDescription }}">
        @if (request()->routeIs('catalog.index'))
            <script type="application/ld+json">@json([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => 'LFAMILIA STORE',
                'url' => route('catalog.index'),
                'inLanguage' => 'id-ID',
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => route('catalog.index').'?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ])</script>
        @endif
    @else
        <meta name="robots" content="noindex,nofollow">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
