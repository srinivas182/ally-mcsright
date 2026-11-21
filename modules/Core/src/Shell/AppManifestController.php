<?php

declare(strict_types=1);

namespace Modules\Core\Shell;

use Illuminate\Http\JsonResponse;
use Modules\Core\Branding\BrandColourSource;
use Modules\Core\Tenancy\TenantContext;

/**
 * The installable app (PW1, specs/core/17): the web app manifest, named after the brokerage and in its colour, so
 * agents can add RightAlly to their phone's home screen and open it like an app. Public: browsers fetch it without
 * signing in, and it holds nothing private.
 */
final class AppManifestController
{
    private const DEFAULT_COLOUR = '#06369E';

    public function __invoke(TenantContext $tenants, BrandColourSource $brand): JsonResponse
    {
        $name = $tenants->check() ? (string) $tenants->current()->name : 'RightAlly';
        $colour = $tenants->check() ? ($brand->forCurrentTenant() ?? self::DEFAULT_COLOUR) : self::DEFAULT_COLOUR;
        $short = mb_strlen($name) <= 12 ? $name : mb_substr($name, 0, 12);

        return response()->json([
            'name' => $name.($tenants->check() ? ' · RightAlly' : ''),
            'short_name' => $short,
            'description' => 'Your brokerage back office: today, contacts, deals and money, on your phone.',
            'id' => '/',
            'start_url' => '/app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => preg_match('/^#[0-9a-f]{6}$/i', $colour) === 1 ? $colour : self::DEFAULT_COLOUR,
            'lang' => 'en',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Today', 'url' => '/agent?source=app'],
                ['name' => 'Contacts', 'url' => '/contacts?source=app'],
                ['name' => 'Deals', 'url' => '/deals?source=app'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }
}
