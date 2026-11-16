<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePost;
use Modules\Sites\SiteAccess;

/**
 * The website blog (specs/websites/08). Posts are Markdown; raw HTML is stripped and unsafe links dropped when they
 * are turned into HTML, and headings get ids for the table of contents.
 */
final class Posts
{
    /**
     * @param  array<string, mixed>  $in
     */
    public function save(Site $site, ?SitePost $post, array $in, User $by): SitePost
    {
        if (! SiteAccess::manager($by)) {
            throw ValidationException::withMessages(['post' => 'Only owners and staff can write blog posts.']);
        }
        $title = mb_substr(trim(strip_tags((string) ($in['title'] ?? ''))), 0, 160);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Give the post a title.']);
        }
        $slug = Str::slug((string) ($in['slug'] ?? '') ?: $title);
        $slug = mb_substr($slug !== '' ? $slug : 'post', 0, 120);
        if (SitePost::query()->where('site_id', $site->id)->where('slug', $slug)->when($post !== null, fn ($q) => $q->whereKeyNot($post?->id))->exists()) {
            throw ValidationException::withMessages(['slug' => 'Another post already uses that address.']);
        }
        $status = ($in['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $when = null;
        if (is_string($in['published_at'] ?? null) && $in['published_at'] !== '') {
            try {
                $when = CarbonImmutable::parse($in['published_at']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['published_at' => 'Choose a valid date and time.']);
            }
        }
        $values = [
            'site_id' => $site->id, 'slug' => $slug, 'title' => $title,
            'excerpt' => mb_substr(trim(strip_tags((string) ($in['excerpt'] ?? ''))), 0, 300) ?: null,
            'body' => mb_substr((string) ($in['body'] ?? ''), 0, 100_000),
            'category' => mb_substr(trim(strip_tags((string) ($in['category'] ?? ''))), 0, 60) ?: null,
            'cover_media' => is_string($in['cover_media'] ?? null) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $in['cover_media']) === 1 ? $in['cover_media'] : null,
            'status' => $status,
            'published_at' => $status === 'published' ? ($when ?? $post->published_at ?? CarbonImmutable::now()) : $when,
            'seo_title' => mb_substr(trim(strip_tags((string) ($in['seo_title'] ?? ''))), 0, 70) ?: null,
            'meta_description' => mb_substr(trim(strip_tags((string) ($in['meta_description'] ?? ''))), 0, 300) ?: null,
            'noindex' => (bool) ($in['noindex'] ?? false),
        ];
        $post ??= new SitePost(['author_user_id' => $by->id]);
        $post->forceFill(['author_user_id' => $post->author_user_id ?? $by->id] + $values)->save();
        app(AuditLogger::class)->record('sites.post_saved', $post, metadata: ['status' => $status]);

        return $post;
    }

    /**
     * Safe HTML for a post, its table of contents and reading time.
     *
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>, words: int, minutes: int}
     */
    public static function render(string $markdown): array
    {
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
        $toc = [];
        $used = [];
        $html = (string) preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function (array $m) use (&$toc, &$used): string {
            $text = trim(html_entity_decode(strip_tags($m[2])));
            $id = Str::slug($text) ?: 'section';
            $base = $id;
            for ($n = 2; isset($used[$id]); $n++) {
                $id = $base.'-'.$n;
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'text' => $text, 'level' => (int) $m[1]];

            return '<h'.$m[1].' id="'.$id.'">'.$m[2].'</h'.$m[1].'>';
        }, $html);
        // Links leaving the site open safely; images load lazily.
        $html = (string) preg_replace('#<a href="(https?://[^"]+)"#', '<a href="$1" rel="noopener" target="_blank"', $html);
        $html = str_replace('<img ', '<img loading="lazy" decoding="async" ', $html);
        $words = str_word_count(strip_tags($html));

        return ['html' => $html, 'toc' => $toc, 'words' => $words, 'minutes' => max(1, (int) round($words / 230))];
    }
}
