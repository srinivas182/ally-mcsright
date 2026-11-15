<?php

declare(strict_types=1);

namespace Modules\Billing\Brief;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/** The brokerage's RightAlly Credits running low, in brokers' daily brief (specs/core/09): AI and ads stop at zero. */
final class CreditsBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('credits', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        if (! DailyBriefs::manager($user)) {
            return [];
        }
        $balance = app(CreditLedger::class)->balanceOf(null);
        $used = (int) DB::connection(Connections::cell())->table('credit_ledger')->where('tenant_id', app(TenantContext::class)->id())->where('entry_type', 'consume')
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(30))->sum('amount');
        $perDay = abs($used) / 30;
        if ($perDay <= 0 || $balance >= $perDay * 7) {
            return [];
        }
        $days = (int) floor($balance / $perDay);

        return [['key' => 'credits', 'title' => 'RightAlly Credits run out in about '.$days.' '.($days === 1 ? 'day' : 'days'),
            'why' => number_format($balance).' left at about '.number_format((int) ceil($perDay)).' a day. AI follow-up, website chat and ads stop when they run out.',
            'action' => 'Buy credits', 'url' => '/settings/credits', 'urgency' => $days <= 2 ? 3 : 2, 'stakes_cents' => null]];
    }
}
