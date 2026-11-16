<?php

declare(strict_types=1);

namespace Modules\Movewise;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Listings\Search\ListingSearch;
use Modules\Movewise\Mail\AlertDigest;
use Modules\Movewise\Models\Person;
use Throwable;

/**
 * Saved-search alerts (specs/movewise/02): new homes and price drops since the last email, daily or weekly, only
 * when there is something new. Every email has a one-click "stop these emails" link and Movewise's postal address
 * (CAN-SPAM). Alerts are the only email Movewise sends without being asked each time.
 */
final class Alerts
{
    public function __construct(private readonly Movewise $movewise, private readonly ListingSearch $search) {}

    public function sendDue(): int
    {
        $hq = $this->movewise->feedTenant();
        $states = $this->movewise->onStates();
        if ($hq === null || $states === []) {
            return 0;
        }
        $sent = 0;
        $now = CarbonImmutable::now();
        $db = DB::connection(Connections::control())->table('movewise_saved_searches');
        foreach ($db->where('frequency', '!=', 'off')->orderBy('id')->limit(5000)->get() as $s) {
            $last = CarbonImmutable::parse((string) ($s->last_sent_at ?? $s->created_at));
            if ($last->gt($now->subHours($s->frequency === 'weekly' ? 167 : 23))) {
                continue;
            }
            $person = Person::query()->whereKey($s->person_id)->first();
            if ($person === null || $person->email_verified_at === null) {
                continue;
            }
            try {
                $filters = (array) json_decode((string) $s->filters, true);
                $found = $this->search->search($hq->id, ['states' => array_values(array_intersect($states, (array) ($filters['states'] ?? $states)) ?: $states),
                    'since' => $last->toIso8601String(), 'per_page' => 12, 'sort' => 'newest'] + array_intersect_key($filters, array_flip(['q', 'min_price', 'max_price', 'beds', 'baths'])));
                if ($found['total'] > 0) {
                    Mail::to($person->email)->send(new AlertDigest($s->name, $found['results'], $found['total'], self::stopUrl((string) $s->ulid), $person->locale));
                    $sent++;
                }
                $db->where('id', $s->id)->update(['last_sent_at' => $now, 'updated_at' => $now]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    public static function stopUrl(string $searchUlid): string
    {
        return 'https://'.config('movewise.host').'/alerts/off/'.$searchUlid.'/'.self::sign($searchUlid);
    }

    public static function sign(string $searchUlid): string
    {
        return substr(hash_hmac('sha256', 'movewise-alert:'.$searchUlid, (string) config('app.key')), 0, 32);
    }

    public static function slug(string $label): string
    {
        return Str::slug(mb_substr($label, 0, 120));
    }
}
