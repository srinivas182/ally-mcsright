<?php

declare(strict_types=1);

namespace Modules\Sites\Visitors;

use Carbon\CarbonImmutable;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\RetrievedRecord;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Leads\Attribution;
use Modules\Crm\Models\Contact;
use Modules\Sites\Models\SiteEvent;
use Modules\Sites\Models\SiteVisitor;

/**
 * The journey summary for agents (specs/websites/07): the facts are always worked out in code (homes viewed most,
 * price range, areas, beds, searches, saves, comparisons, payment estimates); the AI turns them into two sentences
 * and a next step through the gateway (credits, redaction, output policy). Without AI, a written summary from the
 * same facts is used, so agents always get one.
 */
final class JourneySummary
{
    public const FEATURE = 'sites.journey_summary';

    private const SCHEMA = ['type' => 'object', 'required' => ['summary', 'next_step'],
        'properties' => ['summary' => ['type' => 'string'], 'next_step' => ['type' => 'string']]];

    public function __construct(private readonly AiGateway $gateway, private readonly TenantContext $tenants) {}

    public function summarise(int $visitorId): void
    {
        $visitor = SiteVisitor::query()->find($visitorId);
        if ($visitor === null || $visitor->merged_into !== null) {
            return;
        }
        if ($visitor->summary_at !== null && $visitor->summary_at->gt(CarbonImmutable::now()->subMinutes(10))) {
            return; // refreshed moments ago
        }
        $facts = $this->facts($visitor);
        if ($facts['homes_viewed'] === 0 && $facts['searches'] === [] && $facts['valuations'] === []) {
            return; // nothing to summarise yet
        }
        $summary = self::written($facts);
        $ai = false;
        $contact = $visitor->contact_id !== null ? Contact::query()->find($visitor->contact_id) : null;
        try {
            $result = $this->gateway->run(new AiTask(self::FEATURE, 'sites.journey_summary', 'journey:'.$visitor->ulid.':'.CarbonImmutable::now()->format('YmdHi'),
                inputs: ['brokerage' => (string) $this->tenants->current()->name, 'today' => CarbonImmutable::now()->toFormattedDateString()],
                context: [RetrievedRecord::fromModel($visitor, ['journey' => (string) json_encode($facts)])],
                outputSchema: self::SCHEMA, userId: $contact?->owner_user_id));
            $text = trim((string) ($result->json['summary'] ?? ''));
            $next = trim((string) ($result->json['next_step'] ?? ''));
            if ($text !== '') {
                $summary = mb_substr($text.($next !== '' ? "\nNext step: ".$next : ''), 0, 1200);
                $ai = true;
            }
        } catch (\Throwable) {
            // No AI route, no credits, or blocked output: the written summary stands.
        }
        $first = $visitor->summary === null;
        $visitor->forceFill(['summary' => $summary, 'summary_ai' => $ai, 'summary_at' => CarbonImmutable::now()])->save();
        if ($contact !== null && $first) {
            app(Timeline::class)->system($contact, 'Website journey: '.$summary);
        }
    }

    /**
     * What the visitor did, in numbers and names (no personal details: the summary is about homes, not the person).
     *
     * @return array{visits: int, first_seen: string|null, last_seen: string|null, came_from: string, homes_viewed: int, top_homes: list<array{label: string, price: int|null, beds: int|null, zip: string|null, views: int}>, price_low: int|null, price_high: int|null, price_typical: int|null, zips: list<string>, beds: int|null, searches: list<string>, saved: list<string>, compared: list<string>, payments: list<string>, valuations: list<string>}
     */
    public function facts(SiteVisitor $visitor): array
    {
        $events = SiteEvent::query()->where('visitor_id', $visitor->id)->latest('id')->limit(1000)->get();
        $homes = [];
        foreach ($events->where('type', 'listing') as $e) {
            $u = (string) $e->listing_ulid;
            $homes[$u] ??= ['label' => (string) ($e->data['label'] ?? 'a home'), 'price' => isset($e->data['price']) ? (int) $e->data['price'] : null,
                'beds' => isset($e->data['beds']) ? (int) $e->data['beds'] : null, 'zip' => isset($e->data['zip']) ? (string) $e->data['zip'] : null, 'views' => 0];
            $homes[$u]['views']++;
        }
        uasort($homes, fn (array $a, array $b): int => $b['views'] <=> $a['views']);
        $prices = array_values(array_filter(array_column($homes, 'price'), fn ($p): bool => $p !== null));
        sort($prices);
        $zips = array_count_values(array_values(array_filter(array_column($homes, 'zip'), fn ($z): bool => $z !== null)));
        arsort($zips);
        $beds = array_count_values(array_values(array_filter(array_column($homes, 'beds'), fn ($b): bool => $b !== null)));
        arsort($beds);
        $label = static fn (SiteEvent $e): string => (string) ($e->data['label'] ?? 'a home');

        return [
            'visits' => $visitor->visits, 'first_seen' => $visitor->first_seen_at?->toDateString(), 'last_seen' => $visitor->last_seen_at?->toDateString(),
            'came_from' => Attribution::channel($visitor->first_referrer, [], 'website'),
            'homes_viewed' => count($homes), 'top_homes' => array_slice(array_values($homes), 0, 8),
            'price_low' => $prices[0] ?? null, 'price_high' => $prices !== [] ? $prices[count($prices) - 1] : null,
            'price_typical' => $prices !== [] ? $prices[intdiv(count($prices), 2)] : null,
            'zips' => array_slice(array_map('strval', array_keys($zips)), 0, 3), 'beds' => $beds !== [] ? (int) array_key_first($beds) : null,
            'searches' => array_slice(array_values(array_unique($events->where('type', 'search')->map(fn (SiteEvent $e): string => self::describeSearch($e->data ?? []))
                ->filter(fn (string $s): bool => $s !== '')->all())), 0, 6),
            'saved' => array_values(array_unique($events->where('type', 'favourite')->map($label)->all())),
            'compared' => array_values(array_unique($events->where('type', 'compare')->map(fn (SiteEvent $e): string => implode(' vs ', (array) ($e->data['labels'] ?? [])))->all())),
            'payments' => array_values(array_unique($events->where('type', 'mortgage')->map($label)->all())),
            'valuations' => array_values(array_unique($events->where('type', 'valuation')->map(fn (SiteEvent $e): string => (string) ($e->data['address'] ?? 'their home'))->all())),
        ];
    }

    /**
     * Two or three plain sentences from the facts.
     *
     * @param  array<string, mixed>  $f
     */
    public static function written(array $f): string
    {
        $money = static fn (?int $v): string => $v === null ? '' : ($v >= 1_000_000 ? '$'.rtrim(rtrim(number_format($v / 1_000_000, 2), '0'), '.').'M' : '$'.number_format((int) round($v / 1000)).'k');
        $out = [];
        if ($f['homes_viewed'] > 0) {
            $what = ($f['beds'] !== null ? $f['beds'].'-bed homes' : 'homes').($f['price_typical'] !== null ? ' around '.$money($f['price_typical']) : '')
                .($f['price_low'] !== null && $f['price_high'] !== null && $f['price_low'] !== $f['price_high'] ? ' ('.$money($f['price_low']).'–'.$money($f['price_high']).')' : '')
                .($f['zips'] !== [] ? ' in '.implode(', ', $f['zips']) : '');
            $out[] = 'Looking at '.$what.'.';
            $top = $f['top_homes'][0];
            $out[] = 'Viewed '.$f['homes_viewed'].' '.($f['homes_viewed'] === 1 ? 'home' : 'homes').' over '.$f['visits'].' '.($f['visits'] === 1 ? 'visit' : 'visits')
                .($top['views'] > 1 ? '; most interested in '.$top['label'].($top['price'] !== null ? ' ('.$money($top['price']).')' : '').', viewed '.$top['views'].' times' : '').'.';
        } elseif ($f['searches'] !== []) {
            $out[] = 'Searched for '.$f['searches'][0].'.';
        }
        $did = [];
        if ($f['saved'] !== []) {
            $did[] = 'saved '.count($f['saved']).' '.(count($f['saved']) === 1 ? 'home' : 'homes');
        }
        if ($f['compared'] !== []) {
            $did[] = 'compared '.$f['compared'][0];
        }
        if ($f['payments'] !== []) {
            $did[] = 'worked out payments on '.$f['payments'][0];
        }
        if ($f['valuations'] !== []) {
            $did[] = 'asked what '.$f['valuations'][0].' is worth';
        }
        if ($did !== []) {
            $out[] = ucfirst(implode(', ', $did)).'.';
        }
        if ($f['came_from'] === 'ai_search') {
            $out[] = 'Found you through an AI assistant.';
        }

        return implode(' ', $out);
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public static function describeSearch(array $d): string
    {
        $money = static fn (int $v): string => $v >= 1_000_000 ? '$'.rtrim(rtrim(number_format($v / 1_000_000, 2), '0'), '.').'M' : '$'.number_format((int) round($v / 1000)).'k';
        $parts = [];
        if (isset($d['beds'])) {
            $parts[] = $d['beds'].'+ bed';
        }
        $parts[] = 'homes';
        if (isset($d['q'])) {
            $parts[] = 'in '.$d['q'];
        } elseif (isset($d['area'])) {
            $parts[] = $d['area'] === 'drawn' ? 'in an area they drew' : 'in a map area';
        }
        if (isset($d['min_price'], $d['max_price'])) {
            $parts[] = $money((int) $d['min_price']).'–'.$money((int) $d['max_price']);
        } elseif (isset($d['max_price'])) {
            $parts[] = 'under '.$money((int) $d['max_price']);
        } elseif (isset($d['min_price'])) {
            $parts[] = 'over '.$money((int) $d['min_price']);
        }

        return count($parts) > 1 ? implode(' ', $parts) : '';
    }
}
