<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Task;
use Modules\Transactions\Models\Deal;

/**
 * The business planner (specs/insights/01, K3): from a yearly income goal back to the closings, agreements,
 * appointments and conversations needed each week, using the agent's own last 12 months where there is enough history
 * (and sensible starting points where there isn't), then this week's and this year's actual activity against it.
 */
final class Planner
{
    public const WORKING_WEEKS = 48;

    /** Starting points when an agent has too little history. */
    public const DEFAULTS = ['net_share' => 0.7, 'commission_rate' => 0.028, 'closing_rate' => 0.6, 'agreement_rate' => 0.5, 'appointment_rate' => 0.1, 'average_price_cents' => 35000000];

    /**
     * @return array{average_price_cents: int, commission_rate: float, net_share: float, closing_rate: float, agreement_rate: float, appointment_rate: float, from_history: list<string>}
     */
    public function history(User $agent, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subYear();
        $closed = Deal::query()->where('agent_user_id', $agent->id)->where('status', 'closed')->whereBetween('closed_on', [$from->toDateString(), $today->toDateString()])->get();
        $created = Deal::query()->where('agent_user_id', $agent->id)->where('created_at', '>=', $from)->count();
        $counts = $this->activity($agent, $from, $today->endOfDay());
        $out = self::DEFAULTS + ['from_history' => []];
        if ($closed->count() >= 3 && (int) $closed->sum('price_cents') > 0) {
            $out['average_price_cents'] = intdiv((int) $closed->sum('price_cents'), $closed->count());
            $out['commission_rate'] = round((int) $closed->sum('gci_cents') / (int) $closed->sum('price_cents'), 4);
            $out['from_history'][] = 'average price and commission';
            $net = 0;
            $gci = 0;
            foreach ($closed as $d) {
                $calc = $d->calculation_id !== null ? Calculation::query()->find($d->calculation_id) : null;
                foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                    if ((int) ($row['user_id'] ?? 0) === $agent->id) {
                        $net += (int) ($row['agent_net_cents'] ?? 0);
                        $gci += $d->gci_cents;
                    }
                }
            }
            if ($gci > 0) {
                $out['net_share'] = round($net / $gci, 3);
                $out['from_history'][] = 'take-home share';
            }
        }
        if ($created >= 3 && $closed->count() > 0) {
            $out['closing_rate'] = round(min(1, $closed->count() / $created), 3);
            $out['from_history'][] = 'deals that close';
        }
        if ($counts['appointments'] >= 3 && $created > 0) {
            $out['agreement_rate'] = round(min(1, $created / $counts['appointments']), 3);
            $out['from_history'][] = 'appointments that sign';
        }
        if ($counts['conversations'] >= 5 && $counts['appointments'] > 0) {
            $out['appointment_rate'] = round(min(1, $counts['appointments'] / $counts['conversations']), 3);
            $out['from_history'][] = 'conversations that book';
        }

        return $out;
    }

    /**
     * @param  array{average_price_cents: int, commission_rate: float, net_share: float, closing_rate: float, agreement_rate: float, appointment_rate: float}  $rates
     * @return array{gci_cents: int, closings: int, agreements: int, appointments: int, conversations: int, weekly: array{closings: float, agreements: float, appointments: int, conversations: int}}
     */
    public static function plan(int $incomeCents, bool $takeHome, array $rates): array
    {
        $gci = $takeHome ? (int) ceil($incomeCents / max(0.05, $rates['net_share'])) : $incomeCents;
        $perClosing = max(1, (int) round($rates['average_price_cents'] * $rates['commission_rate']));
        $closings = (int) ceil($gci / $perClosing);
        $agreements = (int) ceil($closings / max(0.05, $rates['closing_rate']));
        $appointments = (int) ceil($agreements / max(0.05, $rates['agreement_rate']));
        $conversations = (int) ceil($appointments / max(0.01, $rates['appointment_rate']));

        return ['gci_cents' => $gci, 'closings' => $closings, 'agreements' => $agreements, 'appointments' => $appointments, 'conversations' => $conversations,
            'weekly' => ['closings' => round($closings / self::WORKING_WEEKS, 1), 'agreements' => round($agreements / self::WORKING_WEEKS, 1),
                'appointments' => (int) ceil($appointments / self::WORKING_WEEKS), 'conversations' => (int) ceil($conversations / self::WORKING_WEEKS)]];
    }

    /**
     * What the agent actually did: conversations (connected calls, meetings, messages they sent), appointments
     * (meetings and completed showings), agreements (deals started) and closings.
     *
     * @return array{conversations: int, appointments: int, agreements: int, closings: int}
     */
    public function activity(User $agent, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $acts = Activity::query()->where('user_id', $agent->id)->whereBetween('occurred_at', [$from, $to]);

        return [
            'conversations' => (clone $acts)->where(fn ($q) => $q->where(fn ($c) => $c->where('type', 'call')->where('outcome', 'connected'))
                ->orWhere('type', 'meeting')->orWhere(fn ($m) => $m->whereIn('type', ['text', 'email'])->where('direction', 'outbound')))->count(),
            'appointments' => (clone $acts)->where('type', 'meeting')->count()
                + Task::query()->where('assigned_to', $agent->id)->where('type', 'showing')->whereBetween('completed_at', [$from, $to])->count(),
            'agreements' => Deal::query()->where('agent_user_id', $agent->id)->whereBetween('created_at', [$from, $to])->count(),
            'closings' => Deal::query()->where('agent_user_id', $agent->id)->where('status', 'closed')->whereBetween('closed_on', [$from->toDateString(), $to->toDateString()])->count(),
        ];
    }

    /**
     * Everything the planner page shows.
     *
     * @return array<string, mixed>
     */
    public function view(User $agent, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $year = (int) $today->format('Y');
        $saved = DB::connection(Connections::cell())->table('insights_goals')->where('user_id', $agent->id)->where('year', $year)->value('plan');
        $inputs = is_string($saved) ? (array) json_decode($saved, true) : [];
        $rates = array_merge($this->history($agent, $today), array_intersect_key($inputs, self::DEFAULTS));
        $income = (int) ($inputs['income_cents'] ?? 0);
        $plan = $income > 0 ? self::plan($income, (bool) ($inputs['take_home'] ?? true), $rates) : null;
        $share = round($today->dayOfYear / ($today->isLeapYear() ? 366 : 365), 3);

        return [
            'year' => $year,
            'inputs' => ['income_cents' => $income, 'take_home' => (bool) ($inputs['take_home'] ?? true)],
            'rates' => $rates,
            'plan' => $plan,
            'week' => $this->activity($agent, $today->startOfWeek(), $today->endOfWeek()),
            'ytd' => $this->activity($agent, $today->startOfYear(), $today->endOfDay()),
            'year_share' => $share,
        ];
    }

    /**
     * "I want 24 closings this year" (SO1): the same plan, started from closings instead of income.
     */
    public function saveClosings(User $agent, int $year, int $closings): void
    {
        $rates = $this->history($agent);
        $perClosing = max(1, (int) round($rates['average_price_cents'] * $rates['commission_rate']));
        $this->save($agent, $year, ['income_cents' => $closings * $perClosing, 'take_home' => false]);
    }

    /**
     * Today's share of the yearly plan: conversations a working day (five days a week), and how many are done today.
     *
     * @return array{closings: int, per_day: int, done_today: int}|null null when the person has no plan this year
     */
    public function daily(User $agent, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today();
        $saved = DB::connection(Connections::cell())->table('insights_goals')->where('user_id', $agent->id)->where('year', (int) $today->format('Y'))->value('plan');
        $inputs = is_string($saved) ? (array) json_decode($saved, true) : [];
        if ((int) ($inputs['income_cents'] ?? 0) <= 0) {
            return null;
        }
        $plan = self::plan((int) $inputs['income_cents'], (bool) ($inputs['take_home'] ?? true), array_merge($this->history($agent, $today), array_intersect_key($inputs, self::DEFAULTS)));

        return ['closings' => $plan['closings'], 'per_day' => max(1, (int) ceil($plan['weekly']['conversations'] / 5)),
            'done_today' => $this->activity($agent, $today->startOfDay(), $today->endOfDay())['conversations']];
    }

    /**
     * @param  array{income_cents: int, take_home: bool, average_price_cents?: int, commission_rate?: float, net_share?: float, closing_rate?: float, agreement_rate?: float, appointment_rate?: float}  $inputs
     */
    public function save(User $agent, int $year, array $inputs): void
    {
        $rates = array_merge($this->history($agent), array_intersect_key($inputs, self::DEFAULTS));
        $plan = self::plan($inputs['income_cents'], $inputs['take_home'], $rates);
        Goals::save($agent, $year, $plan['gci_cents'], $plan['closings'], null, $agent);
        DB::connection(Connections::cell())->table('insights_goals')->where('user_id', $agent->id)->where('year', $year)->update(['plan' => json_encode($inputs)]);
    }
}
