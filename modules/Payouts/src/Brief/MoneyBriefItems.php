<?php

declare(strict_types=1);

namespace Modules\Payouts\Brief;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Payouts\Balances;

/**
 * Money in the daily brief (specs/core/09): for agents, money owed to them and ready to pay out; for brokers,
 * payout runs waiting for approval.
 */
final class MoneyBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('money', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        $items = [];
        if ($user->kind === UserKind::Agent) {
            $b = app(Balances::class)->for($user);
            if ($b['available_cents'] > 0) {
                $items[] = ['key' => 'balance', 'title' => '$'.number_format($b['available_cents'] / 100, 2).' is ready for you',
                    'why' => 'Commission and revenue share the brokerage owes you. It goes out in the next payout run, or get paid now.',
                    'action' => 'See my money', 'url' => '/my-money', 'urgency' => 1, 'stakes_cents' => $b['available_cents']];
            }
        }
        if (DailyBriefs::manager($user)) {
            foreach (DB::connection(Connections::cell())->table('pay_runs')->where('status', 'draft')->orderBy('pay_on')->limit(5)->get() as $run) {
                $items[] = ['key' => 'run:'.$run->id, 'title' => 'Approve the payout run of $'.number_format(((int) $run->total_cents) / 100, 2),
                    'why' => 'Agents are paid on '.substr((string) $run->pay_on, 0, 10).' once it is approved.', 'action' => 'Review and approve',
                    'url' => '/finance/payouts', 'urgency' => 3, 'stakes_cents' => (int) $run->total_cents];
            }
        }

        return $items;
    }
}
