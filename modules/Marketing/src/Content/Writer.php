<?php

declare(strict_types=1);

namespace Modules\Marketing\Content;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Marketing\ClaimsCheck;
use stdClass;

/**
 * MK3: writes the day's drafts for a desk and checks each one before any person sees it:
 *
 * - SEO and AEO shape: a direct answer, 4–6 sections, 3–5 questions, a title under 65 characters and a 120–155
 *   character description;
 * - at least 3 facts, each with an https source (shown to the reviewer to check quickly);
 * - no other company named and never "cheap" (ClaimsCheck); Fair Housing wording;
 * - not like anything already written in the state, by HQ or any tenant (Similarity::MAX).
 *
 * A draft that fails is rewritten (at most twice) with the reason; if it still fails the day's slot is skipped and the
 * reason kept — skipping a day is always better than publishing a weak or lookalike page.
 */
final class Writer
{
    public const FEATURE = 'marketing.content';

    private const SCHEMA = ['type' => 'object', 'required' => ['title', 'description', 'answer', 'sections', 'faq', 'facts']];

    public function __construct(private readonly AiGateway $ai) {}

    /**
     * Writes as many drafts as the desk may today. Returns how many reached the review queue.
     *
     * @param  array<string, array{0: string, 1: string, 2: list<string>}>  $catalog
     */
    public function writeFor(stdClass $desk, array $catalog, string $site, string $audience): int
    {
        $written = 0;
        for ($i = Cadence::remainingToday($desk); $i > 0; $i--) {
            $kinds = DB::connection(Connections::control())->table('content_drafts')->where('desk_id', $desk->id)->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->pluck('kind')->all();
            $prefer = in_array('landing', $kinds, true) && ! in_array('article', $kinds, true) ? 'article' : 'landing';
            $topic = Topics::next($desk, $catalog, $prefer);
            if ($topic === null) {
                break;
            }
            $written += $this->draft($desk, $topic, $site, $audience) ? 1 : 0;
        }

        return $written;
    }

    /** @param array{city: string, topic: string, angle: string, kind: string, title: string} $topic */
    public function draft(stdClass $desk, array $topic, string $site, string $audience, string $extra = ''): bool
    {
        $state = Topics::STATES[(string) $desk->state] ?? (string) $desk->state;
        $others = DB::connection(Connections::control())->table('content_drafts')->where('state', (string) $desk->state)->whereIn('status', ['review', 'published'])
            ->orderByDesc('id')->limit(300)->get(['title', 'body']);
        $problem = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            // AI runs (and is charged) as the brokerage the desk belongs to, or as HQ for HQ's desks.
            $owner = (int) $desk->tenant_id > 0 ? Tenant::query()->find((int) $desk->tenant_id) : Tenant::query()->where('type', TenantType::Hq)->first();
            $task = new AiTask(self::FEATURE, 'marketing.content_desk', 'content:'.$desk->id.':'.$topic['topic'].':'.$topic['angle'].':'.$attempt.':'.now()->format('Ymd'),
                inputs: ['kind' => $topic['kind'] === 'landing' ? 'landing page' : 'article', 'site' => $site, 'audience' => $audience, 'place' => $topic['city'], 'state' => $state,
                    'title' => $topic['title'], 'angle' => str_replace('-', ' ', $topic['angle']), 'avoid' => $others->pluck('title')->take(40)->implode('; '),
                    'extra' => trim($extra.($problem !== '' ? "\nYour last draft was rejected: {$problem} Fix that." : ''))],
                outputSchema: self::SCHEMA);
            $result = $owner !== null ? app(TenantContext::class)->runAs($owner, fn () => $this->ai->run($task)) : $this->ai->run($task);
            $d = (array) ($result->json ?? []);
            $body = self::body($d);
            $similarity = 0.0;
            $problem = $this->problem($d, $body, array_values(array_map('strval', $others->pluck('body')->all())), $similarity);
            if ($problem === '') {
                DB::connection(Connections::control())->table('content_drafts')->insert(['ulid' => (string) Str::ulid(), 'desk_id' => $desk->id, 'tenant_id' => (int) $desk->tenant_id,
                    'state' => (string) $desk->state, 'city' => $topic['city'], 'kind' => $topic['kind'], 'topic' => $topic['topic'], 'title' => mb_substr((string) $d['title'], 0, 120),
                    'slug' => Topics::slug((string) $desk->state, (string) $d['title'], $topic['angle']), 'description' => mb_substr((string) $d['description'], 0, 300), 'body' => $body,
                    'faq' => json_encode(array_values((array) $d['faq'])), 'facts' => json_encode(array_values((array) $d['facts'])),
                    'checks' => json_encode(['attempts' => $attempt + 1, 'similarity' => $similarity, 'answer' => (string) $d['answer']]), 'similarity' => $similarity,
                    'status' => 'review', 'created_at' => now(), 'updated_at' => now()]);

                return true;
            }
        }
        DB::connection(Connections::control())->table('content_drafts')->insert(['ulid' => (string) Str::ulid(), 'desk_id' => $desk->id, 'tenant_id' => (int) $desk->tenant_id,
            'state' => (string) $desk->state, 'city' => $topic['city'], 'kind' => $topic['kind'], 'topic' => $topic['topic'], 'title' => mb_substr($topic['title'], 0, 120),
            'slug' => '', 'description' => '', 'body' => '', 'status' => 'skipped', 'note' => mb_substr('Skipped: '.$problem, 0, 300), 'created_at' => now(), 'updated_at' => now()]);

        return false;
    }

    /** @param array<string, mixed> $d */
    public static function body(array $d): string
    {
        $out = trim((string) ($d['answer'] ?? ''))."\n\n";
        foreach ((array) ($d['sections'] ?? []) as $s) {
            $out .= '## '.trim((string) (((array) $s)['heading'] ?? ''))."\n\n".trim((string) (((array) $s)['body'] ?? ''))."\n\n";
        }

        return trim($out);
    }

    /**
     * Why the draft cannot go to review, or '' when it can.
     *
     * @param  array<string, mixed>  $d
     * @param  list<string>  $others
     */
    private function problem(array $d, string $body, array $others, float &$similarity): string
    {
        $similarity = 0.0;
        $title = trim((string) ($d['title'] ?? ''));
        $description = trim((string) ($d['description'] ?? ''));
        $answer = trim((string) ($d['answer'] ?? ''));
        $sections = (array) ($d['sections'] ?? []);
        $faq = (array) ($d['faq'] ?? []);
        $facts = array_filter((array) ($d['facts'] ?? []), fn ($f): bool => is_array($f) && trim((string) ($f['fact'] ?? '')) !== '' && str_starts_with((string) ($f['source'] ?? ''), 'https://'));
        if ($title === '' || mb_strlen($title) > 65 || mb_strlen($description) < 120 || mb_strlen($description) > 155) {
            return 'The title must be under 65 characters and the description 120 to 155 characters.';
        }
        $words = str_word_count($answer);
        if ($words < 30 || $words > 70 || count($sections) < 4 || count($sections) > 6 || count($faq) < 3 || count($faq) > 5) {
            return 'It needs a 40 to 60 word answer first, 4 to 6 sections and 3 to 5 questions.';
        }
        if (count($facts) < 3) {
            return 'It needs at least 3 facts, each with an https source.';
        }
        $all = $title."\n".$description."\n".$body."\n".implode("\n", array_map(fn ($q): string => implode(' ', (array) $q), $faq));
        $claims = ClaimsCheck::problem($all);
        if ($claims !== null) {
            return $claims;
        }
        $flags = FairHousing::screen($all, 'ai_content', 'blocked', 'AI content desk');
        if ($flags !== []) {
            return 'Fair Housing: remove "'.implode('", "', $flags).'" (describe places, not who should live there).';
        }
        $similarity = Similarity::highest($body, $others);
        if ($similarity > Similarity::MAX) {
            return 'It is too like something already written in this state ('.(int) round($similarity * 100).'% the same). Write it differently.';
        }

        return '';
    }
}
