<?php

declare(strict_types=1);

namespace Modules\Transactions\Mcp;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealAccess;

/** The deals section of the daily brief: open key dates in the next 7 days (and overdue), on deals the person can see. */
final class DealDeadlines
{
    /** @return list<array<string, mixed>> */
    public static function forBrief(User $user): array
    {
        $deals = DealAccess::query($user)->whereNotIn('status', ['closed', 'cancelled'])->limit(200)->get()->keyBy('id');
        if ($deals->isEmpty()) {
            return [];
        }
        $today = CarbonImmutable::today()->toDateString();

        return array_values(DB::connection(Connections::cell())->table('tx_dates')->whereIn('deal_id', $deals->keys()->all())->whereNull('done_at')
            ->where('due_on', '<=', CarbonImmutable::today()->addDays(7)->toDateString())->orderBy('due_on')->limit(30)->get()
            ->map(function (object $d) use ($deals, $today): array {
                $deal = $deals->get((int) $d->deal_id);
                $due = substr((string) $d->due_on, 0, 10);

                return ['deal_id' => $deal?->ulid, 'address' => $deal !== null ? trim($deal->street.', '.$deal->city) : null, 'what' => (string) $d->label,
                    'due_on' => $due, 'overdue' => $due < $today];
            })->all());
    }
}
