<?php

declare(strict_types=1);

namespace Modules\Marketing\Content;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Support\SafeUrl;
use Modules\Marketing\Website;
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

    public function reject(string $ulid, User $by, string $why): void
    {
        DB::connection(Connections::control())->table('content_drafts')->where('ulid', $ulid)->where('status', 'review')->where('tenant_id', 0)
            ->update(['status' => 'rejected', 'note' => mb_substr(trim($why) ?: 'Rejected', 0, 300), 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'updated_at' => now()]);
    }

    /** The IndexNow key, served at /indexnow-key.txt on RightAlly's website. */
    public static function indexNowKey(): string
    {
        return substr(hash('sha256', (string) config('app.key').'|indexnow'), 0, 32);
    }

    /** Tell search engines and share; failures are reported, never block publishing. */
    public function announce(string $url, string $title, string $summary): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        try {
            Http::timeout(10)->post('https://api.indexnow.org/indexnow', ['host' => $host, 'key' => self::indexNowKey(),
                'keyLocation' => 'https://'.$host.'/indexnow-key.txt', 'urlList' => [$url]]);
        } catch (Throwable $e) {
            report($e);
        }
        $share = $this->services->active('social_share');
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
