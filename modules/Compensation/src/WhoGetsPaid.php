<?php

declare(strict_types=1);

namespace Modules\Compensation;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\RevenueShare\Insights;
use Modules\Compensation\RevenueShare\RevenueShareEngine;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;

/**
 * CM2: "Who gets paid" on a transaction, tier by tier: the commission (GCI), each agent's share, fees and what they
 * take home, the brokerage's company dollar, and the revenue share level by level with who receives it. A closed
 * deal shows what was booked; an open one shows an estimate from the current calculation.
 *
 * Amounts follow field-level hiding: people who may see commission amounts see everything; an agent sees their
 * own line and the revenue share total, never what each upline earns.
 */
final class WhoGetsPaid
{
    public function __construct(private readonly RevenueShareEngine $engine) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(string $dealRef, User $viewer): ?array
    {
        $calc = Calculation::query()->where('deal_ref', $dealRef)->where('status', 'final')->orderByDesc('version')->first()
            ?? Calculation::query()->where('deal_ref', $dealRef)->where('status', 'pending')->orderByDesc('version')->first();
        if (! $calc instanceof Calculation) {
            return null;
        }
        $full = $viewer->isKind(UserKind::Owner) || $viewer->can('finance.amounts.view');
        $rows = (array) ($calc->result['agents'] ?? []);
        $ids = array_map(fn (array $r): int => (int) $r['user_id'], $rows);
        $final = $calc->status === 'final';
        $levels = $final ? $this->booked($calc) : $this->engine->preview($calc);
        foreach ($levels as $l) {
            $ids[] = (int) ($l['recipient_user_id'] ?? 0);
            $ids[] = (int) ($l['missed_user_id'] ?? 0);
        }
        $names = User::query()->whereKey(array_values(array_unique(array_filter($ids))))->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name]);

        $agents = [];
        $company = 0;
        foreach ($rows as $r) {
            /** @var array<string, mixed> $r */
            $id = (int) $r['user_id'];
            $company += (int) $r['company_cents'] + (int) ($r['post_cap_fee_cents'] ?? 0);
            $agents[] = $full || $id === $viewer->id ? [
                'name' => $names[$id] ?? 'Agent', 'plan' => $r['plan'] ?? null, 'share_cents' => (int) $r['share_cents'],
                'fees' => array_map(fn (array $f): array => ['name' => (string) $f['name'], 'cents' => (int) $f['cents']], (array) ($r['fees'] ?? [])),
                'team_split_cents' => (int) ($r['team_split_cents'] ?? 0), 'credit_cents' => (int) ($r['credit_used_cents'] ?? 0),
                'net_cents' => (int) $r['agent_net_cents'], 'company_cents' => (int) $r['company_cents'] + (int) ($r['post_cap_fee_cents'] ?? 0),
                'capped' => (bool) ($r['capped'] ?? false), 'hidden' => false,
            ] : ['name' => $names[$id] ?? 'Agent', 'plan' => $r['plan'] ?? null, 'hidden' => true];
        }

        $paid = array_sum(array_map(fn (array $l): int => in_array($l['kind'], ['share', 'root', 'bonus', 'held'], true) ? (int) $l['amount_cents'] : 0, $levels));
        $who = function (array $l) use ($names): string {
            return match ($l['kind']) {
                'share', 'bonus', 'held' => $names[(int) $l['recipient_user_id']] ?? 'A former agent',
                'root' => 'Brokerage ('.($names[(int) $l['recipient_user_id']] ?? 'root account').')',
                default => 'Not paid',
            };
        };

        $note = function (array $l) use ($names): ?string {
            if ($l['kind'] === 'held') {
                return 'Held for review';
            }
            $text = $l['reason'] !== null ? (Insights::REASONS[(string) $l['reason']] ?? null) : null;
            if ($l['kind'] !== 'share' && ($l['missed_user_id'] ?? null) !== null) {
                $text = trim(($text ?? 'Not paid').' ('.($names[(int) $l['missed_user_id']] ?? 'upline').')');
            }

            return $text;
        };

        return [
            'status' => $final ? 'final' : 'estimate',
            'version' => $calc->version,
            'gci_cents' => $full || in_array($viewer->id, array_map(fn (array $r): int => (int) $r['user_id'], $rows), true) ? (int) ($calc->result['gci_cents'] ?? 0) : null,
            'outside_referral_cents' => $full ? (int) ($calc->result['outside_referral_cents'] ?? 0) : null,
            'agents' => $agents,
            'company_cents' => $full ? $company : null,
            'revenue_share_cents' => $paid,
            'brokerage_keeps_cents' => $full ? $company - $paid : null,
            // Upline names and amounts only for people who may see everyone's money.
            'levels' => $full ? array_map(fn (array $l): array => [
                'level' => (int) $l['level'], 'percent' => (string) $l['percent'], 'who' => $who($l), 'kind' => (string) $l['kind'],
                'amount_cents' => (int) $l['amount_cents'],
                'note' => $note($l),
                'from' => $names[(int) $l['source_user_id']] ?? 'Agent',
            ], $levels) : [],
        ];
    }

    /**
     * What was booked at closing (reversed lines left out).
     *
     * @return list<array{level: int, percent: string, source_user_id: int, recipient_user_id: int|null, missed_user_id: int|null, kind: string, reason: string|null, amount_cents: int}>
     */
    private function booked(Calculation $calc): array
    {
        $db = DB::connection(Connections::cell());
        $reversed = $db->table('rs_entries')->where('calculation_id', $calc->id)->whereNotNull('reversal_of')->pluck('reversal_of')->map(fn ($v): int => (int) $v)->all();

        return array_values($db->table('rs_entries')->where('calculation_id', $calc->id)->whereNull('reversal_of')->whereNotIn('id', $reversed)
            ->whereIn('kind', ['share', 'root', 'bonus', 'missed', 'held'])->orderBy('source_agent_user_id')->orderBy('level')->orderBy('id')->get()
            ->map(fn (object $r): array => ['level' => (int) $r->level, 'percent' => (string) $r->percent, 'source_user_id' => (int) $r->source_agent_user_id,
                'recipient_user_id' => $r->recipient_user_id !== null ? (int) $r->recipient_user_id : null,
                'missed_user_id' => $r->missed_user_id !== null ? (int) $r->missed_user_id : null, 'kind' => (string) $r->kind,
                'reason' => $r->reason !== null ? (string) $r->reason : null, 'amount_cents' => (int) $r->amount_cents])->all());
    }
}
