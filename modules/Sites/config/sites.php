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
            'theme' => '#0b0b0d', 'css' => 'family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600', 'brand' => ['primary' => '#c9a45c', 'accent' => '#c9a45c'], 'layout' => 'hdr-centered hero-center cards-overlay shape-square btn-line title-center sec-airy', 'hero_photo' => ['exterior_luxury', 11]],
        'modern' => ['name' => 'Modern', 'description' => 'Light, clean and bright; the default for agents.', 'fonts' => 'Inter',
            'theme' => '#ffffff', 'css' => 'family=Inter:wght@400;500;600;700', 'brand' => ['primary' => '#1d4ed8', 'accent' => '#0f172a'], 'layout' => 'hdr-solid hero-full cards-classic shape-soft btn-solid title-left', 'hero_photo' => ['exterior_luxury', 0]],
        // SO2: solo agent designs.
        'hearth' => ['name' => 'Hearth', 'description' => 'Warm and welcoming, with soft colours and a friendly serif; suits family and neighbourhood agents.', 'fonts' => 'Fraunces + Source Sans 3',
            'theme' => '#fbf7f2', 'css' => 'family=Fraunces:wght@500;600&family=Source+Sans+3:wght@400;600', 'brand' => ['primary' => '#b4532a', 'accent' => '#3b2f2a'], 'layout' => 'hdr-solid hero-split cards-classic shape-round btn-pill title-left', 'hero_photo' => ['exterior_family', 4]],
        'coastal' => ['name' => 'Coastal', 'description' => 'Airy and calm, rounded and easy to read; suits coastal, lake and resort markets.', 'fonts' => 'Nunito Sans',
            'theme' => '#f3f8fa', 'css' => 'family=Nunito+Sans:wght@400;600;700;800', 'brand' => ['primary' => '#0e7490', 'accent' => '#164e63'], 'layout' => 'hdr-overlay hero-center cards-classic shape-round btn-pill title-center', 'hero_photo' => ['exterior_coastal', 4]],
        'metro' => ['name' => 'Metro', 'description' => 'Bold and high-contrast with large type; suits city condos and new construction.', 'fonts' => 'Manrope',
            'theme' => '#0f0f10', 'css' => 'family=Manrope:wght@400;600;800', 'brand' => ['primary' => '#e11d48', 'accent' => '#0f0f10'], 'layout' => 'hdr-solid hero-split cards-overlay shape-square btn-solid title-left', 'hero_photo' => ['city_night', 0]],
        // IX1: five more designs (specs/websites/16).
        'estate' => ['name' => 'Estate', 'description' => 'Light luxury: ivory, editorial serif headlines and fine rules; suits luxury and architectural homes.', 'fonts' => 'Playfair Display + Inter',
            'theme' => '#faf8f4', 'css' => 'family=Playfair+Display:wght@500;600&family=Inter:wght@400;500;600', 'brand' => ['primary' => '#1f3a33', 'accent' => '#b08d57'], 'layout' => 'hdr-centered hero-full cards-minimal shape-square btn-line title-rule sec-airy', 'hero_photo' => ['exterior_luxury', 1]],
        'nordic' => ['name' => 'Nordic', 'description' => 'Minimal and calm with lots of white space and square corners; suits modern homes and design-led teams.', 'fonts' => 'DM Sans',
            'theme' => '#ffffff', 'css' => 'family=DM+Sans:wght@400;500;700', 'brand' => ['primary' => '#111827', 'accent' => '#6b7280'], 'layout' => 'hdr-solid hero-framed cards-minimal shape-square btn-line title-left sec-airy', 'hero_photo' => ['living', 9]],
        'heritage' => ['name' => 'Heritage', 'description' => 'Classic and trusted: a traditional serif, navy and brass; suits established brokerages and historic districts.', 'fonts' => 'Libre Baskerville + Lato',
            'theme' => '#fdfcf9', 'css' => 'family=Libre+Baskerville:wght@400;700&family=Lato:wght@400;700', 'brand' => ['primary' => '#1e3a5f', 'accent' => '#a67c2e'], 'layout' => 'hdr-solid hero-framed cards-framed shape-soft btn-solid title-center', 'hero_photo' => ['exterior_family', 15]],
        'summit' => ['name' => 'Summit', 'description' => 'Outdoorsy and strong: forest tones and sturdy type; suits mountain, lake and acreage markets.', 'fonts' => 'Outfit',
            'theme' => '#f4f6f2', 'css' => 'family=Outfit:wght@400;500;700', 'brand' => ['primary' => '#2f5d3a', 'accent' => '#1d2a22'], 'layout' => 'hdr-overlay hero-full cards-framed shape-soft btn-solid title-left', 'hero_photo' => ['exterior_coastal', 2]],
        'atrium' => ['name' => 'Atrium', 'description' => 'Architectural and bright: frosted glass, big photography and crisp lines; suits new construction and urban teams.', 'fonts' => 'Plus Jakarta Sans',
            'theme' => '#f7f9fb', 'css' => 'family=Plus+Jakarta+Sans:wght@400;500;700;800', 'brand' => ['primary' => '#0f766e', 'accent' => '#0b1f2a'], 'layout' => 'hdr-overlay hero-split cards-overlay shape-soft btn-pill title-left', 'hero_photo' => ['exterior_luxury', 0]],
        // IX2: designs 11–15 (specs/websites/17).
        'villa' => ['name' => 'Villa', 'description' => 'Warm Mediterranean: terracotta, cream and a graceful serif; suits Spanish-style homes and bilingual teams.', 'fonts' => 'Cormorant Garamond + Source Sans 3',
            'theme' => '#fbf6ef', 'css' => 'family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;600', 'brand' => ['primary' => '#b4532a', 'accent' => '#3d2b1f'], 'layout' => 'hdr-centered hero-framed cards-minimal shape-soft btn-line title-center sec-airy', 'hero_photo' => ['exterior_luxury', 4]],
        'loft' => ['name' => 'Loft', 'description' => 'Industrial and urban: concrete greys, a strong grotesque and sharp edges; suits lofts, condos and downtown teams.', 'fonts' => 'Space Grotesk + Inter',
            'theme' => '#f1f1ef', 'css' => 'family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600', 'brand' => ['primary' => '#f97316', 'accent' => '#1c1c1c'], 'layout' => 'hdr-solid hero-split cards-minimal shape-square btn-solid title-rule', 'hero_photo' => ['exterior_condo', 1]],
        'meadow' => ['name' => 'Meadow', 'description' => 'Friendly and soft: rounded shapes, sage green and easy reading; suits suburbs and first-time buyers.', 'fonts' => 'Nunito',
            'theme' => '#f6f8f3', 'css' => 'family=Nunito:wght@400;600;800', 'brand' => ['primary' => '#4d7c57', 'accent' => '#2a3b2e'], 'layout' => 'hdr-overlay hero-center cards-classic shape-round btn-pill title-center', 'hero_photo' => ['exterior_family', 13]],
        'mesa' => ['name' => 'Mesa', 'description' => 'Southwest sun: sand, clay and turquoise with a confident slab serif; suits desert and ranch markets.', 'fonts' => 'Zilla Slab + Work Sans',
            'theme' => '#faf5ee', 'css' => 'family=Zilla+Slab:wght@500;700&family=Work+Sans:wght@400;500;600', 'brand' => ['primary' => '#0e7c7b', 'accent' => '#a0522d'], 'layout' => 'hdr-solid hero-full cards-framed shape-soft btn-solid title-left', 'hero_photo' => ['exterior_luxury', 5]],
        'ember' => ['name' => 'Ember', 'description' => 'Dark and dramatic: charcoal with a warm gold glow and big photos; suits luxury and evening-photo listings.', 'fonts' => 'Bodoni Moda + Inter',
            'theme' => '#121110', 'css' => 'family=Bodoni+Moda:wght@500;700&family=Inter:wght@400;500;600', 'brand' => ['primary' => '#d4a24c', 'accent' => '#121110'], 'layout' => 'hdr-overlay hero-center cards-overlay shape-square btn-line title-center sec-airy', 'hero_photo' => ['living', 3]],
    ],
    // Crawlers that read sites for AI answers; allowed unless the brokerage opts out.
    'ai_crawlers' => ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Meta-ExternalAgent'],
];
