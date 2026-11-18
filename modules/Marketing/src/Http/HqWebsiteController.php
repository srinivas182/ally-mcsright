<?php

declare(strict_types=1);

namespace Modules\Marketing\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Marketing\Blocks;
use Modules\Marketing\Website;
use stdClass;

/** HQ → RightAlly website (SG1, specs/marketing/01): pages with drafts, preview, publish and versions; the blog. */
final class HqWebsiteController
{
    public function __construct(private readonly Website $site) {}

    public function index(): Response
    {
        $pages = $this->site->pages();
        $live = collect($pages)->filter(fn (stdClass $p): bool => $p->status === 'live')->map(fn (stdClass $p): string => $p->locale.':'.$p->slug)->all();

        return Inertia::render('hq/website/index', [
            'host' => ((array) config('marketing.hosts'))[0] ?? '',
            'pages' => array_map(fn (stdClass $p): array => ['ulid' => (string) $p->ulid, 'locale' => (string) $p->locale, 'slug' => (string) $p->slug,
                'title' => (string) ($p->draft_title ?? $p->title), 'status' => (string) $p->status, 'has_draft' => $p->draft_blocks !== null,
                'published_at' => $p->published_at, 'twin' => in_array(($p->locale === 'en' ? 'es' : 'en').':'.$p->slug, $live, true)], $pages),
            'posts' => array_values(DB::connection(Connections::control())->table('marketing_posts')->orderByDesc('updated_at')->limit(100)->get()
                ->map(fn (stdClass $p): array => ['ulid' => (string) $p->ulid, 'locale' => (string) $p->locale, 'slug' => (string) $p->slug, 'title' => (string) $p->title,
                    'status' => (string) $p->status, 'published_at' => $p->published_at])->all()),
        ]);
    }

    public function edit(?string $page = null): Response
    {
        $row = $page !== null ? collect($this->site->pages())->firstWhere('ulid', $page) : null;
        abort_if($page !== null && ! $row instanceof stdClass, 404);
        $blocks = $row instanceof stdClass ? json_decode((string) ($row->draft_blocks ?? $row->blocks ?? '[]'), true) : [];
        $title = $row instanceof stdClass ? (string) ($row->draft_title ?? $row->title) : '';
        $description = $row instanceof stdClass ? (string) ($row->draft_description ?? $row->description) : '';
        $locale = $row instanceof stdClass ? (string) $row->locale : 'en';
        $slug = $row instanceof stdClass ? (string) $row->slug : '';
        $twin = $row instanceof stdClass && $this->site->page($locale === 'en' ? 'es' : 'en', $slug)?->status === 'live';
        $host = ((array) config('marketing.hosts'))[0] ?? '';

        return Inertia::render('hq/website/page', [
            'page' => $row instanceof stdClass ? ['ulid' => (string) $row->ulid, 'locale' => $locale, 'slug' => $slug, 'title' => $title, 'description' => $description,
                'status' => (string) $row->status, 'has_draft' => $row->draft_blocks !== null,
                'preview' => $host !== '' ? URL::temporarySignedRoute('marketing.preview', now()->addHour(), ['ulid' => $row->ulid]) : null,
                'url' => 'https://'.$host.'/'.trim(($locale === 'es' ? 'es/' : '').$slug, '/')] : null,
            'blocks' => is_array($blocks) ? $blocks : [],
            'types' => Blocks::TYPES,
            'checks' => $row instanceof stdClass ? Website::check($locale, $slug, $title, $description, is_array($blocks) ? $blocks : [], $twin) : [],
            'versions' => $row instanceof stdClass ? $this->site->versions((string) $row->ulid) : [],
            'locales' => Website::LOCALES,
        ]);
    }

    public function save(Request $request, ?string $page = null): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:en,es'], 'slug' => ['nullable', 'string', 'max:80'], 'title' => ['required', 'string', 'max:70'],
            'description' => ['required', 'string', 'max:170'], 'blocks' => ['present', 'array', 'max:'.Blocks::MAX]]);
        $ulid = $this->site->saveDraft($page, $data['locale'], (string) ($data['slug'] ?? ''), $data['title'], $data['description'], $data['blocks'], $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft saved. Preview it, then publish.']);

        return redirect('/hq/website/pages/'.$ulid);
    }

    public function publish(Request $request, string $page): RedirectResponse
    {
        $this->site->publish($page, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Published. It is live on the website now.']);

        return back();
    }

    public function unpublish(Request $request, string $page): RedirectResponse
    {
        $this->site->unpublish($page, $this->user($request));

        return back();
    }

    public function restore(Request $request, string $page, int $version): RedirectResponse
    {
        $this->site->restore($page, $version, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'That version is back in the draft. Publish to make it live.']);

        return back();
    }

    public function post(?string $post = null): Response
    {
        $row = $post !== null ? DB::connection(Connections::control())->table('marketing_posts')->where('ulid', $post)->first() : null;
        abort_if($post !== null && $row === null, 404);

        return Inertia::render('hq/website/post', ['post' => $row !== null ? ['ulid' => $row->ulid, 'locale' => $row->locale, 'slug' => $row->slug, 'title' => $row->title,
            'description' => $row->description, 'body' => $row->body, 'author' => $row->author, 'status' => $row->status,
            'published_at' => $row->published_at] : null, 'locales' => Website::LOCALES]);
    }

    public function savePost(Request $request, ?string $post = null): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:en,es'], 'slug' => ['required', 'string', 'max:100'], 'title' => ['required', 'string', 'max:110'],
            'description' => ['required', 'string', 'max:170'], 'body' => ['required', 'string', 'max:100000'], 'author' => ['required', 'string', 'max:120'],
            'publish_at' => ['nullable', 'date'], 'live' => ['required', 'boolean']]);
        $ulid = $this->site->savePost($post, $data['locale'], $data['slug'], $data['title'], $data['description'], $data['body'], $data['author'], $data['publish_at'] ?? null,
            (bool) $data['live'], $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => (bool) $data['live'] ? 'Post saved and published.' : 'Draft saved.']);

        return redirect('/hq/website/posts/'.$ulid);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
