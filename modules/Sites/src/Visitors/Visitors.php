<?php

declare(strict_types=1);

namespace Modules\Sites\Visitors;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Listings\Models\Listing;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Jobs\SummariseJourney;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteEvent;
use Modules\Sites\Models\SiteVisitor;

/**
 * Website visitors and their journeys (specs/websites/07). Visitors are known by a random id their browser keeps
 * (no cookies, no third-party trackers); browsers that send Global Privacy Control are not tracked. Once someone
 * becomes a contact, what they searched, viewed, saved and compared is shown to their agent with a summary, and
 * the agent is told when they look ready to act.
 */
final class Visitors
{
    public const MAX_EVENTS_PER_DAY = 500;

    public const BOTS = '/bot|crawl|spider|slurp|preview|headless|lighthouse|monitor|curl|wget|python|httpclient/i';

    public function __construct(private readonly TenantContext $tenants) {}

    /** Whether this request may be tracked at all (Global Privacy Control, Do Not Track, crawlers). */
    public static function trackable(Request $request): bool
    {
        return $request->header('Sec-GPC') !== '1' && $request->header('DNT') !== '1' && preg_match(self::BOTS, (string) $request->userAgent()) !== 1;
    }

    /**
     * A search's web-address query as filters (string keys only).
     *
     * @return array<string, mixed>
     */
    public static function queryFilters(string $query): array
    {
        parse_str(mb_substr($query, 0, 2500), $parsed);
        $filters = [];
        foreach ($parsed as $k => $v) {
            if (is_string($k)) {
                $filters[$k] = $v;
            }
        }

        return $filters;
    }

    public static function validVid(mixed $vid): bool
    {
        return is_string($vid) && preg_match('/^[A-Za-z0-9_-]{16,40}$/', $vid) === 1;
    }

    /**
     * The visitor for a browser id (created on first sight), following merges to the account it now belongs to.
     *
     * @param  array{referrer?: string|null, landing?: string|null}  $first
     */
    public function resolve(Site $site, string $vid, array $first = []): SiteVisitor
    {
        $now = CarbonImmutable::now();
        $visitor = SiteVisitor::query()->firstOrCreate(['vid' => $vid], ['site_id' => $site->id, 'first_seen_at' => $now, 'last_seen_at' => $now, 'visits' => 1,
            'first_referrer' => self::clip($first['referrer'] ?? null, 500), 'landing_url' => self::clip($first['landing'] ?? null, 500)]);

        return $this->primary($visitor);
    }

    public function primary(SiteVisitor $visitor): SiteVisitor
    {
        for ($i = 0; $i < 5 && $visitor->merged_into !== null; $i++) {
            $visitor = SiteVisitor::query()->find($visitor->merged_into) ?? $visitor;
        }

        return $visitor;
    }

    /**
     * Records a batch of events from the browser (unknown types and malformed values are dropped).
     *
     * @param  list<mixed>  $events
     */
    public function record(SiteVisitor $visitor, array $events): int
    {
        $now = CarbonImmutable::now();
        $today = $now->toDateString();
        $used = $visitor->events_day?->toDateString() === $today ? $visitor->events_today : 0;
        $rows = [];
        foreach (array_slice($events, 0, 20) as $e) {
            if ($used + count($rows) >= self::MAX_EVENTS_PER_DAY || ! is_array($e) || ! in_array($e['type'] ?? null, ['page', 'search', 'listing', 'favourite', 'unfavourite', 'compare', 'mortgage'], true)) {
                continue;
            }
            $row = $this->clean($visitor, (string) $e['type'], $e);
            if ($row !== null) {
                $rows[] = $row + ['tenant_id' => $visitor->tenant_id, 'visitor_id' => $visitor->id, 'created_at' => $now];
            }
        }
        if ($rows === []) {
            return 0;
        }
        foreach ($rows as $row) {
            SiteEvent::query()->create($row);
        }
        $newVisit = $visitor->last_seen_at === null || $visitor->last_seen_at->lt($now->subMinutes(30));
        $visitor->forceFill(['last_seen_at' => $now, 'visits' => $visitor->visits + ($newVisit ? 1 : 0), 'events_day' => $today, 'events_today' => $used + count($rows)])->save();
        if ($visitor->contact_id !== null) {
            $this->readyToAct($visitor, $rows);
        }

        return count($rows);
    }

    /**
     * Adds a server-side event (an enquiry, a valuation, a sign-up).
     *
     * @param  array<string, mixed>  $data
     */
    public function note(SiteVisitor $visitor, string $type, ?string $listingUlid = null, array $data = [], ?string $path = null): void
    {
        SiteEvent::query()->create(['tenant_id' => $visitor->tenant_id, 'visitor_id' => $visitor->id, 'type' => $type, 'listing_ulid' => $listingUlid, 'path' => $path,
            'data' => $data !== [] ? $data : null, 'created_at' => CarbonImmutable::now()]);
    }

    /**
     * Ties the visitor to the CRM lead they just became (form, valuation or sign-up), merges any earlier visitor with
     * the same contact, and queues the journey summary for the agent.
     */
    /**
     * @param  array<string, mixed>  $data
     */
    public function linkLead(SiteVisitor $visitor, Lead $lead, string $type, ?string $listingUlid = null, array $data = []): SiteVisitor
    {
        $visitor = $this->primary($visitor);
        $existing = SiteVisitor::query()->where('contact_id', $lead->contact_id)->whereNull('merged_into')->whereKeyNot($visitor->id)->orderBy('id')->first();
        if ($existing !== null) {
            $this->merge($visitor, $existing);
            $visitor = $existing;
        }
        $visitor->forceFill(['contact_id' => $lead->contact_id, 'lead_id' => $lead->id])->save();
        $this->note($visitor, $type, $listingUlid, $data + ['lead' => $lead->ulid]);
        SummariseJourney::dispatch($visitor->id);

        return $visitor;
    }

    /** Moves one browser's journey and favourites into another visitor (same person on a second device). */
    public function merge(SiteVisitor $from, SiteVisitor $into): void
    {
        if ($from->id === $into->id) {
            return;
        }
        $db = DB::connection(Connections::cell());
        $db->transaction(function () use ($db, $from, $into): void {
            $db->table('site_events')->where('visitor_id', $from->id)->update(['visitor_id' => $into->id]);
            $have = $db->table('site_favourites')->where('visitor_id', $into->id)->pluck('listing_ulid')->all();
            $db->table('site_favourites')->where('visitor_id', $from->id)->whereIn('listing_ulid', $have)->delete();
            $db->table('site_favourites')->where('visitor_id', $from->id)->update(['visitor_id' => $into->id]);
            $from->forceFill(['merged_into' => $into->id])->save();
            $into->forceFill(['visits' => $into->visits + $from->visits, 'first_seen_at' => min($into->first_seen_at, $from->first_seen_at) ?? $into->first_seen_at,
                'first_referrer' => $into->first_referrer ?? $from->first_referrer, 'landing_url' => $into->landing_url ?? $from->landing_url])->save();
        });
    }

    /** A sign-in token the browser keeps (encrypted; 180 days). */
    public function token(SiteVisitor $visitor): string
    {
        return Crypt::encryptString((string) json_encode(['v' => $visitor->id, 't' => $visitor->tenant_id, 'e' => CarbonImmutable::now()->addDays(180)->getTimestamp()]));
    }

    public function fromToken(?string $token): ?SiteVisitor
    {
        if ($token === null || $token === '') {
            return null;
        }
        try {
            $p = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($p) || ($p['t'] ?? null) !== $this->tenants->id() || (int) ($p['e'] ?? 0) < time()) {
            return null;
        }
        $visitor = SiteVisitor::query()->find((int) ($p['v'] ?? 0));

        return $visitor !== null && $visitor->verified_at !== null ? $this->primary($visitor) : null;
    }

    /**
     * Tells the agent when a known visitor looks ready: the same home viewed REPEAT_VIEWS times in a week, a
     * payment estimate, or a home saved. Once per home per week.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function readyToAct(SiteVisitor $visitor, array $rows): void
    {
        $since = CarbonImmutable::now()->subDays(7);
        $repeat = (int) config('sites.ready.repeat_views', 3);
        $alerts = $visitor->alerts ?? [];
        foreach ($rows as $row) {
            $ulid = $row['listing_ulid'] ?? null;
            if (! is_string($ulid)) {
                continue;
            }
            $why = match ($row['type']) {
                'mortgage' => 'worked out the monthly payment on',
                'favourite' => 'saved',
                'listing' => SiteEvent::query()->where('visitor_id', $visitor->id)->where('listing_ulid', $ulid)->where('type', 'listing')->where('created_at', '>=', $since)->count() >= $repeat
                    ? 'keeps coming back to' : null,
                default => null,
            };
            if ($why === null || (isset($alerts[$ulid]) && CarbonImmutable::parse($alerts[$ulid])->gt($since))) {
                continue;
            }
            $alerts[$ulid] = CarbonImmutable::now()->toIso8601String();
            $visitor->forceFill(['alerts' => $alerts])->save();
            $this->alertAgent($visitor, $why, (string) (($row['data']['label'] ?? null) ?: 'a home'), isset($row['data']['price']) ? (int) $row['data']['price'] : null);
            SummariseJourney::dispatch($visitor->id);
        }
    }

    private function alertAgent(SiteVisitor $visitor, string $why, string $label, ?int $price): void
    {
        $contact = Contact::query()->find($visitor->contact_id);
        if ($contact === null) {
            return;
        }
        $agentId = $contact->owner_user_id ?? Lead::query()->whereKey($visitor->lead_id)->value('assigned_user_id');
        $agent = $agentId !== null ? User::query()->find($agentId) : null;
        $line = "{$contact->name} {$why} {$label}".($price !== null ? ' ($'.number_format($price).')' : '').' on your website. Now is a good time to reach out.';
        app(Timeline::class)->system($contact, 'Website: '.$contact->name.' '.$why.' '.$label.($price !== null ? ' ($'.number_format($price).')' : ''));
        if ($agent instanceof User && $agent->isActive()) {
            $agent->notify(new LeadAlert("Ready to act: {$contact->name}", $line, app(SpeedToLead::class)->link('/contacts/'.$contact->ulid), 'Call now'));
        }
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array{type: string, listing_ulid: string|null, path: string|null, data: array<string, mixed>|null}|null
     */
    private function clean(SiteVisitor $visitor, string $type, array $e): ?array
    {
        $path = is_string($e['path'] ?? null) ? self::clip(parse_url($e['path'], PHP_URL_PATH) ?: null, 300) : null;
        $ulid = is_string($e['listing'] ?? null) && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $e['listing']) === 1 ? strtoupper($e['listing']) : null;
        $data = null;
        switch ($type) {
            case 'page':
                if ($path === null) {
                    return null;
                }
                break;
            case 'search':
                $f = ListingSearch::clean(self::queryFilters(is_string($e['query'] ?? null) ? $e['query'] : ''));
                $data = array_filter(['q' => $f['q'] ?: null, 'min_price' => $f['min_price'], 'max_price' => $f['max_price'], 'beds' => $f['beds'], 'baths' => $f['baths'],
                    'area' => $f['polygon'] !== null ? 'drawn' : ($f['bounds'] !== null ? 'map' : null), 'sort' => $f['sort'] !== 'newest' ? $f['sort'] : null], fn ($v): bool => $v !== null);
                break;
            case 'compare':
                $ulids = array_values(array_filter(array_map(fn ($u): string => strtoupper((string) $u), array_slice(is_array($e['listings'] ?? null) ? $e['listings'] : [], 0, 4)),
                    fn (string $u): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $u) === 1));
                if (count($ulids) < 2) {
                    return null;
                }
                $data = ['listings' => $ulids, 'labels' => array_map(fn (string $u): string => $this->listingFacts($u)['label'] ?? 'a home', $ulids)];
                break;
            default: // listing, favourite, unfavourite, mortgage
                if ($ulid === null) {
                    return null;
                }
                $facts = $this->listingFacts($ulid);
                if ($facts === null) {
                    return null; // not a listing this brokerage may show
                }
                $data = $facts;
                if ($type === 'mortgage') {
                    $data += array_filter(['down_pct' => is_numeric($e['down_pct'] ?? null) ? max(0, min(100, (int) $e['down_pct'])) : null,
                        'rate' => is_numeric($e['rate'] ?? null) ? max(0, min(20, round((float) $e['rate'], 3))) : null], fn ($v): bool => $v !== null);
                }
                if ($type === 'favourite' || $type === 'unfavourite') {
                    $this->favourite($visitor, $ulid, $type === 'favourite');
                }
        }

        return ['type' => $type, 'listing_ulid' => $ulid, 'path' => $path, 'data' => $data];
    }

    public function favourite(SiteVisitor $visitor, string $ulid, bool $on): void
    {
        $db = DB::connection(Connections::cell())->table('site_favourites');
        $on ? $db->insertOrIgnore(['tenant_id' => $visitor->tenant_id, 'visitor_id' => $visitor->id, 'listing_ulid' => $ulid, 'created_at' => CarbonImmutable::now()])
            : $db->where('visitor_id', $visitor->id)->where('listing_ulid', $ulid)->delete();
    }

    /**
     * @return list<string>
     */
    public function favourites(SiteVisitor $visitor): array
    {
        return array_values(DB::connection(Connections::cell())->table('site_favourites')->where('visitor_id', $visitor->id)->orderByDesc('id')->limit(200)->pluck('listing_ulid')
            ->map(fn ($u): string => (string) $u)->all());
    }

    /**
     * A snapshot of the home at the moment (label, price, beds, ZIP), so the journey still reads well if it sells.
     *
     * @return array{label: string, price: int|null, beds: int|null, zip: string|null}|null
     */
    public function listingFacts(string $ulid): ?array
    {
        $l = Listing::query()->visibleTo($this->tenants->id(), 'idx')->where('ulid', $ulid)
            ->first(['id', 'ulid', 'street_number', 'street_name', 'unit_number', 'city', 'state_or_province', 'postal_code', 'address_display_allowed', 'list_price_cents', 'beds_total']);
        if ($l === null) {
            return null;
        }
        $label = trim(implode(', ', array_filter([$l->address_display_allowed ? $l->address() : null, $l->city])));

        return ['label' => $label !== '' ? $label : 'a home in '.$l->postal_code, 'price' => $l->list_price_cents !== null ? intdiv($l->list_price_cents, 100) : null,
            'beds' => $l->beds_total, 'zip' => $l->postal_code];
    }

    private static function clip(?string $v, int $max): ?string
    {
        $v = $v !== null ? trim($v) : '';

        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }
}
