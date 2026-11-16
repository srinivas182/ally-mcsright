<?php

declare(strict_types=1);

namespace Modules\Movewise\Growth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Crm\PropertyData\PropertyData;
use Modules\Movewise\Models\Person;

/**
 * Home value estimates on Movewise (specs/movewise/09): ATTOM's automated valuation, paid for by HQ. Each address is
 * kept 30 days, a person can ask for 5 a day, and HQ caps the month (movewise.home_values_per_month) so the cost can't
 * run away. Always shown as a range with the record it came from, never as an appraisal.
 */
final class HomeValues
{
    public const KEEP_DAYS = 30;

    public const PER_PERSON_PER_DAY = 5;

    public function __construct(private readonly PropertyData $data) {}

    /**
     * @param  array{street: string, city: string, state: string, zip: string}  $address
     * @return array{low: int|null, mid: int, high: int|null, beds: int|null, baths: float|null, sqft: int|null, year_built: int|null, last_sale: array{price: int|null, date: string|null}, address: string, source: string, as_of: string}|null
     */
    public function for(array $address, ?Person $person): ?array
    {
        $street = trim(strip_tags($address['street']));
        if ($street === '' || preg_match('/^\d{5}$/', $address['zip']) !== 1) {
            throw ValidationException::withMessages(['street' => 'Enter the street address and the 5-digit ZIP code.']);
        }
        $key = 'movewise:value:'.sha1(mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', $street.$address['zip'])));
        $kept = Cache::get($key);
        if (is_array($kept)) {
            /** @var array{low: int|null, mid: int, high: int|null, beds: int|null, baths: float|null, sqft: int|null, year_built: int|null, last_sale: array{price: int|null, date: string|null}, address: string, source: string, as_of: string} $kept */
            return $kept;
        }
        if ($person !== null) {
            $limit = 'movewise:value:person:'.$person->id;
            if (RateLimiter::tooManyAttempts($limit, self::PER_PERSON_PER_DAY)) {
                throw ValidationException::withMessages(['street' => 'You\'ve asked for 5 estimates today. Please try again tomorrow.']);
            }
            RateLimiter::hit($limit, 86_400);
        }
        $month = 'movewise:value:month:'.CarbonImmutable::now()->format('Y-m');
        if ((int) Cache::get($month, 0) >= (int) config('movewise.home_values_per_month', 1500)) {
            return null; // the month's budget is used: no estimate, the page says so
        }
        try {
            $r = $this->data->estimate($address);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        Cache::increment($month);
        Cache::put($month, (int) Cache::get($month, 0), CarbonImmutable::now()->endOfMonth()->addDay());
        if ($r === null || (int) ($r['value']['mid'] ?? 0) <= 0) {
            return null;
        }
        $mid = (int) $r['value']['mid'];
        $out = ['low' => $r['value']['low'] ?? null, 'mid' => $mid, 'high' => $r['value']['high'] ?? null, 'beds' => $r['beds'] ?? null, 'baths' => $r['baths'] ?? null,
            'sqft' => $r['sqft'] ?? null, 'year_built' => $r['year_built'] ?? null, 'last_sale' => (array) ($r['last_sale'] ?? ['price' => null, 'date' => null]),
            'address' => (string) ($r['address'] ?? ''), 'source' => 'ATTOM', 'as_of' => CarbonImmutable::now()->toDateString()];
        Cache::put($key, $out, CarbonImmutable::now()->addDays(self::KEEP_DAYS));
        app(AuditLogger::class)->record('movewise.home_value', metadata: ['zip' => $address['zip']]);

        /** @var array{low: int|null, mid: int, high: int|null, beds: int|null, baths: float|null, sqft: int|null, year_built: int|null, last_sale: array{price: int|null, date: string|null}, address: string, source: string, as_of: string} $out */
        return $out;
    }
}
