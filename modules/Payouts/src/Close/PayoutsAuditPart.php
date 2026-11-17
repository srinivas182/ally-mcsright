<?php

declare(strict_types=1);

namespace Modules\Payouts\Close;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Audit\AuditPackPart;
use Modules\Ledger\Audit\Csv;
use Modules\Payouts\Tax1099;

/**
 * Payouts' part of the audit pack (specs/finance/23): payout runs, trust reconciliations, and the 1099
 * tie-out (what each payee was paid, from the same run lines the books record). No TINs or bank numbers.
 */
final class PayoutsAuditPart implements AuditPackPart
{
    public function __construct(private readonly TenantContext $tenants, private readonly Tax1099 $tax) {}

    public function files(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tenant = $this->tenants->id();
        $db = DB::connection(Connections::cell());
        $name = fn (?int $id): string => $id === null ? '' : (User::query()->find($id)->name ?? 'User '.$id);
        $files = [];

        $files['payout_runs.csv'] = Csv::make(['Pay date', 'Run', 'Status', 'Total', 'Built by', 'Approved by', 'Approved at', 'Sent at', 'Entry in books', 'Bank file fingerprint (SHA-256)'],
            $db->table('pay_runs')->leftJoin('ledger_journals', 'ledger_journals.id', '=', 'pay_runs.journal_id')->where('pay_runs.tenant_id', $tenant)
                ->whereBetween('pay_runs.pay_on', [$from->toDateString(), $to->toDateString()])->orderBy('pay_runs.pay_on')
                ->get(['pay_runs.pay_on', 'pay_runs.ulid', 'pay_runs.status', 'pay_runs.total_cents', 'pay_runs.created_by', 'pay_runs.approved_by', 'pay_runs.approved_at',
                    'pay_runs.sent_at', 'ledger_journals.ulid as journal', 'pay_runs.file_sha256'])
                ->map(fn ($r): array => [(string) $r->pay_on, (string) $r->ulid, (string) $r->status, Csv::money((int) $r->total_cents), $name((int) $r->created_by),
                    $name($r->approved_by !== null ? (int) $r->approved_by : null), (string) $r->approved_at, (string) $r->sent_at, (string) $r->journal, (string) $r->file_sha256])->all());

        $files['trust_reconciliations.csv'] = Csv::make(['Month', 'Status', 'Bank ending balance', 'Prepared by', 'Approved by', 'Approved at', 'Statement file fingerprint (SHA-256)'],
            $db->table('trust_statements')->where('tenant_id', $tenant)->whereBetween('month', [$from->format('Y-m'), $to->format('Y-m')])->orderBy('month')->get()
                ->map(fn ($r): array => [(string) $r->month, (string) $r->status, Csv::money((int) $r->ending_balance_cents), $name((int) $r->prepared_by),
                    $name($r->approved_by !== null ? (int) $r->approved_by : null), (string) $r->approved_at, (string) $r->file_sha256])->all());

        $rows = [];
        for ($year = $from->year; $year <= $to->year; $year++) {
            foreach ($this->tax->forYear($year) as $p) {
                $rows[] = [$year, $p['payee'], $p['kind'], Csv::money($p['paid_cents']), $p['reportable'] ? 'yes' : 'no', $p['w9'] !== null ? 'yes' : 'no'];
            }
        }
        $files['1099_tieout.csv'] = Csv::make(['Year', 'Payee', 'Kind', 'Paid in the year', 'Reportable on a 1099-NEC', 'W-9 on file'], $rows);

        return $files;
    }
}
