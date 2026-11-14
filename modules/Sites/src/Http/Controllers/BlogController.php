<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Content\Posts;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePost;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;

/**
 * The public blog (specs/websites/08): index and categories (12 a page), posts with a table of contents, Article
 * structured data and related posts, and an RSS feed. Scheduled posts appear when their time comes.
 */
final class BlogController
{
    private const PER_PAGE = 12;

    public function __construct(private readonly TenantContext $tenants, private readonly MediaLibrary $media) {}

    public function index(Request $request): Response
    {
        return $this->listing($request, null);
    }

    public function category(Request $request, string $category): Response
    {
        return $this->listing($request, $category);
    }

    public function show(Request $request, string $slug): Response
    {
        $site = $this->site();
        if (! $site instanceof Site) {
            return $site;
        }
        $post = SitePost::query()->where('site_id', $site->id)->where('slug', $slug)->live()->first();
        if ($post === null) {
            return response()->view('sites::missing', ['site' => $site], 404)->header('Cache-Control', 'public, max-age=300');
        }

        return $this->render($request, $site, $post, true)
            ->header('Cache-Control', 'public, max-age=60, s-maxage='.config('sites.cache_seconds').', stale-while-revalidate='.config('sites.stale_seconds'));
    }

    /** A signed, private preview of a post that is not live yet. */
    public function preview(Request $request, string $post): Response
    {
        abort_unless($request->hasValidSignature(false), 403);
        $row = SitePost::query()->where('ulid', $post)->firstOrFail();
        $site = Site::query()->whereKey($row->site_id)->firstOrFail();

        return $this->render($request, $site, $row, false)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function feed(Request $request): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        $base = $request->getSchemeAndHttpHost();
        $items = '';
        if ($site !== null) {
            foreach (SitePost::query()->where('site_id', $site->id)->live()->where('noindex', false)->orderByDesc('published_at')->limit(30)->get() as $p) {
                $items .= '<item><title>'.e($p->title).'</title><link>'.e($base.'/blog/'.$p->slug).'</link><guid isPermaLink="true">'.e($base.'/blog/'.$p->slug).'</guid>'
                    .'<pubDate>'.$p->published_at?->toRfc2822String().'</pubDate><description>'.e($p->excerpt ?? Str::limit(strip_tags(Posts::render($p->body)['html']), 280)).'</description>'
                    .($p->category !== null ? '<category>'.e($p->category).'</category>' : '').'</item>';
            }
        }
        $title = $site !== null ? e($site->displayName()).' blog' : 'Blog';

        return response('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>'.$title.'</title><link>'.e($base.'/blog').'</link>'
            .'<atom:link href="'.e($base.'/blog/feed.xml').'" rel="self" type="application/rss+xml"/><description>'.$title.'</description><language>en-us</language>'.$items.'</channel></rss>', 200,
            ['Content-Type' => 'application/rss+xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }

    private function listing(Request $request, ?string $category): Response
    {
        $site = $this->site();
        if (! $site instanceof Site) {
            return $site;
        }
        $q = SitePost::query()->where('site_id', $site->id)->live()->orderByDesc('published_at');
        $label = null;
        if ($category !== null) {
            $label = SitePost::query()->where('site_id', $site->id)->live()->whereNotNull('category')->distinct()->pluck('category')
                ->first(fn ($c): bool => Str::slug((string) $c) === $category);
            if ($label === null) {
                return response()->view('sites::missing', ['site' => $site], 404);
            }
            $q->where('category', $label);
        }
        $page = max(1, min(200, (int) $request->query('page', 1)));
        $total = (clone $q)->count();
        $posts = $q->forPage($page, self::PER_PAGE)->get();
        $path = $category !== null ? '/blog/category/'.$category : '/blog';
        $title = ($label !== null ? (string) $label.' articles' : 'Real estate blog').' | '.(string) ($site->seo['title_suffix'] ?? $site->displayName());
        $categories = SitePost::query()->where('site_id', $site->id)->live()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')
            ->map(fn ($c): array => ['name' => (string) $c, 'slug' => Str::slug((string) $c)])->values()->all();

        return response()->view('sites::blog.index', [
            'site' => $site, 'current' => '/blog', 'posts' => $posts->map(fn (SitePost $p): array => $this->card($p))->all(), 'categories' => $categories, 'label' => $label,
            'prev' => $page > 1 ? $path.($page > 2 ? '?page='.($page - 1) : '') : null, 'next' => $page * self::PER_PAGE < $total ? $path.'?page='.($page + 1) : null,
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), $path.($page > 1 ? '?page='.$page : ''), $title,
                'Advice and local insight on buying, selling and the housing market from '.$site->displayName().'.', null, $total > 0,
                $label !== null ? [['Blog', '/blog'], [(string) $label, $path]] : [['Blog', '/blog']]),
            'chrome' => Chrome::for($site, $this->tenants->id()),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    private function render(Request $request, Site $site, SitePost $post, bool $indexable): Response
    {
        $base = $request->getSchemeAndHttpHost();
        $r = Posts::render($post->body);
        $author = $post->author_user_id !== null ? User::query()->find($post->author_user_id) : null;
        $cover = $this->media->url($post->cover_media, 'hero');
        $path = '/blog/'.$post->slug;
        $description = $post->meta_description ?: $post->excerpt ?: Str::limit(strip_tags($r['html']), 155);
        $related = SitePost::query()->where('site_id', $site->id)->live()->whereKeyNot($post->id)
            ->when($post->category !== null, fn ($q) => $q->where('category', $post->category))->orderByDesc('published_at')->limit(3)->get();
        $article = array_filter(['@type' => 'BlogPosting', '@id' => $base.$path.'#article', 'headline' => mb_substr($post->title, 0, 110), 'description' => $description,
            'image' => $cover, 'datePublished' => $post->published_at?->toIso8601String(), 'dateModified' => $post->updated_at?->toIso8601String(),
            'author' => $author !== null ? ['@type' => 'Person', 'name' => $author->name] : ['@id' => rtrim($base, '/').'/#organization'],
            'publisher' => ['@id' => rtrim($base, '/').'/#organization'], 'mainEntityOfPage' => $base.$path, 'wordCount' => $r['words'],
            'articleSection' => $post->category], fn ($v): bool => $v !== null);

        return response()->view('sites::blog.show', [
            'site' => $site, 'current' => '/blog', 'post' => $post, 'html' => $r['html'], 'toc' => $r['toc'], 'minutes' => $r['minutes'], 'cover' => $cover,
            'coverAlt' => $this->media->alt($post->cover_media, $post->title), 'author' => $author?->name, 'related' => $related->map(fn (SitePost $p): array => $this->card($p))->all(),
            'seo' => Seo::build($site, $base, $path, ($post->seo_title ?: $post->title).' | '.(string) ($site->seo['title_suffix'] ?? $site->displayName()), $description, $cover,
                $indexable && ! $post->noindex, array_values(array_filter([['Blog', '/blog'], $post->category !== null ? [$post->category, '/blog/category/'.Str::slug($post->category)] : null,
                    [$post->title, $path]])), [], [$article], $post->updated_at?->toIso8601String(), 'article'),
            'chrome' => Chrome::for($site, $this->tenants->id()),
            ...SiteController::form($request),
        ]);
    }

    /**
     * @return array{slug: string, title: string, excerpt: string, category: string|null, date: string|null, cover: string|null, alt: string}
     */
    private function card(SitePost $p): array
    {
        return ['slug' => $p->slug, 'title' => $p->title, 'excerpt' => $p->excerpt ?? Str::limit(strip_tags(Posts::render($p->body)['html']), 160),
            'category' => $p->category, 'date' => $p->published_at?->format('M j, Y'), 'cover' => $this->media->url($p->cover_media, 'card'),
            'alt' => $this->media->alt($p->cover_media, $p->title)];
    }

    private function site(): Site|Response
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
