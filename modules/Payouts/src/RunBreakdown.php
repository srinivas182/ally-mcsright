<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/**
 * CM2: what a payout line is made of: the deals whose commission it pays, and the revenue share by deal and level,
 * booked since that person's previous sent payment and up to this run. The line's total always comes from the books;
 * this is the explanation behind it.
 */
final class RunBreakdown
{
    /** @var array<string, string> deal reference => address */
    private array $streets = [];

    /** @var array<int, string> */
    private array $names = [];

    /**
     * @return list<array{kind: string, deal: string, level: int|null, from: string|null, cents: int}>
     */
    public function forLine(int $userId, int $runId): array
    {
        $db = DB::connection(Connections::cell());
        $run = $db->table('pay_runs')->where('id', $runId)->first(['created_at']);
        if ($run === null) {
            return [];
        }
        $until = (string) $run->created_at;
        $since = $db->table('pay_run_lines')->join('pay_runs', 'pay_runs.id', '=', 'pay_run_lines.run_id')
            ->where('pay_run_lines.user_id', $userId)->where('pay_runs.status', 'sent')->where('pay_runs.id', '<', $runId)
            ->max('pay_runs.created_at');
        $items = [];

        // Commission: deals finalised for this agent in the window.
        $calcs = $db->table('comp_calculation_agents')->join('comp_calculations', 'comp_calculations.id', '=', 'comp_calculation_agents.calculation_id')
            ->where('comp_calculation_agents.agent_user_id', $userId)->where('comp_calculations.status', 'final')
            ->where('comp_calculations.updated_at', '<=', $until)->when($since !== null, fn ($q) => $q->where('comp_calculations.updated_at', '>', $since))
            ->orderBy('comp_calculations.updated_at')->get(['comp_calculations.deal_ref', 'comp_calculations.result']);
        foreach ($calcs as $c) {
            foreach ((array) (json_decode((string) $c->result, true)['agents'] ?? []) as $row) {
                if ((int) ($row['user_id'] ?? 0) === $userId) {
                    $items[] = ['kind' => 'commission', 'deal' => $this->street((string) $c->deal_ref), 'level' => null, 'from' => null, 'cents' => (int) $row['agent_net_cents']];
                }
            }
        }

        // Revenue share: each level received in the window (reversals show as negative lines).
        $entries = $db->table('rs_entries')->where('recipient_user_id', $userId)->whereIn('kind', ['share', 'root', 'bonus'])
            ->where('created_at', '<=', $until)->when($since !== null, fn ($q) => $q->where('created_at', '>', $since))
            ->orderBy('created_at')->get(['deal_ref', 'level', 'source_agent_user_id', 'amount_cents', 'reversal_of', 'kind']);
        foreach ($entries as $e) {
            $items[] = ['kind' => $e->kind === 'bonus' ? 'bonus' : ($e->reversal_of !== null ? 'reversal' : 'revenue_share'), 'deal' => $this->street((string) $e->deal_ref),
                'level' => (int) $e->level, 'from' => $this->name((int) $e->source_agent_user_id), 'cents' => $e->reversal_of !== null ? -abs((int) $e->amount_cents) : (int) $e->amount_cents];
        }

        return $items;
    }

    private function street(string $dealRef): string
    {
        return $this->streets[$dealRef] ??= (string) (DB::connection(Connections::cell())->table('tx_deals')->where('ulid', $dealRef)->value('street') ?? $dealRef);
    }

    private function name(int $userId): string
    {
        return $this->names[$userId] ??= User::query()->find($userId)->name ?? 'A former agent';
    }
}
