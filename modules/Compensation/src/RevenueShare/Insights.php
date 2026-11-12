<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\PlanResolver;
use Modules\Compensation\Rules\CommissionRules;
use Modules\Compensation\Rules\RevenueShareRules;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Ledger\Money\Money;
use Throwable;

/**
 * What the revenue share screens show (specs/compensation/02, additions 5, 7, 8, 11, 13): network,
 * coaching, statements with every cent explained, missed earnings, retention alerts, the yearly income
 * disclosure, and the read-only plan simulator. Sponsors see colleagues' names and amounts, never
 * client or deal details.
 */
final class Insights
{
    public const REASONS = [
        'missing_upline' => 'No sponsor at this level',
        'ineligible_upline' => 'Not active at the time',
        'locked_level' => 'This level was not unlocked yet',
        'recipient_limit' => 'Your yearly maximum was reached',
        'budget' => "The brokerage's yearly budget was reached",
        'fallback_cap' => 'Above the root account limit',
        'rejected' => 'Rejected after review',
    ];

    public function __construct(private readonly Sponsorships $sponsorships, private readonly Unlocking $unlocking, private readonly RevenueShareEngine $engine) {}

    public function rulesFor(int $userId): ?RevenueShareRules
    {
        try {
            return app(PlanResolver::class)->for($userId, CarbonImmutable::now())->rules->revenueShare;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The agent's network as a tree, to the plan's depth (default 7).
     *
     * @param  list<int>  $seen
     * @return list<array<string, mixed>>
     */
    public function network(User $agent, ?RevenueShareRules $rules = null, int $level = 1, ?int $depth = null, array $seen = []): array
    {
        $depth ??= $rules !== null ? count($rules->levels) : 7;
        if ($level > $depth) {
            return [];
        }
        $now = CarbonImmutable::now();
        $seen[] = $agent->id;
        $nodes = [];
        foreach (User::query()->whereIn('id', $this->unlocking->frontLine($agent->id, $now))->orderBy('first_name')->get() as $member) {
            if (in_array($member->id, $seen, true)) {
                continue;
            }
            $p = $this->unlocking->production($member->id, $now->subMonths($rules !== null ? $rules->qualifyingWindowMonths : 6), $now);
            $nodes[] = [
                'ulid' => $member->ulid, 'name' => $member->name, 'level' => $level, 'active' => $member->status === UserStatus::Active,
                'qualifying' => $rules !== null && $level === 1 && (($rules->qualifyingMinClosings > 0 && $p['closings'] >= $rules->qualifyingMinClosings) || ($rules->qualifyingMinGciCents > 0 && $p['gci'] >= $rules->qualifyingMinGciCents)),
                'closings' => $p['closings'], 'children' => $this->network($member, $rules, $level + 1, $depth, $seen),
            ];
        }

        return $nodes;
    }

    /**
     * "Unlock your next tier": the first locked level, what it needs, and roughly what it would pay
     * each month at the network's recent pace.
     *
     * @return array<string, mixed>|null
     */
    public function coaching(User $agent, ?RevenueShareRules $rules): ?array
    {
        if ($rules === null || $rules->levels === []) {
            return null;
        }
        $now = CarbonImmutable::now();
        $qualifying = $this->unlocking->qualifyingFrontLine($agent->id, $rules, $now);
        $open = 0;
        foreach (array_keys($rules->levels) as $i) {
            if (! $this->unlocking->open($agent->id, $i + 1, $rules, $now)) {
                break;
            }
            $open = $i + 1;
        }
        if ($open >= count($rules->levels)) {
            return ['open_levels' => $open, 'all_open' => true, 'qualifying' => $qualifying];
        }
        $next = $open + 1;
        $needed = null;
        foreach ($rules->unlockThresholds as $l => $count) {
            if ($l <= $next) {
                $needed = $count;
            }
        }
        // Missed earnings at the locked level over the last 6 months, per month.
        $missed = (int) DB::connection(Connections::cell())->table('rs_entries')->where('missed_user_id', $agent->id)->where('reason', 'locked_level')
            ->where('level', $next)->where('closing_date', '>=', $now->subMonths(6)->toDateString())->sum('amount_cents');

        return ['open_levels' => $open, 'all_open' => false, 'next_level' => $next, 'qualifying' => $qualifying,
            'needed' => $needed !== null ? max(0, $needed - $qualifying) : null, 'monthly_estimate_cents' => intdiv($missed, 6)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statement(User $agent, int $limit = 200): array
    {
        $rows = DB::connection(Connections::cell())->table('rs_entries')->where('recipient_user_id', $agent->id)->whereIn('kind', ['share', 'root', 'bonus'])
            ->orderByDesc('closing_date')->orderByDesc('id')->limit($limit)->get();
        $names = User::query()->whereIn('id', $rows->pluck('source_agent_user_id'))->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name]);

        return $rows->map(fn ($r): array => [
            'date' => (string) $r->closing_date, 'level' => (int) $r->level, 'kind' => $r->kind, 'amount_cents' => (int) $r->amount_cents,
            'explained' => $this->explain((array) $r, $names[(int) $r->source_agent_user_id] ?? 'A former agent'),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function missed(User $agent): array
    {
        return DB::connection(Connections::cell())->table('rs_entries')->where('missed_user_id', $agent->id)->where('kind', 'missed')->whereNull('reversal_of')
            ->orderByDesc('closing_date')->limit(100)->get()
            ->map(fn ($r): array => ['date' => (string) $r->closing_date, 'level' => (int) $r->level, 'amount_cents' => (int) $r->amount_cents,
                'why' => self::REASONS[(string) $r->reason] ?? 'Not paid'])->values()->all();
    }

    /**
     * People in the network who may need help: no closing in 90 days, or no sign-in for 21 days.
     *
     * @return list<array{name: string, level: int, why: string, suggestion: string}>
     */
    public function retention(User $agent, ?RevenueShareRules $rules): array
    {
        $alerts = [];
        $now = CarbonImmutable::now();
        $walk = function (array $nodes) use (&$walk, &$alerts, $now): void {
            foreach ($nodes as $n) {
                $user = User::query()->where('ulid', $n['ulid'])->first();
                if ($user instanceof User && $user->status === UserStatus::Active) {
                    $closings = $this->unlocking->production($user->id, $now->subDays(90), $now)['closings'];
                    $quiet = $user->last_login_at === null || $user->last_login_at->lt($now->subDays(21));
                    if ($closings === 0 || $quiet) {
                        $alerts[] = ['name' => $user->name, 'level' => (int) $n['level'],
                            'why' => trim(($closings === 0 ? 'No closings in 90 days. ' : '').($quiet ? 'Has not signed in for 3 weeks.' : '')),
                            'suggestion' => $closings === 0 ? 'Offer a call, a mentor match or a Learn-IQ course on lead follow-up.' : 'Check in personally — a quick message often helps.'];
                    }
                }
                $walk($n['children']);
            }
        };
        $walk($this->network($agent, $rules, 1, min(3, $rules !== null ? count($rules->levels) : 3)));

        return $alerts;
    }

    /**
     * Yearly income disclosure: revenue share received by every agent in the brokerage.
     *
     * @return array<string, mixed>
     */
    public function disclosure(int $year): array
    {
        $agents = User::query()->where('kind', UserKind::Agent)->pluck('id');
        $totals = DB::connection(Connections::cell())->table('rs_entries')->whereIn('kind', ['share', 'bonus'])->whereYear('closing_date', $year)
            ->groupBy('recipient_user_id')->selectRaw('recipient_user_id, sum(amount_cents) as total')->pluck('total', 'recipient_user_id');
        $amounts = $agents->map(fn ($id): int => (int) ($totals[$id] ?? 0))->sort()->values()->all();
        $n = count($amounts);
        $pct = fn (int $p): int => $n === 0 ? 0 : $amounts[(int) min($n - 1, floor(($n - 1) * $p / 100))];

        return [
            'year' => $year, 'agents' => $n, 'earning_nothing' => count(array_filter($amounts, fn (int $a): bool => $a === 0)),
            'median_cents' => $pct(50), 'average_cents' => $n === 0 ? 0 : intdiv(array_sum($amounts), $n),
            'p90_cents' => $pct(90), 'top_cents' => $n === 0 ? 0 : (int) end($amounts),
        ];
    }

    /**
     * The simulator: last 12 months of closed deals replayed through proposed revenue share rules
     * (read-only; uses today's network and statuses).
     *
     * @param  array<string, mixed>  $proposed  full plan rules with a revenue_share section
     * @return array<string, mixed>
     */
    public function simulate(array $proposed): array
    {
        $rules = CommissionRules::fromArray($proposed)->revenueShare;
        if ($rules === null || $rules->levels === []) {
            return ['deals' => 0, 'paid_cents' => 0, 'missed_cents' => 0, 'company_cents' => 0, 'by_level' => []];
        }
        $now = CarbonImmutable::now();
        $byLevel = array_fill(1, count($rules->levels), 0);
        $paid = 0;
        $missed = 0;
        $company = 0;
        $deals = 0;
        foreach (Calculation::query()->where('status', 'final')->get() as $calc) {
            $input = DealSideInput::fromArray($calc->inputs);
            if ($input->closingDate->lt($now->subYear())) {
                continue;
            }
            $deals++;
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                $c = (int) $row['company_cents'] + (int) ($row['post_cap_fee_cents'] ?? 0);
                $company += $c;
                $base = match ($rules->basis) {
                    'gci' => (int) $row['share_cents'],
                    'company_dollar' => $c,
                    default => $c - (int) ($row['brokerage_fees_cents'] ?? 0) - (int) ($row['credit_used_cents'] ?? 0),
                };
                $dist = min($this->engine->distributable(max(0, $base), $rules), max(0, $c), $rules->perDealMaxCents ?? PHP_INT_MAX);
                if ($dist <= 0) {
                    continue;
                }
                $chain = $this->sponsorships->chain((int) $row['user_id'], $input->closingDate, count($rules->levels));
                foreach (Money::of($dist)->allocate($rules->levels) as $i => $part) {
                    $up = $chain[$i] ?? null;
                    $ok = $up !== null && User::query()->find($up)?->status === UserStatus::Active
                        && $this->unlocking->open($up, $i + 1, $rules, $input->closingDate);
                    if ($ok) {
                        $byLevel[$i + 1] += $part->cents;
                        $paid += $part->cents;
                    } else {
                        $missed += $part->cents;
                    }
                }
            }
        }

        return ['deals' => $deals, 'paid_cents' => $paid, 'missed_cents' => $missed, 'company_cents' => $company,
            'share_of_company' => $company > 0 ? (string) BigDecimal::of($paid)->multipliedBy(100)->dividedBy($company, 1, RoundingMode::HalfUp) : '0',
            'by_level' => array_map(fn ($l, $c): array => ['level' => $l, 'cents' => $c], array_keys($byLevel), array_values($byLevel))];
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function explain(array $r, string $source): string
    {
        $money = '$'.number_format((int) $r['amount_cents'] / 100, 2);
        $date = CarbonImmutable::parse((string) $r['closing_date'])->format('j M Y');
        $level = (int) $r['level'];

        return match ((string) $r['kind']) {
            'bonus' => "{$money} bonus: {$source} closed their first deal on {$date}",
            'root' => "{$money} as the root account from a level {$level} closing on {$date}",
            default => "{$money} from {$source}'s closing on {$date} (level {$level})",
        };
    }
}
