<?php

declare(strict_types=1);

namespace Modules\Sites\Visitors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\RetrievedRecord;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Contact;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Ai\ContentStudio;
use Modules\Sites\Models\SiteEvent;
use Modules\Sites\Models\SiteVisitor;
use Modules\Sites\Rendering\Urls;
use Modules\Sites\SiteSetup;

/**
 * The closing brief on a website lead (specs/websites/12): the chat with AIlly and the website journey turned into
 * what the agent needs to close: what they want, budget, timeline, financing, must-haves and concerns, the homes they
 * care about most, three similar homes to send next, the best time to reach them, an opening line and a checklist.
 * Facts come from code (homes, searches, matches); the AI writes the words, checked by the figure and Fair Housing
 * guard. Without AI a written version from the facts is shown.
 */
final class ClosingBrief
{
    public const FEATURE = 'sites.closing_brief';

    private const SCHEMA = ['type' => 'object', 'required' => ['summary', 'checklist'], 'properties' => [
        'summary' => ['type' => 'string'], 'budget' => ['type' => 'string'], 'timeline' => ['type' => 'string'], 'financing' => ['type' => 'string'],
        'must_haves' => ['type' => 'array', 'items' => ['type' => 'string']], 'concerns' => ['type' => 'array', 'items' => ['type' => 'string']],
        'best_time' => ['type' => 'string'], 'opening_line' => ['type' => 'string'], 'checklist' => ['type' => 'array', 'items' => ['type' => 'string']],
        'search' => ['type' => ['object', 'null']]]];

    public function __construct(private readonly AiGateway $gateway, private readonly TenantContext $tenants, private readonly JourneySummary $journeys,
        private readonly ListingSearch $search) {}

    public function generate(int $visitorId, bool $force = false): void
    {
        $visitor = SiteVisitor::query()->find($visitorId);
        if ($visitor === null || $visitor->merged_into !== null || $visitor->contact_id === null) {
            return;
        }
        if (! $force && $visitor->brief_at !== null && $visitor->brief_at->gt(CarbonImmutable::now()->subMinutes(15))) {
            return;
        }
        $facts = $this->journeys->facts($visitor);
        $chat = self::chat($visitor->id);
        if ($facts['homes_viewed'] === 0 && $facts['searches'] === [] && $facts['valuations'] === [] && $chat === '') {
            return;
        }
        $top = $this->topHomes($visitor->id);
        $json = [];
        $ai = false;
        $contact = Contact::query()->find($visitor->contact_id);
        try {
            $result = $this->gateway->run(new AiTask(self::FEATURE, 'sites.closing_brief', 'brief:'.$visitor->ulid.':'.CarbonImmutable::now()->format('YmdHi'),
                inputs: ['brokerage' => (string) $this->tenants->current()->name, 'today' => CarbonImmutable::now()->toFormattedDateString()],
                context: [RetrievedRecord::fromModel($visitor, ['journey' => (string) json_encode($facts)])],
                untrustedContent: $chat !== '' ? [$chat] : [], outputSchema: self::SCHEMA, userId: $contact?->owner_user_id));
            $json = (array) ($result->json ?? []);
            $ai = trim((string) ($json['summary'] ?? '')) !== '';
        } catch (\Throwable) {
            // No AI route, no credits, or blocked output: the written brief from the facts stands.
        }
        $allowed = ContentStudio::numbers((string) json_encode($facts).' '.$chat);
        $g = function (mixed $v, int $max) use ($allowed): string {
            [$out] = ContentStudio::guard(mb_substr(trim(strip_tags(is_scalar($v) ? (string) $v : '')), 0, $max), $allowed);

            return $out;
        };
        $list = fn (mixed $v, int $n): array => array_values(array_filter(array_map(fn ($x): string => $g($x, 160), array_slice(is_array($v) ? $v : [], 0, $n)), fn (string $s): bool => $s !== ''));
        $unknown = 'Not shared yet';
        $search = is_array($json['search'] ?? null) ? $json['search'] : ['q' => $facts['zips'][0] ?? null, 'beds' => $facts['beds'],
            'min_price' => $facts['price_typical'] !== null ? (int) round($facts['price_typical'] * 0.85) : null,
            'max_price' => $facts['price_high'] ?? ($facts['price_typical'] !== null ? (int) round($facts['price_typical'] * 1.15) : null)];

        $brief = [
            'ai' => $ai,
            'summary' => $ai ? $g($json['summary'], 500) : JourneySummary::written($facts),
            'budget' => $ai ? ($g($json['budget'] ?? '', 120) ?: $unknown) : self::budget($facts),
            'timeline' => $ai ? ($g($json['timeline'] ?? '', 120) ?: $unknown) : $unknown,
            'financing' => $ai ? ($g($json['financing'] ?? '', 120) ?: $unknown) : $unknown,
            'must_haves' => $ai ? $list($json['must_haves'] ?? [], 5) : [],
            'concerns' => $ai ? $list($json['concerns'] ?? [], 5) : [],
            'best_time' => $ai ? ($g($json['best_time'] ?? '', 120) ?: $unknown) : $unknown,
            'opening_line' => $ai ? $g($json['opening_line'] ?? '', 300) : '',
            'checklist' => $ai ? $list($json['checklist'] ?? [], 5) : self::checklist($facts, $top),
            'top_homes' => $top,
            'send_next' => $this->sendNext($search, array_column($top, 'ulid')),
        ];
        $first = $visitor->brief === null;
        $visitor->forceFill(['brief' => $brief, 'brief_at' => CarbonImmutable::now()])->save();
        if ($contact !== null && $first) {
            app(Timeline::class)->system($contact, 'Closing brief ready: '.$brief['summary']);
        }
    }

    /** The visitor's chats with AIlly (latest two), as text. */
    public static function chat(int $visitorId): string
    {
        $ids = DB::connection(Connections::cell())->table('ailly_conversations')->where('visitor_id', $visitorId)->orderByDesc('id')->limit(2)->pluck('id');
        if ($ids->isEmpty()) {
            return '';
        }

        return DB::connection(Connections::cell())->table('ailly_messages')->whereIn('conversation_id', $ids)->orderBy('id')->limit(60)->get(['role', 'body'])
            ->map(fn (object $m): string => ($m->role === 'visitor' ? 'Visitor: ' : 'Assistant: ').(string) $m->body)->implode("\n");
    }

    /**
     * The homes they viewed most (with links), from the journey.
     *
     * @return list<array{ulid: string, label: string, price: int|null, views: int, url: string|null}>
     */
    private function topHomes(int $visitorId): array
    {
        $host = app(SiteSetup::class)->liveHost();
        $homes = [];
        foreach (SiteEvent::query()->where('visitor_id', $visitorId)->whereIn('type', ['listing', 'favourite'])->whereNotNull('listing_ulid')->latest('id')->limit(500)->get() as $e) {
            $u = (string) $e->listing_ulid;
            $homes[$u] ??= ['ulid' => $u, 'label' => (string) ($e->data['label'] ?? 'A home'), 'price' => isset($e->data['price']) ? (int) $e->data['price'] : null, 'views' => 0,
                'url' => $host !== null ? 'https://'.$host.Urls::listing($u) : null];
            $homes[$u]['views'] += $e->type === 'favourite' ? 3 : 1; // a save counts for more than a view
        }
        usort($homes, fn (array $a, array $b): int => $b['views'] <=> $a['views']);

        return array_slice($homes, 0, 3);
    }

    /**
     * Three similar homes for sale they haven't viewed.
     *
     * @param  array<string, mixed>  $s
     * @param  list<string>  $seen
     * @return list<array{ulid: string, label: string, price: int|null, url: string|null}>
     */
    private function sendNext(array $s, array $seen): array
    {
        $found = $this->search->search($this->tenants->id(), array_filter(['q' => $s['q'] ?? null, 'min_price' => $s['min_price'] ?? null, 'max_price' => $s['max_price'] ?? null,
            'beds' => $s['beds'] ?? null, 'per_page' => 8], fn ($v): bool => $v !== null && $v !== ''));
        $out = [];
        foreach ($found['results'] as $h) {
            if (in_array($h['ulid'], $seen, true)) {
                continue;
            }
            $out[] = ['ulid' => (string) $h['ulid'], 'label' => ListingSearch::label($h), 'price' => isset($h['price']) ? (int) $h['price'] : null,
                'url' => ListingSearch::publicUrl((string) $h['ulid'], ListingSearch::label($h))];
            if (count($out) === 3) {
                break;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $f */
    private static function budget(array $f): string
    {
        return $f['price_low'] !== null && $f['price_high'] !== null ? 'Viewing homes from $'.number_format((int) $f['price_low']).' to $'.number_format((int) $f['price_high']) : 'Not shared yet';
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  list<array{label: string, views: int}>  $top
     * @return list<string>
     */
    private static function checklist(array $f, array $top): array
    {
        $out = [];
        if ($top !== [] && $top[0]['views'] > 1) {
            $out[] = 'Offer a showing of '.$top[0]['label'].'.';
        }
        $out[] = 'Send three similar homes (below) and ask what they liked.';
        $out[] = 'Ask about their timeline and whether they are pre-approved.';
        if ($f['valuations'] !== []) {
            $out[] = 'They checked a home value: offer a free market analysis.';
        }

        return $out;
    }
}
