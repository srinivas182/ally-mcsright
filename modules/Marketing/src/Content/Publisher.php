<?php

declare(strict_types=1);

namespace Modules\Marketing\Content;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Support\SafeUrl;
use Modules\Marketing\Website;
use Modules\Sites\Content\Posts;
use Modules\Sites\Models\SitePost;
use Modules\Sites\SiteAccess;
use Modules\Sites\SiteSetup;
use Throwable;

/**
 * MK3: the morning review — a person edits, approves or rejects each draft. Approving publishes it (a landing page
 * under the state, or a blog post with the reviewer's name), then at once:
 *
 * - the sitemap and llms.txt already list it (they are built from what is live);
 * - search engines that accept instant notice are told (IndexNow: Bing, which ChatGPT search relies on, and others).
 *   Google reads the sitemap and the links from the state's pages; it offers no way to request a visit for ordinary
 *   pages;
 * - it is shared through the social sharing service HQ switched on (a Zapier or Make webhook that posts to LinkedIn
 *   and Facebook, or Buffer).
 */
final class Publisher
{
    public function __construct(private readonly Website $site, private readonly OutsideServices $services, private readonly AuditLogger $audit) {}

    /** @param array{title?: string, description?: string, body?: string} $edits */
    public function approve(string $ulid, User $by, array $edits = []): string
    {
        $db = DB::connection(Connections::control())->table('content_drafts');
        $d = $db->clone()->where('ulid', $ulid)->where('status', 'review')->where('tenant_id', 0)->first();
        if ($d === null) {
            throw ValidationException::withMessages(['draft' => 'This draft has already been answered.']);
        }
        // Test mode: approving saves it as a website draft for HQ to look at; nothing goes live or is announced.
        $live = DB::connection(Connections::control())->table('content_desks')->where('id', $d->desk_id)->value('mode') === 'live';
        $title = trim($edits['title'] ?? (string) $d->title);
        $description = trim($edits['description'] ?? (string) $d->description);
        $body = trim($edits['body'] ?? (string) $d->body);
        $faq = array_values(array_filter((array) json_decode((string) $d->faq, true), 'is_array'));
        $state = strtolower((string) $d->state);
        if ($d->kind === 'landing') {
            $slug = $state.'/'.mb_substr(ltrim(substr((string) $d->slug, strlen($state)), '-'), 0, 80);
            [$answer, $rest] = array_pad(explode("\n\n", $body, 2), 2, '');
            $blocks = [['type' => 'hero', 'heading' => mb_substr($title, 0, 120), 'text' => mb_substr($description, 0, 400), 'cta_label' => 'Choose a package', 'cta_href' => '/pricing',
                'second_label' => '', 'second_href' => ''],
                ['type' => 'answer', 'question' => mb_substr($title, 0, 160), 'answer' => mb_substr($answer, 0, 1200)]];
            foreach (preg_split('/^## /m', $rest) ?: [] as $chunk) {
                [$heading, $text] = array_pad(explode("\n", trim($chunk), 2), 2, '');
                if (trim($text) !== '') {
                    $blocks[] = ['type' => 'text', 'heading' => mb_substr(trim($heading), 0, 120), 'body' => mb_substr(trim($text), 0, 8000)];
                }
            }
            if ($faq !== []) {
                $blocks[] = ['type' => 'faq', 'heading' => '', 'items' => array_map(fn (array $q): array => ['q' => mb_substr((string) ($q['q'] ?? ''), 0, 200), 'a' => mb_substr((string) ($q['a'] ?? ''), 0, 1200)], $faq)];
            }
            $blocks[] = ['type' => 'pricing', 'heading' => '', 'note' => '', 'audience' => 'brokerage'];
            $page = $this->site->saveDraft(null, 'en', $slug, mb_substr($title, 0, 70), $description, $blocks, $by);
            if ($live) {
                $this->site->publish($page, $by);
            }
            $path = '/'.$slug;
        } else {
            $faqText = $faq === [] ? '' : "\n\n## Questions\n\n".implode("\n\n", array_map(fn (array $q): string => '**'.trim((string) ($q['q'] ?? '')).'** '.trim((string) ($q['a'] ?? '')), $faq));
            $slug = mb_substr((string) $d->slug, 0, 100);
            $this->site->savePost(null, 'en', $slug, mb_substr($title, 0, 70), $description, $body.$faqText, (string) $by->name, null, $live, $by);
            $path = '/blog/'.$slug;
        }
        $url = 'https://'.((array) config('marketing.hosts'))[0].$path;
        $db->clone()->where('id', $d->id)->update(['title' => mb_substr($title, 0, 120), 'description' => mb_substr($description, 0, 300), 'body' => $body, 'status' => 'published',
            'reviewed_by' => $by->id, 'reviewed_at' => now(), 'published_url' => $url, 'updated_at' => now()]);
        $this->audit->record('marketing.content.published', metadata: ['draft' => $ulid, 'url' => $url, 'live' => $live], severity: 'info');
        if ($live) {
            $this->announce($url, $title, $description);
        }

        return $url;
    }

    /**
     * MK4: a brokerage's owner or staff approves a draft from its own blog writer. It becomes a post on the brokerage's
     * website with the author named and a short "who wrote this" line; in Test mode it stays a website draft.
     *
     * @param  array{title?: string, description?: string, body?: string}  $edits
     */
    public function approveForTenant(int $tenantId, string $ulid, User $by, array $edits = []): string
    {
        if (! SiteAccess::manager($by)) {
            throw ValidationException::withMessages(['draft' => 'Your broker approves posts before they go on the website.']);
        }
        $db = DB::connection(Connections::control())->table('content_drafts');
        $d = $db->clone()->where('ulid', $ulid)->where('status', 'review')->where('tenant_id', $tenantId)->first();
        if ($tenantId < 1 || $d === null) {
            throw ValidationException::withMessages(['draft' => 'This draft has already been answered.']);
        }
        $sites = app(SiteSetup::class);
        $site = $sites->brokerageSite();
        if ($site === null) {
            throw ValidationException::withMessages(['draft' => 'Set up your website first (Settings → Website).']);
        }
        $desk = DB::connection(Connections::control())->table('content_desks')->where('id', $d->desk_id)->where('tenant_id', $tenantId)->first();
        $live = $desk?->mode === 'live';
        $agent = $desk?->user_id !== null ? User::query()->find((int) $desk->user_id) : null;
        $info = (array) $site->info;
        $title = trim($edits['title'] ?? (string) $d->title);
        $description = trim($edits['description'] ?? (string) $d->description);
        $body = trim($edits['body'] ?? (string) $d->body);
        $faq = array_values(array_filter((array) json_decode((string) $d->faq, true), 'is_array'));
        $faqText = $faq === [] ? '' : "\n\n## Questions\n\n".implode("\n\n", array_map(fn (array $q): string => '**'.trim((string) ($q['q'] ?? '')).'** '.trim((string) ($q['a'] ?? '')), $faq));
        $licence = trim((string) ($info['licence'] ?? ''));
        $byline = "\n\n---\n\n*Written for ".trim((string) ($info['name'] ?? '')).($agent !== null ? ' by '.$agent->name : '')
            .($licence !== '' ? ' (licence '.$licence.')' : '').'. Market figures come from MLS listings and change daily; ask us for today\'s numbers.*';
        $slug = mb_substr(Str::slug($title), 0, 100);
        for ($n = 2; SitePost::query()->where('site_id', $site->id)->where('slug', $slug)->exists(); $n++) {
            $slug = mb_substr(Str::slug($title), 0, 95).'-'.$n;
        }
        $post = app(Posts::class)->save($site, null, ['title' => mb_substr($title, 0, 160), 'slug' => $slug, 'excerpt' => $description, 'meta_description' => $description,
            'seo_title' => mb_substr($title, 0, 70), 'category' => 'Local guides', 'body' => $body.$faqText.$byline, 'status' => $live ? 'published' : 'draft'], $by);
        if ($agent !== null) {
            $post->forceFill(['author_user_id' => $agent->id])->save();
        }
        $host = $sites->liveHost();
        $url = $host !== null ? 'https://'.$host.'/blog/'.$post->slug : '/blog/'.$post->slug;
        $db->clone()->where('id', $d->id)->update(['title' => mb_substr($title, 0, 120), 'description' => mb_substr($description, 0, 300), 'body' => $body, 'status' => 'published',
            'reviewed_by' => $by->id, 'reviewed_at' => now(), 'published_url' => $url, 'ref' => $post->ulid, 'updated_at' => now()]);
        $this->audit->record('marketing.blog_writer.published', $post, metadata: ['draft' => $ulid, 'live' => $live], severity: 'info');
        if ($live && $host !== null) {
            // Search engines are told at once; sharing to social media stays with the brokerage's own tools.
            $this->announce($url, $title, $description, share: false, perHost: true);
        }

        return $url;
    }

    public function reject(string $ulid, User $by, string $why, int $tenantId = 0): void
    {
        DB::connection(Connections::control())->table('content_drafts')->where('ulid', $ulid)->where('status', 'review')->where('tenant_id', $tenantId)
            ->update(['status' => 'rejected', 'note' => mb_substr(trim($why) ?: 'Rejected', 0, 300), 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'updated_at' => now()]);
    }

    /** The IndexNow key, served at /indexnow-key.txt on RightAlly's website (and, per host, on every brokerage website). */
    public static function indexNowKey(?string $host = null): string
    {
        return substr(hash('sha256', (string) config('app.key').'|indexnow'.($host !== null ? '|'.strtolower($host) : '')), 0, 32);
    }

    /** Tell search engines and share; failures are reported, never block publishing. */
    public function announce(string $url, string $title, string $summary, bool $share = true, bool $perHost = false): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        try {
            Http::timeout(10)->post('https://api.indexnow.org/indexnow', ['host' => $host, 'key' => self::indexNowKey($perHost ? $host : null),
                'keyLocation' => 'https://'.$host.'/indexnow-key.txt', 'urlList' => [$url]]);
        } catch (Throwable $e) {
            report($e);
        }
        $share = $share ? $this->services->active('social_share') : null;
        if ($share === null) {
            return;
        }
        try {
            $payload = ['title' => $title, 'url' => $url, 'summary' => $summary, 'source' => 'RightAlly content desk'];
            if ($share['provider'] === 'buffer') {
                Http::timeout(15)->asForm()->post('https://api.bufferapp.com/1/updates/create.json', ['access_token' => $share['keys']['access_token'] ?? '',
                    'text' => $title.' '.$url, 'profile_ids' => array_filter(array_map('trim', explode(',', $share['keys']['profile_ids'] ?? '')))]);
            } else {
                $hook = SafeUrl::check((string) ($share['keys']['webhook_url'] ?? ''));
                Http::timeout(15)->post($hook['url'], $payload);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
