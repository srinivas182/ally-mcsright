<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Rendering\PageRenderer;
use Modules\Sites\Sections;

/**
 * SU2: a design's home page with made-up content, for choosing a design while signing up. Nothing is saved: the page
 * is built in memory, in the HQ workspace (which has no listings, clients or pages of its own), and never indexed.
 */
final class DesignPreviewController
{
    public function __invoke(Request $request, string $design, PageRenderer $renderer, TenantContext $tenants): Response
    {
        abort_unless(array_key_exists($design, (array) config('sites.templates')), 404);
        $name = mb_substr(trim((string) $request->query('name', '')), 0, 80) ?: 'Your Brokerage';
        $area = mb_substr(trim((string) $request->query('area', '')), 0, 60) ?: 'your city';
        $info = ['name' => $name, 'tagline' => 'Homes for sale in '.$area, 'area' => $area, 'phone' => '(555) 010-0100', 'email' => 'hello@example.com'];
        $hq = Tenant::query()->where('type', TenantType::Hq)->firstOrFail();

        $response = $tenants->runAs($hq, function () use ($request, $design, $info, $renderer): Response {
            $site = new Site(['kind' => 'brokerage', 'template' => $design, 'info' => $info, 'status' => 'live',
                'brand' => (array) config("sites.templates.{$design}.brand", []), 'seo' => ['title_suffix' => $info['name']]]);
            $sections = Sections::starterPages($info, $design)['/'] ?? [];
            $page = new SitePage(['path' => '/', 'title' => 'Home', 'sections' => $sections, 'status' => 'published']);

            return $renderer->render($request, $site, $site, $page, $sections, false);
        });
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'public, max-age=600']);

        return $response;
    }
}
