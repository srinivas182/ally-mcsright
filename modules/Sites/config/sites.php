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
    'templates' => [
        'aurum' => ['name' => 'Aurum', 'description' => 'Dark luxury with gold accents and a full-screen hero.', 'fonts' => 'Cormorant Garamond + Inter'],
        'modern' => ['name' => 'Modern', 'description' => 'Light, clean and bright; the default for agents.', 'fonts' => 'Inter'],
    ],
    // Crawlers that read sites for AI answers; allowed unless the brokerage opts out.
    'ai_crawlers' => ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Meta-ExternalAgent'],
];
