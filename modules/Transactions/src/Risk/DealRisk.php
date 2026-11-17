<?php

declare(strict_types=1);

namespace Modules\Transactions\Risk;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;

/**
 * A risk score for each open deal (RD1, specs/transactions/31), 0 to 100, so the review queue and the dashboard put
 * the riskiest files first. Explainable, never a black box: every point comes from something the reviewer can see
 * and fix: open blockers (25 each), warnings (8 each), missed key dates (15 each), dates due in the next 3 days
 * with no document yet (5 each), and closing within 7 days with blockers still open (+15).
 */
final class DealRisk
{
    /**
     * @param  list<int>  $dealIds
     * @return array<int, array{score: int, level: string, reasons: list<string>}>
     */
    public static function forDeals(array $dealIds, ?CarbonImmutable $today = null): array
    {
        if ($dealIds === []) {
            return [];
        }
        $today ??= CarbonImmutable::today();
        $db = DB::connection(Connections::cell());
        $findings = $db->table('tx_findings')->whereIn('deal_id', $dealIds)->where('status', 'open')->get(['deal_id', 'severity']);
        $dates = $db->table('tx_dates')->whereIn('deal_id', $dealIds)->whereNull('done_at')->get(['deal_id', 'due_on']);
        $closing = Deal::query()->whereIn('id', $dealIds)->pluck('closing_date', 'id');
        $out = [];
        foreach ($dealIds as $id) {
            $blockers = $findings->where('deal_id', $id)->where('severity', 'blocker')->count();
            $warnings = $findings->where('deal_id', $id)->where('severity', 'warning')->count();
            $missed = $dates->where('deal_id', $id)->filter(fn ($d): bool => (string) $d->due_on < $today->toDateString())->count();
            $soon = $dates->where('deal_id', $id)->filter(fn ($d): bool => (string) $d->due_on >= $today->toDateString() && (string) $d->due_on <= $today->addDays(3)->toDateString())->count();
            $close = $closing->get($id);
            $closingSoon = $close !== null && CarbonImmutable::parse((string) $close)->between($today, $today->addDays(7)) && $blockers > 0;
            $reasons = array_values(array_filter([
                $blockers > 0 ? $blockers.' '.($blockers === 1 ? 'blocker' : 'blockers') : null,
                $warnings > 0 ? $warnings.' '.($warnings === 1 ? 'warning' : 'warnings') : null,
                $missed > 0 ? $missed.' missed '.($missed === 1 ? 'date' : 'dates') : null,
                $soon > 0 ? $soon.' '.($soon === 1 ? 'date' : 'dates').' in the next 3 days' : null,
                $closingSoon ? 'closing within a week with blockers open' : null,
            ]));
            $score = min(100, $blockers * 25 + $warnings * 8 + $missed * 15 + $soon * 5 + ($closingSoon ? 15 : 0));
            $out[$id] = ['score' => $score, 'level' => $score >= 50 ? 'high' : ($score >= 20 ? 'medium' : 'low'), 'reasons' => $reasons];
        }

        return $out;
    }

    /**
     * Agents to coach: open deals per agent, average and high-risk counts, riskiest first.
     *
     * @return list<array{agent: string, deals: int, average: int, high: int}>
     */
    public static function byAgent(): array
    {
        $deals = Deal::query()->whereIn('status', ['pre_contract', 'under_contract'])->get(['id', 'agent_user_id']);
        $scores = self::forDeals(array_values($deals->pluck('id')->map(fn ($v): int => (int) $v)->all()));
        $names = User::query()->whereIn('id', $deals->pluck('agent_user_id')->unique()->all() ?: [0])->pluck('first_name', 'id');
        $rows = [];
        foreach ($deals->groupBy('agent_user_id') as $agentId => $group) {
            $s = $group->map(fn (Deal $d): int => $scores[$d->id]['score'] ?? 0);
            $rows[] = ['agent' => (string) ($names[$agentId] ?? 'Agent'), 'deals' => $group->count(), 'average' => (int) round((float) $s->avg()), 'high' => $s->filter(fn (int $v): bool => $v >= 50)->count()];
        }
        usort($rows, fn (array $a, array $b): int => [$b['high'], $b['average']] <=> [$a['high'], $a['average']]);

        return $rows;
    }
}
