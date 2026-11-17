<?php

declare(strict_types=1);

namespace Modules\Pricing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Contact;
use Modules\Listings\Models\Listing;
use Modules\Pricing\Models\Cma;
use Throwable;

/**
 * CMAs (CM1, specs/listings/01): made from a contact, a listing or an address; comps chosen automatically with
 * reasons and changeable by the agent; adjustments shown and editable; the price range with its confidence; the
 * market in the ZIP code; the seller net sheet at the low, likely and high price; a pricing summary; and a private
 * share link that tells the agent when the seller opens it.
 */
final class Cmas
{
    public const FEATURE = 'pricing.cma_summary';

    public function __construct(private readonly TenantContext $tenants) {}

    public static function canSee(User $user, Cma $cma): bool
    {
        return $cma->owner_user_id === $user->id || $user->isKind(UserKind::Owner);
    }

    /**
     * @param  array<string, mixed>  $in  listing (ulid) or street/city/state/zip, beds, baths_full, baths_half, sqft, year_built, lot_sqft, pool, garage; contact (ulid)
     */
    public function create(User $by, array $in): Cma
    {
        $subject = $this->subject($in);
        $contact = isset($in['contact']) && is_string($in['contact']) && $in['contact'] !== '' ? Contact::query()->where('ulid', $in['contact'])->first() : null;
        $found = app(Comps::class)->find($this->tenants->id(), $subject);
        $picks = array_map(fn (array $c, int $i): array => ['listing_id' => $c['listing']->id, 'on' => $i < (int) config('pricing.comps.wanted'), 'overrides' => []], $found, array_keys($found));
        $defaults = app(Defaults::class)->values($subject['state'] ?? null);
        $cma = Cma::query()->create(['owner_user_id' => $by->id, 'contact_id' => $contact?->id, 'title' => $subject['street'] !== '' ? $subject['street'] : 'Home value',
            'subject' => $subject, 'picks' => $picks, 'lang' => in_array($in['lang'] ?? 'en', ['en', 'es'], true) ? (string) ($in['lang'] ?? 'en') : 'en',
            'net' => ['payoff_cents' => 0, 'listing_pct' => $defaults['listing_commission_pct'], 'buyer_pct' => $defaults['buyer_agent_pct'], 'closing_pct' => $defaults['seller_closing_pct'],
                'repairs_cents' => 0, 'concessions_cents' => 0]]);
        $this->rebuild($cma);
        if ($contact !== null) {
            app(Timeline::class)->system($contact, "CMA started for {$cma->title}.", $by->id);
        }
        app(AuditLogger::class)->record('pricing.cma.created', $cma);

        return $cma;
    }

    /**
     * Changes from the agent: which comps count, adjustment amounts, the subject's facts, the net sheet, the language.
     *
     * @param  array<string, mixed>  $in
     */
    public function update(Cma $cma, array $in): void
    {
        if (isset($in['subject']) && is_array($in['subject'])) {
            $cma->subject = $this->subject(array_merge($cma->subject, $in['subject'], ['listing' => null]), $cma->subject);
        }
        $picks = $cma->picks;
        foreach ((array) ($in['on'] ?? []) as $id => $on) {
            foreach ($picks as &$p) {
                if ($p['listing_id'] === (int) $id) {
                    $p['on'] = (bool) $on;
                }
            }
            unset($p);
        }
        foreach ((array) ($in['overrides'] ?? []) as $id => $lines) {
            foreach ($picks as &$p) {
                if ($p['listing_id'] === (int) $id) {
                    foreach ((array) $lines as $key => $dollars) {
                        if (in_array($key, ['size', 'beds', 'baths_full', 'baths_half', 'age', 'pool', 'garage', 'lot'], true)) {
                            if ($dollars === null || $dollars === '') {
                                unset($p['overrides'][$key]);
                            } elseif (is_numeric($dollars) && abs((float) $dollars) <= 1_000_000) {
                                $p['overrides'][$key] = (int) round((float) $dollars * 100);
                            }
                        }
                    }
                }
            }
            unset($p);
        }
        $cma->picks = $picks;
        if (isset($in['net']) && is_array($in['net'])) {
            $n = $in['net'];
            $cma->net = ['payoff_cents' => self::cents($n['payoff'] ?? null), 'listing_pct' => self::pct($n['listing_pct'] ?? 0), 'buyer_pct' => self::pct($n['buyer_pct'] ?? 0),
                'closing_pct' => self::pct($n['closing_pct'] ?? 0), 'repairs_cents' => self::cents($n['repairs'] ?? null), 'concessions_cents' => self::cents($n['concessions'] ?? null)];
        }
        if (isset($in['lang']) && in_array($in['lang'], ['en', 'es'], true)) {
            $cma->lang = (string) $in['lang'];
        }
        if (isset($in['title']) && is_string($in['title']) && trim($in['title']) !== '') {
            $cma->title = mb_substr(trim($in['title']), 0, 160);
        }
        $this->rebuild($cma);
    }

    /** Add a sold home the agent found by address. */
    public function addComp(Cma $cma, string $listingUlid): void
    {
        $sources = app(Comps::class)->sources($this->tenants->id())['sources'];
        $l = Listing::query()->whereIn('mls_source_id', $sources ?: [0])->where('ulid', $listingUlid)->first();
        if ($l === null) {
            throw ValidationException::withMessages(['comp' => 'That home isn\'t in your MLS data.']);
        }
        $picks = array_values(array_filter($cma->picks, fn (array $p): bool => $p['listing_id'] !== $l->id));
        array_unshift($picks, ['listing_id' => $l->id, 'on' => true, 'overrides' => []]);
        $cma->picks = $picks;
        $this->rebuild($cma);
    }

    /**
     * Homes the agent can add, by street.
     *
     * @return list<array{ulid: string, address: string, price_cents: int, sold_on: string|null}>
     */
    public function search(Cma $cma, string $q): array
    {
        ['sources' => $sources, 'sold' => $sold] = app(Comps::class)->sources($this->tenants->id());
        $q = trim($q);
        if (mb_strlen($q) < 3 || $sources === []) {
            return [];
        }
        $parts = preg_split('/\s+/', $q, 2) ?: [];
        $rows = Listing::query()->whereIn('mls_source_id', $sources)->when($sold, fn ($w) => $w->where('standard_status', 'Closed'), fn ($w) => $w->where('standard_status', 'Active'))
            ->when(count($parts) === 2 && ctype_digit($parts[0]), fn ($w) => $w->where('street_number', $parts[0])->where('street_name', 'like', '%'.$parts[1].'%'),
                fn ($w) => $w->where('street_name', 'like', '%'.$q.'%'))->orderByDesc('close_date')->limit(10)->get();

        return array_values($rows->map(fn (Listing $l): array => ['ulid' => $l->ulid, 'address' => trim($l->address().', '.$l->city), 'price_cents' => Valuer::price($l, $sold),
            'sold_on' => $l->close_date?->toDateString()])->all());
    }

    public function rebuild(Cma $cma): void
    {
        $tenant = $this->tenants->id();
        ['sources' => $sources, 'sold' => $sold] = app(Comps::class)->sources($tenant);
        $on = array_values(array_filter($cma->picks, fn (array $p): bool => $p['on']));
        $listings = Listing::query()->whereIn('mls_source_id', $sources ?: [0])->whereIn('id', array_column($on, 'listing_id') ?: [0])->get()->keyBy('id');
        $all = Listing::query()->whereIn('mls_source_id', $sources ?: [0])->whereIn('id', array_column($cma->picks, 'listing_id') ?: [0])->get()->keyBy('id');
        $extras = app(Comps::class)->extras(array_values(array_map('intval', $listings->keys()->all())));
        $subject = $cma->subject;
        $chosen = [];
        foreach ($on as $p) {
            $l = $listings->get($p['listing_id']);
            if ($l === null) {
                continue;
            }
            $d = isset($subject['lat'], $subject['lng']) && $l->latitude !== null ? Comps::miles((float) $subject['lat'], (float) $subject['lng'], (float) $l->latitude, (float) $l->longitude) : null;
            $chosen[] = ['listing' => $l, 'score' => 50.0, 'miles' => $d !== null ? round($d, 2) : null, 'reason' => '', 'extras' => $extras[$l->id] ?? ['pool' => null, 'garage' => null],
                'overrides' => (array) ($p['overrides'] ?? [])];
        }
        // Reasons and scores, the same way the search gives them.
        $reasons = [];
        foreach (app(Comps::class)->find($tenant, $subject) as $c) {
            $reasons[$c['listing']->id] = $c;
        }
        foreach ($chosen as &$c) {
            $r = $reasons[$c['listing']->id] ?? null;
            $c['score'] = $r['score'] ?? 50.0;
            $c['reason'] = $r['reason'] ?? 'Added by the agent';
        }
        unset($c);
        $value = app(Valuer::class)->value($subject, $chosen, $sold);
        $market = app(Valuer::class)->market($tenant, (string) ($subject['zip'] ?? ''), $sold ? $sources : []);
        $net = (array) ($cma->net ?? []);
        $prices = ['low' => $value['low_cents'], 'likely' => $value['likely_cents'], 'high' => $value['high_cents']];
        $alternates = array_values(array_map(fn (array $p): array => ['listing_id' => $p['listing_id'], 'address' => $all->get($p['listing_id'])?->address(),
            'price_cents' => ($l = $all->get($p['listing_id'])) !== null ? Valuer::price($l, $sold) : 0, 'reason' => $reasons[$p['listing_id']]['reason'] ?? 'Added by the agent'],
            array_filter($cma->picks, fn (array $p): bool => ! $p['on'] && $all->has($p['listing_id']))));
        $cma->result = $value + ['sold' => $sold, 'market' => $market, 'alternates' => $alternates,
            'net' => array_map(fn (int $price): array => Calculators::net($price, $net), $prices), 'built_at' => CarbonImmutable::now()->toIso8601String()];
        $cma->save();
    }

    /** The pricing summary: written by AI from the figures only, or in plain template words if AI isn't available. */
    public function writeSummary(Cma $cma, User $by): void
    {
        $r = (array) $cma->result;
        if (($r['likely_cents'] ?? 0) === 0) {
            throw ValidationException::withMessages(['summary' => 'Choose some comparable homes first.']);
        }
        $allowed = array_merge([$r['low_cents'], $r['likely_cents'], $r['high_cents']], array_column((array) $r['comps'], 'price_cents'), array_column((array) $r['comps'], 'adjusted_cents'));
        try {
            $facts = array_map(fn (array $c): string => "{$c['address']}: ".($r['sold'] ? 'sold' : 'asking').' '.self::usd((int) $c['price_cents']).', adjusted to '.self::usd((int) $c['adjusted_cents'])
                .($c['adjustments'] !== [] ? ' ('.implode(', ', array_map(fn (array $a): string => $a['label'].' '.($a['cents'] >= 0 ? '+' : '−').self::usd(abs((int) $a['cents'])), $c['adjustments'])).')' : ''), (array) $r['comps']);
            $m = (array) $r['market'];
            $facts[] = 'Market in '.$m['zip'].': '.$m['sold_90'].' sold in 90 days'.($m['median_dom'] !== null ? ', median '.$m['median_dom'].' days on market' : '')
                .($m['sale_to_list'] !== null ? ', '.$m['sale_to_list'].'% of list price' : '').($m['months_of_inventory'] !== null ? ', '.$m['months_of_inventory'].' months of homes for sale' : '')
                .($m['ppsf_change'] !== null ? ', price per square foot '.($m['ppsf_change'] >= 0 ? 'up ' : 'down ').abs((float) $m['ppsf_change']).'% on the 3 months before' : '').'.';
            $s = $cma->subject;
            $result = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'pricing.cma_summary', 'cma-summary:'.$cma->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
                inputs: ['language' => $cma->lang === 'es' ? 'Write in clear, neutral Latin American Spanish' : 'Write in American English',
                    'home' => trim(($s['beds'] ?? '?').' bed, '.($s['baths_full'] ?? '?').' bath, '.(isset($s['sqft']) ? number_format((int) $s['sqft']).' sq ft' : 'size not given')),
                    'range' => self::usd((int) $r['low_cents']).' to '.self::usd((int) $r['high_cents']), 'likely' => self::usd((int) $r['likely_cents']), 'confidence' => (string) $r['confidence'], 'facts' => implode("\n", $facts)], outputSchema: ['type' => 'object', 'required' => ['summary'], 'properties' => ['summary' => ['type' => 'string']]], userId: $by->id));
            $text = trim((string) ($result->json['summary'] ?? ''));
            preg_match_all('/\$\s?([\d,]+)/', $text, $m2);
            foreach ($m2[1] as $amount) {
                $cents = (int) str_replace(',', '', $amount) * 100;
                if (! in_array($cents, $allowed, true)) {
                    $text = ''; // a figure that isn't in the CMA: don't trust it
                }
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $text = '';
        }
        $cma->summary = mb_substr($text !== '' ? $text : self::plainSummary($cma), 0, 2000);
        $cma->save();
    }

    public static function plainSummary(Cma $cma): string
    {
        $r = (array) $cma->result;
        $n = count((array) ($r['comps'] ?? []));
        $es = $cma->lang === 'es';

        return $es
            ? 'Según '.$n.' viviendas comparables '.($r['sold'] ? 'vendidas' : 'en venta').' cerca, el precio sugerido está entre '.self::usd((int) $r['low_cents']).' y '.self::usd((int) $r['high_cents'])
                .', con '.self::usd((int) $r['likely_cents']).' como el más probable. Ajustamos cada vivienda por tamaño, recámaras, baños y antigüedad para compararla con la suya. Revisaremos juntos estos números y el plan para vender.'
            : 'Based on '.$n.' comparable homes '.($r['sold'] ? 'sold' : 'for sale').' nearby, the suggested price is '.self::usd((int) $r['low_cents']).' to '.self::usd((int) $r['high_cents'])
                .', with '.self::usd((int) $r['likely_cents']).' the most likely. Each home was adjusted for size, bedrooms, bathrooms and age to compare it with yours. We will go through these numbers and the plan to sell together.';
    }

    /** A private link for the seller (90 days); sharing again makes a new link and ends the old one. */
    public function share(Cma $cma, User $by): string
    {
        $token = Str::random(40);
        $cma->fill(['share_hash' => hash('sha256', $token), 'shared_at' => now(), 'share_expires_at' => now()->addDays((int) config('pricing.share_days'))])->save();
        if ($cma->summary === null) {
            $cma->summary = self::plainSummary($cma);
            $cma->save();
        }
        $contact = $cma->contact_id !== null ? Contact::query()->find($cma->contact_id) : null;
        if ($contact !== null) {
            app(Timeline::class)->system($contact, "CMA for {$cma->title} shared.", $by->id);
        }
        app(AuditLogger::class)->record('pricing.cma.shared', $cma);

        return app(SpeedToLead::class)->link('/cma/'.$token);
    }

    public function byToken(string $token): ?Cma
    {
        return strlen($token) === 40 ? Cma::query()->where('share_hash', hash('sha256', $token))->where('share_expires_at', '>', now())->first() : null;
    }

    /** The seller opened it: count it, and tell the agent (at most every 12 hours). */
    public function viewed(Cma $cma): void
    {
        $alert = $cma->last_viewed_at === null || $cma->last_viewed_at->lt(now()->subHours(12));
        $cma->forceFill(['views' => $cma->views + 1, 'last_viewed_at' => now()])->save();
        if (! $alert) {
            return;
        }
        $contact = $cma->contact_id !== null ? Contact::query()->find($cma->contact_id) : null;
        $who = $contact !== null ? $contact->name : 'Your seller';
        if ($contact !== null) {
            app(Timeline::class)->system($contact, "{$who} opened the CMA for {$cma->title}".($cma->views > 1 ? " (view {$cma->views})" : '').'.');
        }
        $agent = User::query()->find($cma->owner_user_id);
        if ($agent !== null && $agent->email !== '') {
            Mail::to($agent->email)->send(new CmaOpenedMail($who, $cma->title, $cma->views, app(SpeedToLead::class)->link('/pricing/cma/'.$cma->ulid)));
        }
    }

    public static function usd(int $cents): string
    {
        return '$'.number_format(intdiv($cents, 100));
    }

    /**
     * @param  array<string, mixed>  $in
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function subject(array $in, array $old = []): array
    {
        $listing = isset($in['listing']) && is_string($in['listing']) && $in['listing'] !== '' ? Listing::query()->visibleTo($this->tenants->id(), 'idx')->where('ulid', $in['listing'])->first() : null;
        $listing ??= isset($in['listing']) && is_string($in['listing']) && $in['listing'] !== ''
            ? Listing::query()->whereIn('mls_source_id', app(Comps::class)->sources($this->tenants->id())['sources'] ?: [0])->where('ulid', $in['listing'])->first() : null;
        $int = fn (string $k, int $min, int $max): ?int => isset($in[$k]) && is_numeric($in[$k]) && (int) $in[$k] >= $min && (int) $in[$k] <= $max ? (int) $in[$k] : null;
        $s = $listing !== null ? ['listing_id' => $listing->id, 'street' => $listing->address(), 'city' => (string) $listing->city, 'state' => (string) $listing->state_or_province,
            'zip' => (string) $listing->postal_code, 'beds' => $listing->beds_total, 'baths_full' => $listing->baths_full, 'baths_half' => $listing->baths_half,
            'sqft' => $listing->living_area_sqft, 'year_built' => $listing->year_built, 'lot_sqft' => $listing->lot_size_sqft,
            'lat' => $listing->latitude !== null ? (float) $listing->latitude : null, 'lng' => $listing->longitude !== null ? (float) $listing->longitude : null] + (app(Comps::class)->extras([$listing->id])[$listing->id] ?? [])
            : ['listing_id' => $old['listing_id'] ?? null, 'street' => mb_substr(trim(strip_tags((string) ($in['street'] ?? ''))), 0, 150), 'city' => mb_substr(trim((string) ($in['city'] ?? '')), 0, 80),
                'state' => strtoupper(mb_substr(trim((string) ($in['state'] ?? '')), 0, 2)), 'zip' => preg_match('/^\d{5}$/', trim((string) ($in['zip'] ?? ''))) === 1 ? trim((string) $in['zip']) : '',
                'beds' => $int('beds', 0, 20), 'baths_full' => $int('baths_full', 0, 20), 'baths_half' => $int('baths_half', 0, 10), 'sqft' => $int('sqft', 200, 50_000),
                'year_built' => $int('year_built', 1800, (int) date('Y') + 1), 'lot_sqft' => $int('lot_sqft', 100, 50_000_000),
                'pool' => array_key_exists('pool', $in) && $in['pool'] !== null && $in['pool'] !== '' ? filter_var($in['pool'], FILTER_VALIDATE_BOOLEAN) : null,
                'garage' => $int('garage', 0, 10), 'lat' => $old['lat'] ?? null, 'lng' => $old['lng'] ?? null];
        if ($s['street'] === '' || $s['zip'] === '') {
            throw ValidationException::withMessages(['street' => 'Enter the street address and the 5-digit ZIP code, or pick a listing.']);
        }
        if ($s['sqft'] === null) {
            throw ValidationException::withMessages(['sqft' => 'Enter the home\'s size in square feet: comps are matched by size.']);
        }

        return $s;
    }

    private static function cents(mixed $dollars): int
    {
        return is_numeric($dollars) ? max(0, min(100_000_000_00, (int) round((float) $dollars * 100))) : 0;
    }

    private static function pct(mixed $p): float
    {
        return is_numeric($p) ? max(0.0, min(10.0, round((float) $p, 3))) : 0.0;
    }
}
