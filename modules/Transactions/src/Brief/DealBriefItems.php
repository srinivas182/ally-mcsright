<?php

declare(strict_types=1);

namespace Modules\Transactions\Brief;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealAccess;

/**
 * Deals in the daily brief (specs/core/09): contract deadlines this week and Deal-IQ problems to fix on the deals
 * the person can see (for brokers, every open deal), each with the deal's commission at stake.
 */
final class DealBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('deals', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        $deals = DealAccess::query($user)->whereIn('status', ['pre_contract', 'under_contract'])->limit(300)->get()->keyBy('id');
        if ($deals->isEmpty()) {
            return [];
        }
        $db = DB::connection(Connections::cell());
        $today = CarbonImmutable::today();
        $items = [];
        foreach ($db->table('tx_dates')->whereIn('deal_id', $deals->keys()->all())->whereNull('done_at')->where('due_on', '<=', $today->addDays(7)->toDateString())
            ->orderBy('due_on')->limit(20)->get() as $d) {
            $deal = $deals->get((int) $d->deal_id);
            if ($deal === null) {
                continue;
            }
            $due = CarbonImmutable::parse(substr((string) $d->due_on, 0, 10));
            $when = $due->lt($today) ? 'was due '.$due->format('M j') : ($due->isSameDay($today) ? 'is due today' : 'is due '.$due->format('l, M j'));
            $items[] = ['key' => 'date:'.$d->id, 'title' => $d->label.': '.trim($deal->street.', '.$deal->city, ', '), 'why' => 'The contract '.$when.'. A missed deadline can cost the deal.',
                'action' => 'Open the deal', 'url' => '/deals/'.$deal->ulid, 'urgency' => $due->lte($today->addDay()) ? 3 : 2, 'stakes_cents' => $deal->gci_cents];
        }
        $findings = $db->table('tx_findings')->whereIn('deal_id', $deals->keys()->all())->where('status', 'open')->whereIn('severity', ['blocker', 'warning'])
            ->orderByRaw("case when severity = 'blocker' then 0 else 1 end")->limit(60)->get()->groupBy('deal_id');
        foreach ($findings as $dealId => $rows) {
            $deal = $deals->get((int) $dealId);
            if ($deal === null) {
                continue;
            }
            $blockers = $rows->where('severity', 'blocker')->count();
            $items[] = ['key' => 'findings:'.$dealId, 'title' => 'Fix '.$rows->count().' '.($rows->count() === 1 ? 'problem' : 'problems').' on '.trim($deal->street.', '.$deal->city, ', '),
                'why' => (string) ($rows->first()->message ?? 'Deal-IQ found a problem.').($rows->count() > 1 ? ' (and '.($rows->count() - 1).' more)' : ''),
                'action' => 'Fix it', 'url' => '/deals/'.$deal->ulid, 'urgency' => $blockers > 0 ? 3 : 2, 'stakes_cents' => $deal->gci_cents];
        }

        return $items;
    }
}
