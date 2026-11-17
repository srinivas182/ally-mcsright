<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * Keeping good agents (RC3, specs/grow/17): the brokerage's own agents who may be thinking of leaving, each with
 * the plain reasons and a suggested check-in for the broker. Signals come from the brokerage's own records:
 *
 * - closings down 30% or more on the year before (3+ before): 35
 * - not signed in for 30 days or more: 25
 * - their anniversary here in the next 45 days, often when agents compare plans: 15
 * - no deal opened in the last 90 days while they closed some the year before: 20
 *
 * Shown from 30 points. A check-in hides them for 60 days. Nothing is shown to the agent.
 */
final class Retention
{
    public const SHOW_FROM = 30;

    public const QUIET_DAYS = 60;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return list<array{user_id: int, ulid: string, name: string, score: int, reasons: list<string>, suggestion: string, last_checkin: string|null}>
     */
    public function risks(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $db = DB::connection(Connections::cell());
        $checkins = $db->table('rec_checkins')->where('tenant_id', $this->tenants->id())->groupBy('user_id')->selectRaw('user_id, max(created_at) as last')->pluck('last', 'user_id');
        $out = [];
        foreach (User::query()->where('kind', UserKind::Agent)->where('status', 'active')->where('created_at', '<=', $today->subDays(90))->orderBy('first_name')->get() as $agent) {
            $last = $checkins->get($agent->id);
            if ($last !== null && CarbonImmutable::parse((string) $last)->gt($today->subDays(self::QUIET_DAYS))) {
                continue;
            }
            [$score, $reasons] = $this->score($agent, $today);
            if ($score < self::SHOW_FROM) {
                continue;
            }
            $out[] = ['user_id' => $agent->id, 'ulid' => $agent->ulid, 'name' => $agent->name, 'score' => $score, 'reasons' => $reasons,
                'suggestion' => "Call {$agent->first_name} for a no-agenda catch-up: ask how business is going and what would help most this quarter.",
                'last_checkin' => $last !== null ? CarbonImmutable::parse((string) $last)->toDateString() : null];
        }
        usort($out, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $out;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    public function score(User $agent, CarbonImmutable $today): array
    {
        $closed = fn (CarbonImmutable $from, CarbonImmutable $to): int => Deal::query()->where('agent_user_id', $agent->id)->where('status', 'closed')
            ->whereBetween('closed_on', [$from->toDateString(), $to->toDateString()])->count();
        $now = $closed($today->subYear()->addDay(), $today);
        $before = $closed($today->subYears(2)->addDay(), $today->subYear());
        $score = 0;
        $reasons = [];
        if ($before >= 3 && $now * 10 <= $before * 7) {
            $score += 35;
            $reasons[] = "Closed {$now} in the last 12 months, down from {$before} the year before.";
        }
        $active = $agent->last_active_at ?? $agent->last_login_at;
        if ($active === null || CarbonImmutable::parse((string) $active)->lt($today->subDays(30))) {
            $score += 25;
            $reasons[] = $active === null ? 'Has not signed in yet.' : 'Last signed in '.CarbonImmutable::parse((string) $active)->toFormattedDateString().'.';
        }
        $joined = CarbonImmutable::parse((string) $agent->created_at);
        $anniversary = $joined->setYear($today->year);
        if ($anniversary->lt($today)) {
            $anniversary = $anniversary->addYear();
        }
        if ($joined->lt($today->subMonths(10)) && $anniversary->lte($today->addDays(45))) {
            $years = $anniversary->year - $joined->year;
            $score += 15;
            $reasons[] = "{$years} year".($years === 1 ? '' : 's').' here on '.$anniversary->toFormattedDateString().': a natural time to talk about their plan.';
        }
        $opened = Deal::query()->where('agent_user_id', $agent->id)->where('created_at', '>=', $today->subDays(90))->exists();
        if (! $opened && $before + $now > 0) {
            $score += 20;
            $reasons[] = 'No new deal in the last 90 days.';
        }

        return [min(100, $score), $reasons];
    }

    public function checkIn(User $agent, User $by, ?string $note): void
    {
        DB::connection(Connections::cell())->table('rec_checkins')->insert(['tenant_id' => $this->tenants->id(), 'user_id' => $agent->id, 'by_user_id' => $by->id,
            'note' => $note !== null ? mb_substr(trim($note), 0, 500) : null, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('recruiting.retention.checkin', $agent);
    }
}
