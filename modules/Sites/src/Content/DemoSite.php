<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\SiteSetup;

/**
 * TP4: the demo website people open while choosing a design at sign-up: a real, complete website (every page, home
 * search, property pages, blog) on its own address, with sample homes. It belongs to a workspace HQ keeps for this;
 * visitors see it in the design they are looking at, with their own name and area (DemoView).
 */
final class DemoSite
{
    public const SLUG = 'demo';

    public function __construct(private readonly TenantContext $tenants) {}

    public static function tenant(): ?Tenant
    {
        return Tenant::query()->where('slug', self::SLUG)->first();
    }

    /** The demo's address, or null before it is installed. */
    public static function host(): ?string
    {
        $tenant = self::tenant();

        return $tenant !== null ? self::SLUG.'.'.config('sites.platform_domain') : null;
    }

    /** A link that opens the demo in a design, with the visitor's name, area and state. */
    public static function previewUrl(string $design, string $name = '', string $area = '', ?string $state = null): ?string
    {
        $host = self::host();

        return $host === null ? null : 'https://'.$host.'/?'.http_build_query(array_filter(['design' => $design, 'name' => $name, 'area' => $area, 'state' => $state]));
    }

    /** Creates the demo workspace and its website, once. Safe to run on every deploy. */
    public function install(): Site
    {
        $tenant = self::tenant() ?? app(ProvisionTenant::class)->handle(new ProvisionTenantData(type: TenantType::Brokerage, name: DemoView::NAME, slug: self::SLUG,
            ownerEmail: 'demo@'.config('core.hq_host'), ownerName: 'RightAlly Demo', idempotencyKey: 'demo-site'));

        return $this->tenants->runAs($tenant, function (): Site {
            $sites = app(SiteSetup::class);
            $site = $sites->brokerageSite() ?? $sites->launch('modern', ['name' => DemoView::NAME, 'area' => DemoView::AREA,
                'tagline' => 'Every home for sale in '.DemoView::AREA.', updated every few minutes, with local experts from '.DemoView::NAME.'.', 'phone' => '(555) 010-0100',
                'email' => 'hello@example.com']);
            $site->forceFill(['ai_crawlers' => false, 'status' => 'live'])->save();
            // The home banner shows each design's own photo (DemoView sets it), so the page keeps none of its own.
            $home = SitePage::query()->where('site_id', $site->id)->where('path', '/')->first();
            if ($home !== null) {
                $sections = (array) $home->sections;
                foreach ($sections as $i => $s) {
                    if (($s['type'] ?? '') === 'hero') {
                        $sections[$i]['props']['image'] = null;
                    }
                }
                $home->forceFill(['sections' => $sections])->save();
            }

            return $site;
        });
    }
}
