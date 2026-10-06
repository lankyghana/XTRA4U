{{--
    Per-page SEO / social tags. Every value is escaped; there is deliberately no way
    to inject arbitrary <meta> or <script> markup from the CMS.
    Missing values fall back inside CmsContent (SEO title -> page title, OG title -> SEO title ...).
--}}
@props(['seo', 'url' => null])

@php
    $canonical = $seo['canonical'] ?? null ?: $url;
    if (is_string($canonical) && str_starts_with($canonical, '/')) {
        $canonical = url($canonical);
    }
    if (! is_string($canonical) || ! preg_match('#^https?://#i', $canonical)) {
        $canonical = null;
    }
    $ogImage = $seo['og_image'] ?? null;
@endphp

@if ($canonical)
    <link rel="canonical" href="{{ $canonical }}">
@endif
@if (! empty($seo['noindex']))
    <meta name="robots" content="noindex, nofollow">
@endif
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $cms->setting('footer.copyright_name', 'XTRA4U') }}">
<meta property="og:title" content="{{ $seo['og_title'] ?? $seo['title'] ?? '' }}">
<meta property="og:description" content="{{ $seo['og_description'] ?? $seo['description'] ?? '' }}">
@if ($canonical)
    <meta property="og:url" content="{{ $canonical }}">
@endif
@if ($ogImage)
    <meta property="og:image" content="{{ $ogImage }}">
@endif
<meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
