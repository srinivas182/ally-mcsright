<?php

declare(strict_types=1);

return [
    /*
    | Base domain for tenant platform subdomains: {slug}.{platform_domain}
    */
    'platform_domain' => env('CORE_PLATFORM_DOMAIN', 'rightally.app'),

    /*
    | Host serving the RightAlly HQ console (resolves to the HQ tenant).
    */
    'hq_host' => env('CORE_HQ_HOST', 'app.rightally.io'),

    /*
    | Hosts that run without a tenant (local development and the starter-kit
    | routes until Identity moves them onto tenant domains in Sprints 3-4).
    */
    'central_hosts' => array_filter(array_map('trim', explode(',', (string) env('CORE_CENTRAL_HOSTS', 'localhost,127.0.0.1')))),

    /*
    | Logical database connections. Null means "use the default connection".
    | Production sets these to the control-plane and cell connections.
    */
    'connections' => [
        'control' => env('CORE_CONTROL_CONNECTION'),
        'cell' => env('CORE_CELL_CONNECTION'),
    ],

    /*
    | TEMPORARY until roles and permissions ship (Sprints 5-6, specs/core/03): HQ console
    | access is denied unless the signed-in user's email is listed here (comma-separated).
    */
    'hq_bootstrap_admins' => array_values(array_filter(array_map(
        fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('CORE_HQ_ADMINS', '')),
    ))),

    /*
    | Component workbench at /dev/design: always on locally; set CORE_DESIGN_GALLERY=true to
    | enable it in a staging environment for design review. Never enable in production.
    */
    'design_gallery' => (bool) env('CORE_DESIGN_GALLERY', false),

    'domain_cache_ttl' => (int) env('CORE_DOMAIN_CACHE_TTL', 300),

    'slug' => [
        'min' => 3,
        'max' => 40,
        'reserved' => [
            'www', 'app', 'api', 'admin', 'hq', 'mail', 'support', 'help', 'status', 'docs',
            'blog', 'cdn', 'static', 'assets', 'auth', 'login', 'billing', 'rightally',
        ],
    ],
];
