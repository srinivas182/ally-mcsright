<?php

declare(strict_types=1);

namespace Modules\Sites\Funnels;

use Carbon\CarbonImmutable;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Contact;
use Modules\Listings\Models\Listing;
use Modules\Listings\Sync\Duplicates;

/**
 * "May have moved" flags (LN4, E24, specs/grow/16). When the home on a past client's or sphere contact's record sold
 * on the MLS in the last 12 months (and after they bought it), they have probably moved: the contact is flagged in
 * database health and on their page, with a note on the timeline, so the agent can congratulate them and ask for
 * their new address. Uses only MLS data the brokerage is licensed for, and never shows the sale to anyone else.
 */
final class MovedFlags
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** Flags contacts whose home sold. Returns how many were newly flagged. */
    public function scan(int $limit = 2000): int
    {
        $n = 0;
        $since = CarbonImmutable::today()->subMonths(12);
        Contact::query()->whereIn('lifecycle', ['past_client', 'sphere'])->whereNull('moved_on')->whereNotNull('address_line1')->whereNotNull('zip')
            ->orderBy('id')->limit($limit)->get()->each(function (Contact $c) use ($since, &$n): void {
                $key = self::key((string) $c->address_line1, $c->address_line2, (string) $c->zip);
                if ($key === null) {
                    return;
                }
                $after = $c->closed_on !== null && $c->closed_on->gt($since) ? $c->closed_on : $since;
                $sale = Listing::query()->visibleTo($this->tenants->id())->where('address_key', $key)->where('standard_status', 'Closed')
                    ->where('close_date', '>', $after->toDateString())->orderByDesc('close_date')->first(['close_date']);
                if ($sale === null || $sale->close_date === null) {
                    return;
                }
                $note = 'Their home at '.$c->address_line1.' sold on '.$sale->close_date->format('M j, Y').' (MLS). They may have moved.';
                $c->newQuery()->whereKey($c->id)->update(['moved_on' => $sale->close_date->toDateString(), 'moved_note' => mb_substr($note, 0, 200)]);
                app(Timeline::class)->system($c, $note.' Ask for their new address.');
                $n++;
            });

        return $n;
    }

    /** The listing address key for a contact's street line ("12 N Oak St Apt 4"). */
    public static function key(string $line1, ?string $line2, string $zip): ?string
    {
        if (preg_match('/^\s*(\d+[A-Za-z]?)\s+(.+?)(?:\s+(?:apt|unit|ste|suite|#)\s*([\w-]+))?\s*$/i', $line1, $m) !== 1) {
            return null;
        }
        $unit = $m[3] ?? '';
        if ($unit === '' && $line2 !== null && preg_match('/(?:apt|unit|ste|suite|#)?\s*([\w-]+)/i', $line2, $u) === 1) {
            $unit = $u[1];
        }

        return Duplicates::key($m[1], $m[2], $unit !== '' ? $unit : null, $zip);
    }
}
