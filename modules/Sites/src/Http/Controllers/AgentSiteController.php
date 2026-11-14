<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\AgentSites;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\PageRenderer;
use Modules\Sites\Rendering\Seo;
use Modules\Sites\Rendering\SiteContext;

/**
 * The agent directory (/agents) and each agent's website at /agents/{slug} (specs/websites/09). Only live websites
 * of active agents are shown, and only when the brokerage has agent websites turned on.
 */
final class AgentSiteController
{
    public function __construct(private readonly TenantContext $tenants, private readonly SiteContext $context) {}

    public function directory(Request $request): Response
    {
        $brokerage = $this->brokerage();
        if (! $brokerage instanceof Site) {
            return $brokerage;
        }
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $language = trim((string) $request->query('language', ''));
        $all = array_map(fn (Site $s): array => AgentSites::profile($s) + ['slug' => (string) $s->slug], $this->live());
        $languages = array_values(array_unique(array_merge(...array_map(fn (array $a): array => $a['languages'], $all))));
        sort($languages);
        $agents = array_values(array_filter($all, fn (array $a): bool => ($q === '' || str_contains(mb_strtolower($a['name'].' '.implode(' ', $a['areas'])), $q))
            && ($language === '' || in_array($language, $a['languages'], true))));
        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $list = ['@type' => 'ItemList', 'name' => 'Agents at '.$brokerage->displayName(), 'itemListElement' => array_map(fn (array $a, int $i): array => ['@type' => 'ListItem',
            'position' => $i + 1, 'item' => array_filter(['@type' => 'RealEstateAgent', 'name' => $a['name'], 'url' => $base.'/agents/'.$a['slug'], 'image' => $a['photo'],
                'parentOrganization' => ['@id' => $base.'/#organization']])], $agents, array_keys($agents))];

        return response()->view('sites::agents.directory', [
            'site' => $brokerage, 'current' => '/agents', 'agents' => $agents, 'languages' => $languages, 'q' => $q, 'language' => $language,
            'seo' => Seo::build($brokerage, $request->getSchemeAndHttpHost(), '/agents', 'Our agents | '.(string) ($brokerage->seo['title_suffix'] ?? $brokerage->displayName()),
                'Meet the real estate agents of '.$brokerage->displayName().': local experts for buying and selling'.(($brokerage->info['area'] ?? '') !== '' ? ' in '.$brokerage->info['area'] : '').'.',
                null, $q === '' && $language === '' && $agents !== [], [['Agents', '/agents']], [], [$list]),
            'chrome' => Chrome::for($brokerage, $this->tenants->id()),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    public function show(Request $request, string $slug, ?string $path = null): Response
    {
        $brokerage = $this->brokerage();
        if (! $brokerage instanceof Site) {
            return $brokerage;
        }
        $agent = Site::query()->where('kind', 'agent')->where('slug', $slug)->where('status', 'live')->first();
        if ($agent === null || ! $this->active($agent)) {
            return response()->view('sites::missing', ['site' => $brokerage], 404)->header('Cache-Control', 'public, max-age=60');
        }
        $this->context->enterAgent($agent, '/agents/'.$agent->slug);
        $page = SitePage::query()->where('site_id', $agent->id)->where('path', '/'.trim((string) $path, '/'))->where('status', 'published')->first();
        if ($page === null) {
            return response()->view('sites::missing', ['site' => $brokerage], 404)->header('Cache-Control', 'public, max-age=60');
        }

        return app(PageRenderer::class)->render($request, $brokerage, $agent, $page, $page->sections, true)
            ->header('Cache-Control', 'public, max-age=60, s-maxage='.config('sites.cache_seconds').', stale-while-revalidate='.config('sites.stale_seconds'));
    }

    /**
     * Live agent websites of active agents (when the brokerage allows agent websites).
     *
     * @return list<Site>
     */
    public function live(): array
    {
        if (! AgentSites::rules()['enabled']) {
            return [];
        }
        $sites = Site::query()->where('kind', 'agent')->where('status', 'live')->orderBy('slug')->limit(500)->get();
        $active = User::query()->whereIn('id', $sites->pluck('user_id')->filter())->get()->filter(fn (User $u): bool => $u->isActive())->keyBy('id');

        return array_values($sites->filter(fn (Site $s): bool => $s->user_id !== null && $active->has($s->user_id))->all());
    }

    private function active(Site $agent): bool
    {
        $user = $agent->user_id !== null ? User::query()->find($agent->user_id) : null;

        return AgentSites::rules()['enabled'] && $user instanceof User && $user->isActive();
    }

    private function brokerage(): Site|Response
    {
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site === null) {
            return response()->view('sites::missing', ['site' => null], 404);
        }
        if ($site->status !== 'live') {
            return response()->view('sites::coming-soon', ['site' => $site], 200)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
        }

        return $site;
    }
}
