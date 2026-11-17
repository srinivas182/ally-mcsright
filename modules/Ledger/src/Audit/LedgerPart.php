<?php

declare(strict_types=1);

namespace Modules\Ledger\Audit;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditVerifier;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Close\Snapshots;
use Modules\Ledger\Models\LedgerPeriod;

/**
 * The books' part of the audit pack: statements as closed, trial balance, every entry, adjustments and who
 * approved them, month-end notes, bank reconciliations, and the finance part of the tamper-evident activity log.
 */
final class LedgerPart implements AuditPackPart
{
    public function __construct(private readonly Snapshots $snapshots, private readonly TenantContext $tenants) {}

    public function files(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tenant = $this->tenants->current();
        $db = DB::connection(Connections::cell());
        $names = [];
        $name = function (?int $id) use (&$names): string {
            if ($id === null) {
                return '';
            }

            return $names[$id] ??= (User::query()->find($id)->name ?? 'User '.$id);
        };
        $files = [];

        $periods = LedgerPeriod::query()->whereDate('starts_on', '>=', $from->toDateString())->whereDate('starts_on', '<=', $to->toDateString())->orderBy('starts_on')->get();
        $months = [];
        foreach ($periods as $p) {
            $snap = $p->isClosed() ? $this->snapshots->get($p, 'month') : null;
            $months[] = [$p->starts_on->format('Y-m'), $p->isClosed() ? 'closed' : 'open', $p->getAttribute('closed_at')?->toDateTimeString(),
                $name($p->getAttribute('closed_by') !== null ? (int) $p->getAttribute('closed_by') : null), $p->close_notes['note'] ?? '',
                implode(' | ', array_column($p->close_notes['items'] ?? [], 'title')), $snap['sha256'] ?? '',
                $snap === null ? '' : ($this->snapshots->intact($p, 'month') ? 'yes' : 'NO')];
            foreach (['month', 'year'] as $kind) {
                $s = $p->isClosed() ? $this->snapshots->get($p, $kind) : null;
                if ($s !== null) {
                    $files['statements/'.($kind === 'year' ? $p->starts_on->format('Y').'-year' : $p->starts_on->format('Y-m')).'.pdf'] = Pdf::loadView('ledger::statements', [
                        'pnl' => $s['payload']['pnl'], 'balance' => $s['payload']['balance'], 'brokerage' => $tenant->name,
                        'closed' => ['at' => $s['created_at'], 'sha256' => $s['sha256'], 'intact' => $this->snapshots->intact($p, $kind), 'trial_balance' => $s['payload']['trial_balance'] ?? []],
                    ])->setPaper('letter')->output();
                }
            }
        }
        $files['month_end.csv'] = Csv::make(['Month', 'Status', 'Closed at', 'Closed by', 'Note when closed', 'Items to check when closed', 'Statements fingerprint (SHA-256)', 'Unchanged since close'], $months);

        $last = $periods->last();
        if ($last !== null) {
            $files['trial_balance.csv'] = Csv::make(['Account', 'Name', 'Debit', 'Credit'], array_map(fn (array $r): array => [$r['code'], $r['name'],
                Csv::money($r['debit_cents']), Csv::money($r['credit_cents'])], $this->snapshots->trialBalance($last)));
        }

        $lines = $db->table('ledger_lines')->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_lines.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.account_id')
            ->leftJoin('ledger_journals as rev', 'rev.id', '=', 'ledger_journals.reversal_of_id')
            ->where('ledger_journals.tenant_id', $tenant->id)->whereBetween('ledger_journals.effective_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('ledger_journals.effective_date')->orderBy('ledger_journals.id')->orderBy('ledger_lines.id')
            ->get(['ledger_journals.effective_date', 'ledger_journals.ulid', 'ledger_journals.journal_type', 'ledger_journals.description', 'ledger_journals.posted_at',
                'ledger_journals.created_by', 'rev.ulid as reverses', 'ledger_accounts.code', 'ledger_accounts.name', 'ledger_lines.debit_cents', 'ledger_lines.credit_cents', 'ledger_lines.memo']);
        $files['general_ledger.csv'] = Csv::make(['Date', 'Entry', 'Type', 'Manual', 'Description', 'Account', 'Account name', 'Debit', 'Credit', 'Memo', 'Posted at', 'Posted by', 'Reverses entry'],
            $lines->map(fn ($l): array => [(string) $l->effective_date, (string) $l->ulid, (string) $l->journal_type, $l->journal_type === 'manual.adjustment' ? 'yes' : 'no',
                (string) $l->description, (string) $l->code, (string) $l->name, (int) $l->debit_cents > 0 ? Csv::money((int) $l->debit_cents) : '',
                (int) $l->credit_cents > 0 ? Csv::money((int) $l->credit_cents) : '', (string) $l->memo, (string) $l->posted_at,
                $name($l->created_by !== null ? (int) $l->created_by : null), (string) $l->reverses])->all());

        $files['adjustments.csv'] = Csv::make(['Entered on', 'Date in books', 'Description', 'Amount', 'Entered by', 'Status', 'Decided by', 'Decided at', 'Approver was the only one (approved own entry)', 'Note'],
            $db->table('ledger_adjustment_requests')->where('tenant_id', $tenant->id)->whereBetween('effective_date', [$from->toDateString(), $to->toDateString()])->orderBy('id')->get()
                ->map(fn ($r): array => [(string) $r->created_at, (string) $r->effective_date, (string) $r->description, Csv::money((int) $r->total_cents), $name((int) $r->requested_by),
                    (string) $r->status, $name($r->decided_by !== null ? (int) $r->decided_by : null), (string) $r->decided_at, (bool) $r->self_approved ? 'yes' : 'no', (string) $r->decision_note])->all());

        $files['bank_reconciliations.csv'] = Csv::make(['Month', 'Status', 'Bank ending balance', 'Prepared by', 'Approved by', 'Approved at', 'Statement file fingerprint (SHA-256)'],
            $db->table('acct_bank_statements')->where('tenant_id', $tenant->id)->whereBetween('month', [$from->format('Y-m'), $to->format('Y-m')])->orderBy('month')->get()
                ->map(fn ($r): array => [(string) $r->month, (string) $r->status, Csv::money((int) $r->ending_balance_cents), $name((int) $r->prepared_by),
                    $name($r->approved_by !== null ? (int) $r->approved_by : null), (string) $r->approved_at, (string) $r->file_sha256])->all());

        $events = $db->table('audit_events')->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('action', 'like', 'finance.%')->orWhere('action', 'like', 'trust.%')->orWhere('action', 'like', 'payouts.%'))
            ->whereBetween('occurred_at', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])->orderBy('chain_seq')
            ->get(['occurred_at', 'actor_name', 'actor_type', 'action', 'subject_type', 'subject_id', 'severity', 'chain_seq']);
        $files['activity_log.csv'] = Csv::make(['When', 'Who', 'Actor type', 'What', 'On', 'Id', 'Severity', 'Log sequence'],
            $events->map(fn ($e): array => [(string) $e->occurred_at, (string) $e->actor_name, (string) $e->actor_type, (string) $e->action, (string) $e->subject_type,
                (string) $e->subject_id, (string) $e->severity, (int) $e->chain_seq])->all());
        $chain = app(AuditVerifier::class)->verify($tenant->id);
        $files['activity_log_check.txt'] = ($chain['ok'] ? 'PASSED' : 'FAILED').": the activity log's hash chain was re-checked ({$chain['checked']} events). {$chain['message']}\n";

        return $files;
    }
}
