<?php

declare(strict_types=1);

namespace Modules\Roster\Brief;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Support\Connections;

/** Licences and E&O cover expiring within 30 days, in the daily brief (specs/core/09): one's own, or (brokers) everyone's. */
final class LicenceBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('licences', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        $today = CarbonImmutable::today();
        $manager = DailyBriefs::manager($user);
        $rows = DB::connection(Connections::cell())->table('roster_credentials')->whereIn('kind', ['licence', 'eo'])->where('status', 'current')->whereNotNull('expires_on')
            ->where('expires_on', '<=', $today->addDays(30)->toDateString())->when(! $manager, fn ($q) => $q->where('user_id', $user->id))->orderBy('expires_on')->limit(20)->get();
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->unique()->all())->get()->keyBy('id');
        $items = [];
        foreach ($rows as $c) {
            $on = CarbonImmutable::parse(substr((string) $c->expires_on, 0, 10));
            $what = $c->kind === 'eo' ? 'E&O cover' : trim(($c->state_code ?? '').' licence');
            $who = (int) $c->user_id === $user->id ? 'Your' : (($names->get((int) $c->user_id)->name ?? 'An agent')."'s");
            $items[] = ['key' => 'cred:'.$c->id, 'title' => $who.' '.$what.($on->lt($today) ? ' expired ' : ' expires ').$on->format('M j'),
                'why' => 'No licence or cover, no commission: deals can\'t close or be paid without it.', 'action' => (int) $c->user_id === $user->id ? 'Renew' : 'Remind them',
                'url' => (int) $c->user_id === $user->id ? '/roster/me' : '/roster', 'urgency' => $on->lte($today->addDays(7)) ? 3 : 2, 'stakes_cents' => null];
        }

        return $items;
    }
}
