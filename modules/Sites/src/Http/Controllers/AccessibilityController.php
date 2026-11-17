<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;

/**
 * The website's accessibility statement, on every brokerage website at /accessibility (AX1, specs/core/13): what
 * the website does for people with disabilities, its target (WCAG 2.2 level AA), what may fall short, and how to
 * get help or the same information another way. Linked from every page's footer.
 */
final class AccessibilityController
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function show(Request $request): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site === null || $site->status !== 'live') {
            return response()->view('sites::missing', ['site' => $site], 404);
        }
        $name = $site->displayName();

        return response()->view('sites::accessibility', [
            'site' => $site, 'current' => '/accessibility', 'name' => $name, 'info' => $site->info,
            'updated' => $site->updated_at?->format('F j, Y'),
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/accessibility', 'Accessibility | '.(string) ($site->seo['title_suffix'] ?? $name),
                'How '.$name.' makes this website usable for everyone, and how to get help if something is hard to use.', null, true, [['Accessibility', '/accessibility']], []),
            'chrome' => Chrome::for($site, $this->tenants->id(), []),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }
}
