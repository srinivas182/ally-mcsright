<?php

declare(strict_types=1);

return [
    // Websites without their own domain live at {slug}.{platform_domain} (the back office stays on CORE_PLATFORM_DOMAIN).
    'platform_domain' => env('SITES_PLATFORM_DOMAIN', 'rightally.site'),
    // Custom domains point a CNAME here (Cloudflare for SaaS fallback origin, specs/core/05).
    'cname_target' => env('SITES_CNAME_TARGET', 'sites.rightally.site'),
    // Edge cache for published pages (seconds); stale content may be served this long while refreshing.
    'cache_seconds' => (int) env('SITES_CACHE_SECONDS', 300),
    'stale_seconds' => (int) env('SITES_STALE_SECONDS', 86400),
    // Website media library (specs/websites/08): where images are stored, and an optional CDN in front of them.
    'media_disk' => env('SITES_MEDIA_DISK', 'public'),
    'media_cdn_url' => env('SITES_MEDIA_CDN_URL'),
    // Visitor accounts and journeys (specs/websites/07): sign-up prompt after this many home views (0 = never),
    // and when to tell the agent a known visitor looks ready to act.
    'signup_after' => 3,
    'ready' => ['repeat_views' => 3],
    // Mortgage estimate defaults on listing pages (visitors can change them).
    'mortgage' => ['rate' => (float) env('SITES_MORTGAGE_RATE', 6.5), 'down_pct' => 20, 'years' => 30, 'tax_rate' => 1.2, 'insurance' => 150],
    // Website designs. theme: the browser bar colour; css: the Google Fonts families; brand: starting colours.
    'templates' => [
        'aurum' => ['name' => 'Aurum', 'description' => 'Dark luxury with gold accents and a full-screen hero.', 'fonts' => 'Cormorant Garamond + Inter',
            'theme' => '#0b0b0d', 'css' => 'family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600', 'brand' => ['primary' => '#c9a45c', 'accent' => '#c9a45c']],
        'modern' => ['name' => 'Modern', 'description' => 'Light, clean and bright; the default for agents.', 'fonts' => 'Inter',
            'theme' => '#ffffff', 'css' => 'family=Inter:wght@400;500;600;700', 'brand' => ['primary' => '#1d4ed8', 'accent' => '#0f172a']],
        // SO2: solo agent designs.
        'hearth' => ['name' => 'Hearth', 'description' => 'Warm and welcoming, with soft colours and a friendly serif; suits family and neighbourhood agents.', 'fonts' => 'Fraunces + Source Sans 3',
            'theme' => '#fbf7f2', 'css' => 'family=Fraunces:wght@500;600&family=Source+Sans+3:wght@400;600', 'brand' => ['primary' => '#b4532a', 'accent' => '#3b2f2a']],
        'coastal' => ['name' => 'Coastal', 'description' => 'Airy and calm, rounded and easy to read; suits coastal, lake and resort markets.', 'fonts' => 'Nunito Sans',
            'theme' => '#f3f8fa', 'css' => 'family=Nunito+Sans:wght@400;600;700;800', 'brand' => ['primary' => '#0e7490', 'accent' => '#164e63']],
        'metro' => ['name' => 'Metro', 'description' => 'Bold and high-contrast with large type; suits city condos and new construction.', 'fonts' => 'Manrope',
            'theme' => '#0f0f10', 'css' => 'family=Manrope:wght@400;600;800', 'brand' => ['primary' => '#e11d48', 'accent' => '#0f0f10']],
    ],
    // Crawlers that read sites for AI answers; allowed unless the brokerage opts out.
    'ai_crawlers' => ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Meta-ExternalAgent'],
];
