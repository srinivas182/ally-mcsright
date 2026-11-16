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
 * The website's privacy policy, on every brokerage website at /privacy (specs/websites/15). It says plainly what this
 * website collects and why, matching what RightAlly actually does: forms and accounts, the homes someone views (no
 * cookies, no third-party trackers, Global Privacy Control honoured), texts only with consent, leads never sold.
 * The owner can replace it with their own policy (Website settings). Lead-form ads link here.
 */
final class PrivacyController
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function show(Request $request): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site === null || $site->status !== 'live') {
            return response()->view('sites::missing', ['site' => $site], 404);
        }
        $name = $site->displayName();
        $custom = trim((string) ($site->info['privacy'] ?? ''));

        return response()->view('sites::privacy', [
            'site' => $site, 'current' => '/privacy', 'name' => $name, 'info' => $site->info,
            'custom' => $custom !== '' ? array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $custom) ?: []))) : null,
            'updated' => $site->updated_at?->format('F j, Y'),
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/privacy', 'Privacy policy | '.(string) ($site->seo['title_suffix'] ?? $name),
                'How '.$name.' collects, uses and protects your information on this website.', null, true, [['Privacy policy', '/privacy']], []),
            'chrome' => Chrome::for($site, $this->tenants->id(), []),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }
}
