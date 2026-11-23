<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Branding\OfficeBrand;
use Modules\Core\Branding\OfficeBrandSource;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;

/**
 * BR2: a brokerage's back office takes its brand from its website settings (Settings › Website): the brand colour,
 * the logo, and the website's banner photo for the sign-in page. Nothing new to set up. "Back office colours" there
 * chooses the whole back office, buttons and highlights only, or RightAlly's own look.
 */
final class SiteOfficeBrand implements OfficeBrandSource
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function forCurrentTenant(): ?OfficeBrand
    {
        if (! $this->tenants->check()) {
            return null;
        }
        $data = Cache::remember(self::key($this->tenants->id()), 300, function (): array {
            $site = Site::query()->where('kind', 'brokerage')->first();
            if ($site === null) {
                return [];
            }
            $brand = (array) $site->brand;
            $hero = SitePage::query()->where('site_id', $site->id)->where('path', '/')->value('sections');
            $heroImage = is_array($hero) ? ($hero[0]['props']['image'] ?? null) : null;
            $photo = is_string($brand['hero_image'] ?? null) ? $brand['hero_image']
                : (is_string($heroImage) ? app(MediaLibrary::class)->url($heroImage, 'hero') : DemoPhotos::url(StarterSite::heroPhoto($site->template), 'hero'));
            $mode = in_array($brand['office'] ?? null, OfficeBrand::MODES, true) ? $brand['office'] : 'full';
            $primary = is_string($brand['primary'] ?? null) && preg_match('/^#[0-9a-f]{6}$/i', $brand['primary']) === 1 ? strtolower($brand['primary']) : null;

            return ['primary' => $primary, 'logo' => is_string($brand['logo_url'] ?? null) ? $brand['logo_url'] : null, 'photo' => $photo, 'mode' => $mode];
        });

        return $data === [] ? null : new OfficeBrand($data['primary'], $data['logo'], $data['photo'], $data['mode']);
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget(self::key($tenantId));
    }

    private static function key(int $tenantId): string
    {
        return "office-brand:{$tenantId}";
    }
}
