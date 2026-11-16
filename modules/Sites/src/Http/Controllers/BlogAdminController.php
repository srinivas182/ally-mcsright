<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Listings\Search\MarketStats;
use Modules\Sites\Ai\MarketUpdates;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Content\Posts;
use Modules\Sites\Content\SeoCheck;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePost;
use Modules\Sites\SiteAccess;
use Modules\Sites\SiteSetup;

/** Settings → Website → blog (specs/websites/08). */
final class BlogAdminController
{
    public function __construct(private readonly SiteSetup $sites, private readonly Posts $posts) {}

    public function index(Request $request, MarketStats $stats): Response
    {
        self::manager($request);
        $site = $this->site();
        $places = array_map(fn (array $p): array => ['slug' => $p['slug'], 'name' => $p['name']], $stats->places($site->tenant_id, 60));
        $authors = User::query()->whereIn('id', SitePost::query()->where('site_id', $site->id)->pluck('author_user_id')->filter())->get()->keyBy('id');

        return Inertia::render('settings/website-blog', [
            'places' => $places,
            'market_updates' => MarketUpdates::settings($site),
            'posts' => SitePost::query()->where('site_id', $site->id)->orderByDesc('updated_at')->limit(300)->get()->map(fn (SitePost $p): array => [
                'ulid' => $p->ulid, 'title' => $p->title, 'slug' => $p->slug, 'category' => $p->category, 'author' => $authors->get($p->author_user_id)?->name,
                'state' => $p->status === 'draft' ? 'draft' : ($p->isLive() ? 'live' : 'scheduled'), 'published_at' => $p->published_at?->toIso8601String(),
                'score' => SeoCheck::post($p, $site->displayName())['score']]),
        ]);
    }

    public function edit(Request $request, MediaLibrary $media, ?SitePost $post = null): Response
    {
        self::manager($request);
        $site = $this->site();
        abort_if($post !== null && $post->site_id !== $site->id, 404);

        return Inertia::render('settings/website-post', [
            'post' => $post === null ? null : ['ulid' => $post->ulid, 'title' => $post->title, 'slug' => $post->slug, 'excerpt' => $post->excerpt, 'body' => $post->body,
                'category' => $post->category, 'cover_media' => $post->cover_media, 'status' => $post->status, 'published_at' => $post->published_at?->toIso8601String(),
                'seo_title' => $post->seo_title, 'meta_description' => $post->meta_description, 'noindex' => $post->noindex, 'live' => $post->isLive()],
            'check' => $post !== null ? SeoCheck::post($post, $site->displayName()) : null,
            'preview_url' => $post !== null ? $this->sites->previewPostUrl($post) : null,
            'live_url' => $post !== null && $post->isLive() && ($host = $this->sites->liveHost()) !== null ? 'https://'.$host.'/blog/'.$post->slug : null,
            'categories' => SitePost::query()->where('site_id', $site->id)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values(),
            'media' => PageEditorController::mediaList($site, $media),
            'ai_notes' => $request->session()->get('ai_notes'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $post = $this->posts->save($this->site(), null, self::input($request), self::manager($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => self::saved($post)]);

        return redirect('/settings/website/blog/'.$post->ulid);
    }

    public function update(Request $request, SitePost $post): RedirectResponse
    {
        abort_unless($post->site_id === $this->site()->id, 404);
        $post = $this->posts->save($this->site(), $post, self::input($request), self::manager($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => self::saved($post)]);

        return back();
    }

    /** Checks an unsaved post as it is typed. */
    public function check(Request $request): JsonResponse
    {
        self::manager($request);
        $in = self::input($request);
        $post = new SitePost(['title' => (string) ($in['title'] ?? ''), 'body' => (string) ($in['body'] ?? ''), 'excerpt' => $in['excerpt'] ?? null,
            'seo_title' => $in['seo_title'] ?? null, 'meta_description' => $in['meta_description'] ?? null, 'noindex' => (bool) ($in['noindex'] ?? false)]);

        return response()->json(['check' => SeoCheck::post($post, $this->site()->displayName())]);
    }

    public function destroy(Request $request, SitePost $post): RedirectResponse
    {
        self::manager($request);
        abort_unless($post->site_id === $this->site()->id, 404);
        app(AuditLogger::class)->record('sites.post_deleted', $post, metadata: ['slug' => $post->slug], severity: 'notice');
        $post->delete();

        return redirect('/settings/website/blog');
    }

    /**
     * @return array<string, mixed>
     */
    private static function input(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:160'], 'slug' => ['nullable', 'string', 'max:120'], 'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:100000'], 'category' => ['nullable', 'string', 'max:60'], 'cover_media' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'in:draft,published'], 'published_at' => ['nullable', 'date'], 'seo_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:300'], 'noindex' => ['nullable', 'boolean']]);
    }

    private static function saved(SitePost $post): string
    {
        return $post->status === 'draft' ? 'Draft saved.' : ($post->isLive() ? 'Published.' : 'Scheduled for '.$post->published_at?->format('M j, g:i A').'.');
    }

    private function site(): Site
    {
        return $this->sites->brokerageSite() ?? abort(404);
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && SiteAccess::manager($user), 403);

        return $user;
    }
}
