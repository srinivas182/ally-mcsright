<?php

declare(strict_types=1);

namespace Modules\Sites\Ai;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\RetrievedRecord;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Search\MarketStats;
use Modules\Sites\Content\Pages;
use Modules\Sites\Content\Posts;
use Modules\Sites\Content\SeoCheck;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Models\SitePost;
use Modules\Sites\SiteAccess;

/**
 * The AI content studio (specs/websites/10): blog posts, pages and editing help, written through the AI gateway
 * (credits, redaction, output policy) and checked in code before anyone sees them:
 *  - numbers: the AI may only state figures it was given (market data, the brief, the page); any sentence with
 *    another number is removed and reported;
 *  - Fair Housing: sentences with steering or protected-class wording are removed and reported;
 *  - SEO and AEO: a draft scoring under 80 gets one rewrite with the exact fixes.
 * Everything lands as a draft; nothing is published by the AI.
 */
final class ContentStudio
{
    public const POST = 'sites.ai_post';

    public const PAGE = 'sites.ai_page';

    public const ASSIST = 'sites.ai_assist';

    public const MARKET = 'sites.ai_market';

    public const TARGET_SCORE = 80;

    private const POST_SCHEMA = ['type' => 'object', 'required' => ['title', 'excerpt', 'meta_description', 'body'], 'properties' => [
        'title' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'meta_description' => ['type' => 'string'], 'body' => ['type' => 'string'], 'category' => ['type' => 'string']]];

    private const PAGE_SCHEMA = ['type' => 'object', 'required' => ['title', 'meta_description', 'intro', 'blocks', 'faq'], 'properties' => [
        'title' => ['type' => 'string'], 'meta_description' => ['type' => 'string'], 'intro' => ['type' => 'string'],
        'blocks' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['heading' => ['type' => 'string'], 'body' => ['type' => 'string']]]],
        'faq' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string'], 'a' => ['type' => 'string']]]],
        'cta' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'button' => ['type' => 'string']]]]];

    private const ASSIST_SCHEMA = ['type' => 'object', 'required' => ['result'], 'properties' => ['result' => []]];

    public function __construct(private readonly AiGateway $gateway, private readonly TenantContext $tenants, private readonly MarketStats $stats) {}

    /**
     * A blog post draft from a brief: {topic, audience, tone, length, area, keywords}.
     *
     * @param  array<string, mixed>  $brief
     * @return array{post: SitePost, score: int, warnings: list<string>}
     */
    public function writePost(Site $site, array $brief, ?User $by, string $feature = self::POST): array
    {
        if ($by !== null && ! SiteAccess::canEdit($by, $site)) {
            throw ValidationException::withMessages(['brief' => 'You cannot write for this website.']);
        }
        $topic = self::text($brief['topic'] ?? '', 200);
        if ($topic === '') {
            throw ValidationException::withMessages(['topic' => 'Say what the post is about.']);
        }
        $facts = $this->facts($site, is_string($brief['area'] ?? null) ? $brief['area'] : null);
        $inputs = $this->inputs($site, $brief, $facts) + ['length' => match ($brief['length'] ?? 'medium') {
            'short' => '600 to 800', 'long' => '1,400 to 1,800', default => '900 to 1,200'
        }];
        $allowed = self::numbers($topic.' '.self::text($brief['keywords'] ?? '', 300).' '.implode(' ', $facts['lines']));
        $key = $feature.':'.$this->tenants->id().':'.Str::slug($topic).':'.CarbonImmutable::now()->format('YmdHi');
        $warnings = [];
        $draft = null;
        $score = 0;
        $fixes = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $json = $this->run($feature, 'sites.write_post', $key.':'.$attempt, $inputs + ['fixes' => $fixes], $site, $facts, self::POST_SCHEMA, $by);
            [$body, $w] = self::guard((string) ($json['body'] ?? ''), $allowed);
            [$excerpt, $w2] = self::guard(self::text($json['excerpt'] ?? '', 300), $allowed);
            [$meta, $w3] = self::guard(self::text($json['meta_description'] ?? '', 300), $allowed);
            $draft = new SitePost(['title' => self::text($json['title'] ?? $topic, 160) ?: $topic, 'excerpt' => $excerpt ?: null, 'meta_description' => $meta ?: null,
                'body' => $body, 'category' => self::text($json['category'] ?? ($brief['category'] ?? ''), 60) ?: null, 'noindex' => false]);
            $warnings = array_values(array_unique([...$w, ...$w2, ...$w3]));
            $check = SeoCheck::post($draft, $site->displayName());
            $score = $check['score'];
            if ($score >= self::TARGET_SCORE) {
                break;
            }
            $fixes = implode("\n", array_map(fn (array $c): string => '- '.$c['message'], array_filter($check['checks'], fn (array $c): bool => ! $c['ok'])));
        }
        $post = app(Posts::class)->save($site, null, ['title' => $draft->title, 'excerpt' => $draft->excerpt, 'body' => $draft->body, 'category' => $draft->category,
            'meta_description' => $draft->meta_description, 'status' => 'draft'], $by ?? self::systemAuthor($site));
        app(AuditLogger::class)->record('sites.ai_post_drafted', $post, metadata: ['score' => $score, 'warnings' => count($warnings), 'feature' => $feature]);

        return ['post' => $post, 'score' => $score, 'warnings' => $warnings];
    }

    /**
     * A page draft from a brief: {topic, path, audience, tone}.
     *
     * @param  array<string, mixed>  $brief
     * @return array{page: SitePage, score: int, warnings: list<string>}
     */
    public function writePage(Site $site, array $brief, User $by): array
    {
        if (! SiteAccess::canEdit($by, $site)) {
            throw ValidationException::withMessages(['brief' => 'You cannot write for this website.']);
        }
        $topic = self::text($brief['topic'] ?? '', 200);
        if ($topic === '') {
            throw ValidationException::withMessages(['topic' => 'Say what the page is about.']);
        }
        $facts = $this->facts($site, is_string($brief['area'] ?? null) ? $brief['area'] : null);
        $allowed = self::numbers($topic.' '.implode(' ', $facts['lines']));
        $json = $this->run(self::PAGE, 'sites.write_page', self::PAGE.':'.$this->tenants->id().':'.Str::slug($topic).':'.CarbonImmutable::now()->format('YmdHi'),
            $this->inputs($site, $brief, $facts), $site, $facts, self::PAGE_SCHEMA, $by);
        $warnings = [];
        $g = function (string $text) use ($allowed, &$warnings): string {
            [$out, $w] = self::guard($text, $allowed);
            $warnings = [...$warnings, ...$w];

            return $out;
        };
        $sections = [['type' => 'text', 'id' => 'answer', 'props' => ['title' => self::text($json['title'] ?? $topic, 120), 'body' => $g(self::text($json['intro'] ?? '', 6000))]]];
        foreach (array_slice((array) ($json['blocks'] ?? []), 0, 8) as $i => $b) {
            if (is_array($b) && self::text($b['body'] ?? '', 6000) !== '') {
                $sections[] = ['type' => 'text', 'id' => 'part-'.$i, 'props' => ['title' => $g(self::text($b['heading'] ?? '', 120)), 'body' => $g(self::text($b['body'] ?? '', 6000))]];
            }
        }
        $faq = array_values(array_filter(array_map(fn ($f): array => is_array($f) ? ['q' => $g(self::text($f['q'] ?? '', 200)), 'a' => $g(self::text($f['a'] ?? '', 1200))] : ['q' => '', 'a' => ''],
            array_slice((array) ($json['faq'] ?? []), 0, 10)), fn (array $f): bool => $f['q'] !== '' && $f['a'] !== ''));
        if ($faq !== []) {
            $sections[] = ['type' => 'faq', 'id' => 'faq', 'props' => ['title' => 'Questions and answers', 'items' => $faq]];
        }
        $cta = (array) ($json['cta'] ?? []);
        $sections[] = ['type' => 'cta', 'id' => 'next', 'props' => ['title' => self::text($cta['title'] ?? 'Talk to a local agent', 120) ?: 'Talk to a local agent',
            'body' => $g(self::text($cta['body'] ?? '', 300)), 'button' => self::text($cta['button'] ?? 'Contact us', 40) ?: 'Contact us', 'link' => '/contact']];
        $path = is_string($brief['path'] ?? null) && trim($brief['path']) !== '' ? (string) $brief['path'] : '/'.Str::slug($topic);
        $pages = app(Pages::class);
        $page = $pages->create($site, $path, self::text($json['title'] ?? $topic, 120) ?: $topic, $by);
        $page->forceFill(['meta_description' => $g(self::text($json['meta_description'] ?? '', 300)) ?: null])->save();
        $pages->saveDraft($page, $sections, $by);
        $score = SeoCheck::page($page->title, $page->meta_description, $page->refresh()->draft_sections ?? [], false, $site->displayName())['score'];
        app(AuditLogger::class)->record('sites.ai_page_drafted', $page, metadata: ['score' => $score]);

        return ['page' => $page, 'score' => $score, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Editing help: rewrite (improve, shorter, longer), faq, meta, bio.
     *
     * @param  array<string, mixed>  $in
     * @return array{result: mixed, warnings: list<string>}
     */
    public function assist(Site $site, string $action, array $in, User $by): array
    {
        if (! SiteAccess::canEdit($by, $site)) {
            throw ValidationException::withMessages(['action' => 'You cannot edit this website.']);
        }
        $content = self::text($in['content'] ?? '', 20000);
        $mode = in_array($in['mode'] ?? null, ['improve', 'shorter', 'longer'], true) ? (string) $in['mode'] : 'improve';
        if (! in_array($action, ['rewrite', 'faq', 'meta', 'bio'], true) || ($content === '' && $action !== 'bio')) {
            throw ValidationException::withMessages(['action' => 'Nothing to work with yet. Write a little first.']);
        }
        $profile = $action === 'bio' ? self::text(json_encode(array_intersect_key((array) ($in['profile'] ?? []), array_flip(['name', 'tagline', 'areas', 'languages', 'years', 'specialties', 'bio']))) ?: '', 3000) : '';
        $allowed = self::numbers($content.' '.$profile.' '.self::text($in['title'] ?? '', 200));
        $json = $this->run(self::ASSIST, 'sites.assist', self::ASSIST.':'.$this->tenants->id().':'.$action.':'.md5($content.$profile.$mode).':'.CarbonImmutable::now()->format('YmdHi'),
            ['action' => $action, 'mode' => $mode, 'title' => self::text($in['title'] ?? '', 200), 'brokerage' => $site->displayName(), 'today' => CarbonImmutable::now()->toFormattedDateString()],
            $site, ['lines' => [], 'records' => []], self::ASSIST_SCHEMA, $by, [$content, $profile]);
        $warnings = [];
        $g = function (string $text) use ($allowed, &$warnings): string {
            [$out, $w] = self::guard($text, $allowed);
            $warnings = [...$warnings, ...$w];

            return $out;
        };
        $r = $json['result'] ?? null;
        $result = match ($action) {
            'faq' => array_values(array_filter(array_map(fn ($f): array => is_array($f) ? ['q' => $g(self::text($f['q'] ?? '', 200)), 'a' => $g(self::text($f['a'] ?? '', 1200))] : ['q' => '', 'a' => ''],
                array_slice(is_array($r) ? $r : [], 0, 8)), fn (array $f): bool => $f['q'] !== '' && $f['a'] !== '')),
            'meta' => ['seo_title' => $g(self::text(is_array($r) ? ($r['seo_title'] ?? '') : '', 70)), 'meta_description' => $g(self::text(is_array($r) ? ($r['meta_description'] ?? '') : '', 160))],
            default => $g(self::text(is_string($r) ? $r : '', 8000)),
        };

        return ['result' => $result, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Market facts the AI may use (and nothing else numeric): the area's live figures with their date, and links.
     *
     * @return array{lines: list<string>, area: string|null, links: list<string>}
     */
    public function facts(Site $site, ?string $place): array
    {
        $lines = [];
        $links = ['/homes', '/home-value', '/contact'];
        $area = null;
        if ($place !== null && $place !== '' && ($m = $this->stats->forPlace($this->tenants->id(), $place)) !== null) {
            $area = $m['name'];
            $asOf = CarbonImmutable::parse($m['as_of'])->format('F j, Y');
            $lines[] = "Figures for {$m['name']} as of {$asOf}, from MLS listings:";
            $lines[] = 'Homes for sale: '.number_format($m['active']);
            if ($m['median_price'] !== null) {
                $lines[] = 'Median list price: $'.number_format($m['median_price']);
            }
            if ($m['low_price'] !== null && $m['high_price'] !== null) {
                $lines[] = 'Most homes are listed between $'.number_format($m['low_price']).' and $'.number_format($m['high_price']);
            }
            if ($m['median_ppsf'] !== null) {
                $lines[] = 'Median price per square foot: $'.number_format($m['median_ppsf']);
            }
            if ($m['median_dom'] !== null) {
                $lines[] = 'Typical days on market: '.$m['median_dom'];
            }
            $lines[] = 'New listings in the last 30 days: '.$m['new_30'];
            $lines[] = 'Share of homes for sale with a price cut in the last 30 days: '.$m['reduced_share'].'%';
            $links[] = '/market/'.$m['slug'];
            $links[] = '/homes?q='.urlencode($m['zip'] ?? $m['city'] ?? '');
        }

        return ['lines' => $lines, 'area' => $area, 'links' => $links];
    }

    /**
     * Keeps only sentences whose numbers were given; removes sentences with Fair Housing risk wording.
     *
     * @param  list<string>  $allowed  normalised numbers
     * @return array{0: string, 1: list<string>} the cleaned text and what was removed
     */
    public static function guard(string $text, array $allowed, string $channel = 'websites'): array
    {
        $warnings = [];
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $li => $line) {
            $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"“(*])/u', $line) ?: [$line];
            $keep = [];
            foreach ($parts as $sentence) {
                $bad = array_values(array_map(fn (array $f): string => $f[0], array_filter(self::figures($sentence), fn (array $f): bool => ! self::allowedNumber($f[0], $f[1], $allowed))));
                $flags = FairHousing::screen($sentence, $channel, 'removed', $channel === 'ailly' ? 'AIlly chat reply' : 'AI website content');
                if ($bad !== []) {
                    $warnings[] = 'Removed a sentence with a figure we could not verify ('.$bad[0].'): "'.Str::limit(trim($sentence), 90).'"';

                    continue;
                }
                if ($flags !== []) {
                    $warnings[] = 'Removed a sentence with Fair Housing risk wording ("'.$flags[0].'"): "'.Str::limit(trim($sentence), 90).'"';

                    continue;
                }
                $keep[] = $sentence;
            }
            $lines[$li] = implode(' ', $keep);
        }

        return [trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines))), $warnings];
    }

    /**
     * Numbers in text, normalised ("$450,000" → "450000", "12%" → "12", "2.5" → "2.5", "$1.2M" → "1200000").
     *
     * @return list<string>
     */
    public static function numbers(string $text): array
    {
        return array_values(array_unique(array_map(fn (array $f): string => $f[0], self::figures($text))));
    }

    /**
     * Figures in text: [normalised value, whether it is money or a percentage (never a plain count)].
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function figures(string $text): array
    {
        preg_match_all('/(?<![\w.])(\$)?(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?(\s*(?:k|m|million|thousand)\b)?(%)?/i', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            $n = str_replace(',', '', $match[2]).(isset($match[3]) && $match[3] !== '' ? '.'.$match[3] : '');
            $unit = strtolower(trim($match[4] ?? ''));
            if ($unit !== '') {
                $n = (string) ((float) $n * ($unit === 'k' || $unit === 'thousand' ? 1000 : 1000000));
            }
            if (str_contains($n, '.')) {
                $n = rtrim(rtrim($n, '0'), '.');
            }
            $out[] = [$n === '' ? '0' : $n, $match[1] !== '' || ($match[5] ?? '') !== '' || $unit !== ''];
        }

        return $out;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function allowedNumber(string $n, bool $moneyOrPercent, array $allowed): bool
    {
        if (in_array($n, $allowed, true)) {
            return true;
        }
        if ($moneyOrPercent) {
            return false; // prices and percentages must come from the data
        }
        $v = (float) $n;
        $year = (int) CarbonImmutable::now()->format('Y');

        // Small counts ("3 steps") and nearby years are fine; any other figure must come from the data.
        return ($v <= 12 && floor($v) === $v) || ($v >= $year - 3 && $v <= $year + 2 && floor($v) === $v);
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array{lines: list<string>, area: string|null, links: list<string>}  $facts
     * @return array<string, string>
     */
    private function inputs(Site $site, array $brief, array $facts): array
    {
        return ['brokerage' => $site->kind === 'agent' ? (string) ($site->info['name'] ?? '').' at '.(string) (Site::query()->where('kind', 'brokerage')->first()?->displayName() ?? '') : $site->displayName(),
            'service_area' => (string) ($site->info['area'] ?? $site->info['areas'] ?? ''), 'topic' => self::text($brief['topic'] ?? '', 200),
            'audience' => self::text($brief['audience'] ?? 'home buyers and sellers', 120) ?: 'home buyers and sellers',
            'tone' => in_array($brief['tone'] ?? null, ['friendly', 'expert', 'luxury'], true) ? (string) $brief['tone'] : 'friendly',
            'keywords' => self::text($brief['keywords'] ?? '', 300), 'area' => (string) ($facts['area'] ?? ''),
            'links' => implode(', ', $facts['links']), 'today' => CarbonImmutable::now()->toFormattedDateString()];
    }

    /**
     * @param  array<string, string>  $inputs
     * @param  array{lines: list<string>, area?: string|null, links?: list<string>, records?: array<mixed>}  $facts
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $untrusted
     * @return array<string, mixed>
     */
    private function run(string $feature, string $prompt, string $key, array $inputs, Site $site, array $facts, array $schema, ?User $by, array $untrusted = []): array
    {
        $context = $facts['lines'] !== [] ? [RetrievedRecord::fromModel($site, ['market_facts' => implode("\n", $facts['lines'])])] : [];
        $result = $this->gateway->run(new AiTask($feature, $prompt, $key, inputs: $inputs, context: $context, untrustedContent: array_values(array_filter($untrusted, fn (string $u): bool => $u !== '')),
            outputSchema: $schema, userId: $by?->id));

        return (array) ($result->json ?? []);
    }

    private static function systemAuthor(Site $site): User
    {
        return User::query()->whereKey($site->user_id)->first()
            ?? User::query()->where('kind', UserKind::Owner)->orderBy('id')->firstOrFail();
    }

    private static function text(mixed $v, int $max): string
    {
        return mb_substr(trim(strip_tags((string) (is_scalar($v) ? $v : ''))), 0, $max);
    }
}
