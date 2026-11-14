<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\CrmAccess;
use Modules\Listings\Search\MarketStats;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Content\Pages;
use Modules\Sites\Content\SeoCheck;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteMedia;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Sections;
use Modules\Sites\SiteAccess;
use Modules\Sites\SiteSetup;

/** Settings → Website → page builder (specs/websites/08): sections, draft, live preview, publish, versions. */
final class PageEditorController
{
    public function __construct(private readonly SiteSetup $sites, private readonly Pages $pages) {}

    public function edit(Request $request, SitePage $page, TenantContext $tenants, MarketStats $stats, MediaLibrary $media): Response
    {
        $site = $this->editable($request, $page);
        $sections = $page->draft_sections ?? $page->sections;

        return Inertia::render('settings/website-editor', [
            'page' => ['ulid' => $page->ulid, 'path' => $page->path, 'title' => $page->title, 'meta_description' => $page->meta_description, 'noindex' => $page->noindex,
                'in_menu' => $page->in_menu, 'status' => $page->status, 'has_draft' => $page->draft_sections !== null],
            'sections' => $sections,
            'site' => ['ulid' => $site->ulid, 'kind' => $site->kind],
            'types' => collect(Sections::LABELS)->map(fn (string $label, string $type): array => ['type' => $type, 'label' => $label])->values(),
            'versions' => $this->pages->versions($page),
            'check' => SeoCheck::page($page->title, $page->meta_description, $sections, $page->noindex, (string) ($site->seo['title_suffix'] ?? $site->displayName())),
            'preview_url' => $this->sites->previewUrl($page),
            'live_url' => ($host = $this->sites->liveHost()) !== null && $page->status === 'published'
                ? 'https://'.$host.($site->kind === 'agent' ? '/agents/'.$site->slug.($page->path === '/' ? '' : $page->path) : $page->path) : null,
            'back' => $site->kind === 'agent' && ! CrmAccess::manager(self::user($request)) ? '/settings/my-website' : '/settings/website',
            'places' => array_map(fn (array $p): array => ['slug' => $p['slug'], 'name' => $p['name']], $stats->places($tenants->id(), 40)),
            'media' => self::mediaList($site, $media),
            'ai_notes' => $request->session()->get('ai_notes'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['path' => ['required', 'string', 'max:130'], 'title' => ['required', 'string', 'max:120'], 'layout' => ['nullable', 'in:standard,landing']]);
        $page = $this->pages->create($this->sites->brokerageSite() ?? abort(404), $data['path'], $data['title'], $user, (string) ($data['layout'] ?? 'standard'));

        return redirect('/settings/website/pages/'.$page->ulid.'/edit');
    }

    /** Autosaves the draft and returns the fresh SEO/AEO check (the preview reloads from the draft). */
    public function draft(Request $request, SitePage $page): JsonResponse
    {
        $site = $this->editable($request, $page);
        $user = self::user($request);
        $data = $request->validate(['sections' => ['present', 'array', 'max:40']]);
        $page = $this->pages->saveDraft($page, array_values(array_filter($data['sections'], 'is_array')), $user);

        return response()->json(['sections' => $page->draft_sections, 'check' => SeoCheck::page($page->title, $page->meta_description, $page->draft_sections ?? [], $page->noindex,
            (string) ($site->seo['title_suffix'] ?? $site->displayName())), 'preview_url' => $this->sites->previewUrl($page)]);
    }

    public function blank(Request $request): JsonResponse
    {
        self::user($request);
        $type = (string) $request->query('type');
        abort_unless(in_array($type, Sections::TYPES, true), 422);

        return response()->json(['section' => Sections::blank($type)]);
    }

    public function publish(Request $request, SitePage $page): RedirectResponse
    {
        $this->editable($request, $page);
        $this->pages->publish($page, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Published. Visitors see the new version within a few minutes.']);

        return back();
    }

    public function discard(Request $request, SitePage $page): RedirectResponse
    {
        $this->editable($request, $page);
        $this->pages->discardDraft($page, self::user($request));

        return back();
    }

    public function restore(Request $request, SitePage $page): RedirectResponse
    {
        $this->editable($request, $page);
        $this->pages->restore($page, (int) $request->validate(['version' => ['required', 'integer']])['version'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'That version is now your draft. Publish it to make it live.']);

        return back();
    }

    public function destroy(Request $request, SitePage $page): RedirectResponse
    {
        $this->editable($request, $page);
        $this->pages->delete($page, self::manager($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Page deleted. Add a redirect if other sites link to it.']);

        return redirect('/settings/website');
    }

    /**
     * @return list<array{ulid: string, url: string, alt: string}>
     */
    public static function mediaList(Site $site, MediaLibrary $media): array
    {
        return array_values(SiteMedia::query()->where('site_id', $site->id)->latest('id')->limit(200)->get()
            ->map(fn ($m): array => ['ulid' => $m->ulid, 'url' => MediaLibrary::urlFor($m, 'thumb'), 'alt' => $m->alt])->all());
    }

    /** The page's website, when this person may edit it (the brokerage's, a landing page, or their own agent website). */
    private function editable(Request $request, SitePage $page): Site
    {
        $site = Site::query()->find($page->site_id);
        abort_unless($site !== null, 404);
        abort_unless(SiteAccess::canEdit(self::user($request), $site), 403);

        return $site;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function manager(Request $request): User
    {
        $user = self::user($request);
        abort_unless(CrmAccess::manager($user), 403);

        return $user;
    }
}
