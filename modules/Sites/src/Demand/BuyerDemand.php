<?php

declare(strict_types=1);

namespace Modules\Sites\Demand;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\SavedSearch;
use Modules\Listings\Models\Listing;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Models\DemandReport;

/**
 * Buyer demand before a home is listed (specs/grow/10). From an address, a price and the home's basics: how many
 * buyers in the brokerage's own CRM and website want a home like it, how that changes at each price point, which
 * of the brokerage's agents hold those buyers, and similar homes for sale.
 *
 * First-party data only (saved searches, buyer leads, website visitors who viewed similar homes); counts only,
 * never names or contact details, so the listing agent learns how many and through which agents, nothing more.
 */
final class BuyerDemand
{
    /** Price points shown, as a share of the price. */
    public const STEPS = [0.85, 0.9, 0.95, 1.0, 1.05, 1.1, 1.15];

    public function __construct(private readonly TenantContext $tenants, private readonly ListingSearch $search) {}

    /**
     * @param  array<string, mixed>  $in  address, zip, price, beds, baths, sqft
     */
    public function run(User $by, array $in): DemandReport
    {
        $address = mb_substr(trim(strip_tags((string) ($in['address'] ?? ''))), 0, 150);
        $zip = trim((string) ($in['zip'] ?? ''));
        $price = (int) preg_replace('/[^0-9]/', '', explode('.', (string) ($in['price'] ?? ''))[0]);
        $home = ['beds' => self::int($in['beds'] ?? null, 20), 'baths' => self::int($in['baths'] ?? null, 20), 'sqft' => self::int($in['sqft'] ?? null, 50000)];
        $errors = array_filter(['address' => $address === '' ? 'Enter the street address.' : null, 'zip' => preg_match('/^\d{5}$/', $zip) === 1 ? null : 'Enter the 5-digit ZIP code.',
            'price' => $price < 10_000 || $price > 100_000_000 ? 'Enter the price you expect to list at.' : null]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $result = $this->measure($zip, $price, $home);
        $report = DemandReport::query()->create(['user_id' => $by->id, 'address' => $address, 'zip' => $zip, 'inputs' => ['price' => $price] + $home, 'result' => $result]);
        app(AuditLogger::class)->record('sites.demand.report', $report, metadata: ['zip' => $zip, 'buyers' => $result['buyers']], label: $address);

        return $report;
    }

    /**
     * @param  array{beds: int|null, baths: int|null, sqft: int|null}  $home
     * @return array<string, mixed>
     */
    public function measure(string $zip, int $price, array $home): array
    {
        $tenant = $this->tenants->id();
        $area = Listing::query()->visibleTo($tenant, 'idx')->where('postal_code', $zip)->whereNotNull('latitude');
        $place = ['zip' => $zip, 'city' => (string) ((clone $area)->value('city') ?? ''), 'state' => (string) ((clone $area)->value('state_or_province') ?? ''),
            'lat' => (float) ((clone $area)->avg('latitude') ?? 0), 'lng' => (float) ((clone $area)->avg('longitude') ?? 0)];

        // Buyers who told us: saved searches that would send them this home, and buyer leads for this area and price.
        $searches = SavedSearch::query()->where('frequency', '!=', 'off')->whereNull('unsubscribed_at')->limit(20_000)->get(['id', 'contact_id', 'user_id', 'filters']);
        $leads = Lead::query()->where('intent', 'buy')->where('created_at', '>=', CarbonImmutable::now()->subYear())->where('zip', $zip)->whereNotNull('contact_id')
            ->limit(20_000)->get(['id', 'contact_id', 'price_min', 'price_max']);
        $curve = [];
        $at = [];
        foreach (self::STEPS as $step) {
            $p = (int) (round($price * $step / 1000) * 1000);
            $who = [];
            foreach ($searches as $s) {
                if (self::wants((array) $s->filters, $place, $home, $p)) {
                    $who[$s->contact_id] = $s->user_id;
                }
            }
            foreach ($leads as $l) {
                if (($l->price_min === null || $l->price_min <= $p) && ($l->price_max === null || $l->price_max >= $p) && ! isset($who[(int) $l->contact_id])) {
                    $who[(int) $l->contact_id] = null;
                }
            }
            $curve[] = ['price' => $p, 'buyers' => count($who)];
            if ($step === 1.0) {
                $at = $who;
            }
        }

        // Which of the brokerage's agents hold those buyers (the saved search's agent, else the contact's owner).
        $owners = Contact::query()->whereIn('id', array_keys($at))->pluck('owner_user_id', 'id');
        $byAgent = [];
        foreach ($at as $contactId => $agentId) {
            $agentId ??= $owners[$contactId] ?? null;
            if ($agentId !== null) {
                $byAgent[(int) $agentId] = ($byAgent[(int) $agentId] ?? 0) + 1;
            }
        }
        arsort($byAgent);
        $names = User::query()->whereIn('id', array_keys($byAgent))->get()->keyBy('id');
        $agents = [];
        foreach ($byAgent as $id => $n) {
            if (($u = $names->get($id)) !== null) {
                $agents[] = ['id' => $id, 'name' => $u->name, 'buyers' => $n];
            }
        }

        // Website shoppers: visitors who looked at homes in this ZIP within 15% of the price in the last 90 days.
        $similar = Listing::query()->visibleTo($tenant, 'idx')->where('postal_code', $zip)
            ->whereBetween('list_price_cents', [(int) ($price * 0.85 * 100), (int) ($price * 1.15 * 100)])->limit(2000)->pluck('ulid')->all();
        $db = DB::connection(Connections::cell());
        $since = CarbonImmutable::now()->subDays(90);
        $shoppers = $similar === [] ? 0 : (int) $db->table('site_events')->where('tenant_id', $tenant)->where('type', 'listing')->whereIn('listing_ulid', $similar)
            ->where('created_at', '>=', $since)->distinct()->count('visitor_id');
        $saved = $similar === [] ? 0 : (int) $db->table('site_favourites')->where('tenant_id', $tenant)->whereIn('listing_ulid', $similar)
            ->where('created_at', '>=', $since)->distinct()->count('visitor_id');

        // The competition: similar homes for sale now.
        $found = $this->search->search($tenant, array_filter(['q' => $zip, 'min_price' => (int) ($price * 0.85), 'max_price' => (int) ($price * 1.15),
            'beds' => $home['beds'] !== null ? max(1, $home['beds'] - 1) : null, 'sort' => 'newest', 'per_page' => 6], fn ($v) => $v !== null));
        $prices = array_values(array_filter(array_map(fn (array $h): ?int => isset($h['price']) ? (int) $h['price'] : null, $found['results'])));
        sort($prices);

        return ['place' => $place, 'price' => $price, 'buyers' => count($at), 'curve' => $curve, 'agents' => $agents, 'shoppers' => $shoppers, 'favourited' => $saved,
            'competition' => ['total' => (int) $found['total'], 'median_price' => $prices !== [] ? $prices[intdiv(count($prices), 2)] : null,
                'homes' => array_map(fn (array $h): array => ['ulid' => $h['ulid'], 'label' => ListingSearch::label($h), 'price' => $h['price'] ?? null, 'beds' => $h['beds'] ?? null,
                    'sqft' => $h['sqft'] ?? null, 'photo' => $h['photo'] ?? null, 'attribution' => $h['attribution'] ?? null], array_slice($found['results'], 0, 6))],
            'as_of' => CarbonImmutable::now()->toIso8601String()];
    }

    /**
     * Tells the brokerage's agents who hold matching buyers that this home is coming (counts only; each agent
     * knows their own buyers). Once per report.
     */
    public function tellAgents(DemandReport $report, User $by): int
    {
        if ($report->agents_told_at !== null) {
            throw ValidationException::withMessages(['agents' => 'These agents have already been told about this home.']);
        }
        $told = 0;
        foreach ((array) ($report->result['agents'] ?? []) as $a) {
            $agent = User::query()->find((int) $a['id']);
            if ($agent === null || $agent->id === $by->id) {
                continue;
            }
            $agent->notify(new DemandAlert($report->address, (int) $report->inputs['price'], (int) $a['buyers'], $by->name, $by->email));
            $told++;
        }
        $report->forceFill(['agents_told_at' => CarbonImmutable::now()])->save();
        app(AuditLogger::class)->record('sites.demand.agents_told', $report, metadata: ['agents' => $told], label: $report->address);

        return $told;
    }

    /**
     * Would this saved search send them this home at this price? Place by ZIP, city or map area; price, beds, baths
     * and size within the search's limits.
     *
     * @param  array<string, mixed>  $f
     * @param  array{zip: string, city: string, state: string, lat: float, lng: float}  $place
     * @param  array{beds: int|null, baths: int|null, sqft: int|null}  $home
     */
    public static function wants(array $f, array $place, array $home, int $price): bool
    {
        if ((isset($f['min_price']) && is_numeric($f['min_price']) && (int) $f['min_price'] > $price) || (isset($f['max_price']) && is_numeric($f['max_price']) && (int) $f['max_price'] < $price)) {
            return false;
        }
        foreach (['beds' => 'beds', 'baths' => 'baths', 'min_sqft' => 'sqft'] as $key => $have) {
            if (isset($f[$key]) && is_numeric($f[$key]) && $home[$have] !== null && $home[$have] < (int) $f[$key]) {
                return false;
            }
        }
        $q = strtolower(trim((string) ($f['q'] ?? '')));
        $bounds = is_array($f['bounds'] ?? null) && count($f['bounds']) === 4 ? array_map('floatval', $f['bounds']) : null;
        if ($bounds !== null && $place['lat'] !== 0.0) {
            return $place['lat'] >= $bounds[0] && $place['lat'] <= $bounds[2] && $place['lng'] >= $bounds[1] && $place['lng'] <= $bounds[3];
        }
        if ($q === '') {
            return false; // no place: not a buyer for this area
        }
        $city = strtolower($place['city']);

        $qCity = trim((string) preg_replace('/,.*$/', '', $q));

        return str_contains($q, $place['zip']) || ($city !== '' && ($qCity === $city || str_contains($q, $city.',')));
    }

    private static function int(mixed $v, int $max): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? min($max, (int) $v) : null;
    }
}
