<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\CrmAccess;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\PoweredBy;
use Modules\Sites\SiteSetup;

/** Settings → Website: create the brokerage site from a template, edit details, SEO and AI visibility, publish, domains. */
final class WebsiteSettingsController
{
    public function index(Request $request, SiteSetup $sites, PoweredBy $powered, TenantContext $tenants): Response
    {
        self::manager($request);
        $site = $sites->brokerageSite();

        return Inertia::render('settings/website', [
            'site' => $site === null ? null : [
                'ulid' => $site->ulid, 'template' => $site->template, 'status' => $site->status, 'published_at' => $site->published_at?->toIso8601String(),
                'info' => $site->info, 'brand' => $site->brand, 'seo' => $site->seo ?? [], 'ai_crawlers' => $site->ai_crawlers,
                'signup_after' => (int) ($site->settings['signup_after'] ?? config('sites.signup_after', 3)),
                'pages' => $site->pages()->orderBy('position')->get()->map(fn (SitePage $p): array => ['ulid' => $p->ulid, 'path' => $p->path, 'title' => $p->title,
                    'meta_description' => $p->meta_description, 'noindex' => $p->noindex, 'in_menu' => $p->in_menu])->values(),
            ],
            'hosts' => $sites->hosts(),
            'live_host' => $sites->liveHost(),
            'cname_target' => (string) config('sites.cname_target'),
            'templates' => collect((array) config('sites.templates'))->map(fn (array $t, string $k): array => ['key' => $k, 'name' => $t['name'], 'description' => $t['description']])->values(),
            'powered_by' => $powered->forTenant($tenants->id()),
        ]);
    }

    public function store(Request $request, SiteSetup $sites): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['template' => ['required', 'string', Rule::in(array_keys((array) config('sites.templates')))],
            'info' => ['nullable', 'array'], 'info.tagline' => ['nullable', 'string', 'max:200'], 'info.phone' => ['nullable', 'string', 'max:30'], 'info.email' => ['nullable', 'email', 'max:150'],
            'info.city' => ['nullable', 'string', 'max:80'], 'info.state' => ['nullable', 'string', 'size:2'], 'info.area' => ['nullable', 'string', 'max:120']]);
        $sites->create($data['template'], $data['info'] ?? [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your website is ready. Review it, then publish when you are happy.']);

        return back();
    }

    public function update(Request $request, SiteSetup $sites): RedirectResponse
    {
        $user = self::manager($request);
        $site = self::site($sites);
        $data = $request->validate(['template' => ['nullable', 'string'], 'ai_crawlers' => ['nullable', 'boolean'],
            'info' => ['nullable', 'array'], 'info.name' => ['nullable', 'string', 'max:120'], 'info.tagline' => ['nullable', 'string', 'max:200'], 'info.about' => ['nullable', 'string', 'max:3000'],
            'info.email' => ['nullable', 'email', 'max:150'], 'info.phone' => ['nullable', 'string', 'max:30'], 'info.social' => ['nullable', 'array'], 'info.social.*' => ['nullable', 'url:https', 'max:290'],
            'brand' => ['nullable', 'array'], 'brand.primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'brand.accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brand.logo_url' => ['nullable', 'url:https', 'max:490'], 'brand.hero_image' => ['nullable', 'url:https', 'max:490'],
            'settings' => ['nullable', 'array'], 'settings.signup_after' => ['nullable', 'integer', 'min:0', 'max:20'],
            'seo' => ['nullable', 'array'], 'seo.title_suffix' => ['nullable', 'string', 'max:60'], 'seo.description' => ['nullable', 'string', 'max:300']]);
        $sites->update($site, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Website saved.']);

        return back();
    }

    public function publish(Request $request, SiteSetup $sites): RedirectResponse
    {
        $user = self::manager($request);
        $live = $request->validate(['live' => ['required', 'boolean']])['live'];
        $sites->publish(self::site($sites), (bool) $live, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $live ? 'Your website is live.' : 'Your website is offline and shows a coming-soon page.']);

        return back();
    }

    public function page(Request $request, SiteSetup $sites, SitePage $page): RedirectResponse
    {
        self::manager($request);
        abort_unless($page->site_id === self::site($sites)->id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'meta_description' => ['nullable', 'string', 'max:300'],
            'noindex' => ['required', 'boolean'], 'in_menu' => ['required', 'boolean']]);
        $before = $page->only(['title', 'meta_description', 'noindex', 'in_menu']);
        $page->fill(['title' => trim(strip_tags($data['title'])), 'meta_description' => isset($data['meta_description']) ? trim(strip_tags($data['meta_description'])) : null,
            'noindex' => $page->path === '/' ? false : (bool) $data['noindex'], 'in_menu' => (bool) $data['in_menu']])->save();
        $changes = [];
        foreach ($before as $field => $old) {
            if ($page->getAttribute($field) !== $old) {
                $changes[$field] = ['old' => $old, 'new' => $page->getAttribute($field)];
            }
        }
        app(AuditLogger::class)->record('sites.page_updated', $page, changes: $changes);

        return back();
    }

    public function preview(Request $request, SiteSetup $sites, SitePage $page): JsonResponse
    {
        self::manager($request);
        abort_unless($page->site_id === self::site($sites)->id, 404);

        return response()->json(['url' => $sites->previewUrl($page)]);
    }

    public function domain(Request $request, SiteSetup $sites): RedirectResponse
    {
        $user = self::manager($request);
        self::site($sites);
        $sites->addDomain((string) $request->validate(['host' => ['required', 'string', 'max:253']])['host'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Domain added. Add the DNS record shown, and it goes live once verified.']);

        return back();
    }

    private static function site(SiteSetup $sites): Site
    {
        return $sites->brokerageSite() ?? abort(404);
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && CrmAccess::manager($user), 403);

        return $user;
    }
}
