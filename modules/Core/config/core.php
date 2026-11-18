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
    // Sprint 63: load balancers to trust for the visitor's address and https (comma-separated, or "*"); empty means
    // the private network ranges (Http\Middleware\TrustLoadBalancer).
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // SX2: the content security policy (Security\ContentSecurityPolicy). "report" sends it report-only, so browsers
    // report what it would block (HQ → Controls) and nothing breaks; "enforce" blocks; "off" sends none. Switch to
    // "enforce" once two weeks of reports show only expected items (docs/specs/core/12).
    'csp' => env('CSP_MODE', 'report'),

    'connections' => [
        'control' => env('CORE_CONTROL_CONNECTION'),
        'cell' => env('CORE_CELL_CONNECTION'),
        'audit' => env('CORE_AUDIT_CONNECTION'),
        'audit_maintenance' => env('CORE_AUDIT_MAINTENANCE_CONNECTION'),
        'listings' => env('CORE_LISTINGS_CONNECTION'),
    ],

    /*
    | Breached-password check (Have I Been Pwned, k-anonymity) on every new password.
    */
    'breached_password_check' => (bool) env('CORE_BREACHED_PASSWORD_CHECK', true),

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
            'blog', 'cdn', 'static', 'assets', 'auth', 'login', 'billing', 'rightally', 'mcp',
        ],
    ],

    // Central host for Google/Microsoft sign-in redirects (one registered address for every brokerage).
    'sso_host' => env('CORE_SSO_HOST', 'auth.rightally.app'),

    // One AI assistant (MCP) address for every brokerage (specs/connect/03, M2b): people pick their brokerage by email.
    'mcp_host' => env('CORE_MCP_HOST', 'mcp.rightally.app'),
];
