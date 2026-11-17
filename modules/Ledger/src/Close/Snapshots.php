<?php

declare(strict_types=1);

namespace Modules\Ledger\Close;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Ledger\Reports\FinancialStatements;

/**
 * A copy of the statements exactly as they were when a month (and, in December, the year) closed, with a
 * SHA-256 fingerprint so an auditor can tell it hasn't changed since (specs/finance/22).
 */
final class Snapshots
{
    public function __construct(private readonly FinancialStatements $statements) {}

    public function take(LedgerPeriod $period): void
    {
        $this->store($period, 'month', [
            'pnl' => $this->statements->profitAndLoss($period->starts_on, $period->ends_on),
            'balance' => $this->statements->balanceSheet($period->ends_on),
            'trial_balance' => $this->trialBalance($period),
        ]);
        if ($period->starts_on->month === 12) {
            $this->store($period, 'year', [
                'pnl' => $this->statements->profitAndLoss($period->starts_on->startOfYear(), $period->ends_on),
                'balance' => $this->statements->balanceSheet($period->ends_on),
                'trial_balance' => $this->trialBalance($period),
            ]);
        }
    }

    /**
     * @return array{payload: array<string, mixed>, sha256: string, created_at: string}|null
     */
    public function get(LedgerPeriod $period, string $kind): ?array
    {
        $row = DB::connection(Connections::cell())->table('ledger_period_snapshots')->where('tenant_id', $period->tenant_id)
            ->where('period_id', $period->id)->where('kind', $kind)->first();
        if ($row === null) {
            return null;
        }

        return ['payload' => (array) json_decode((string) $row->payload, true), 'sha256' => (string) $row->sha256, 'created_at' => (string) $row->created_at];
    }

    /** True when the stored copy still matches its fingerprint. */
    public function intact(LedgerPeriod $period, string $kind): bool
    {
        $row = DB::connection(Connections::cell())->table('ledger_period_snapshots')->where('tenant_id', $period->tenant_id)
            ->where('period_id', $period->id)->where('kind', $kind)->first(['payload', 'sha256']);

        return $row !== null && hash_equals((string) $row->sha256, hash('sha256', (string) $row->payload));
    }

    /**
     * Debit and credit totals per account up to the month's last day.
     *
     * @return list<array{code: string, name: string, debit_cents: int, credit_cents: int}>
     */
    public function trialBalance(LedgerPeriod $period): array
    {
        $rows = DB::connection(Connections::cell())->table('ledger_lines')->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_lines.journal_id')
            ->where('ledger_lines.tenant_id', $period->tenant_id)->where('ledger_journals.effective_date', '<=', $period->ends_on->toDateString())
            ->groupBy('ledger_lines.account_id')->selectRaw('ledger_lines.account_id as id, sum(ledger_lines.debit_cents) - sum(ledger_lines.credit_cents) as net')
            ->pluck('net', 'id');
        $out = [];
        foreach (LedgerAccount::query()->whereIn('id', $rows->keys()->all())->orderBy('code')->get() as $a) {
            $net = (int) $rows[$a->id];
            if ($net !== 0) {
                $out[] = ['code' => (string) $a->code, 'name' => (string) $a->name, 'debit_cents' => max($net, 0), 'credit_cents' => max(-$net, 0)];
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $payload */
    private function store(LedgerPeriod $period, string $kind, array $payload): void
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        DB::connection(Connections::cell())->table('ledger_period_snapshots')->insertOrIgnore(['tenant_id' => $period->tenant_id, 'period_id' => $period->id,
            'kind' => $kind, 'payload' => $json, 'sha256' => hash('sha256', $json), 'created_at' => now()]);
    }
}
