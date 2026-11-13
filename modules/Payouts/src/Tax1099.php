<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * Year-end 1099-NEC totals (specs/finance/02 part 5; revenue share addition 16): what the brokerage paid
 * each agent (commission and revenue share, before invoices and get-paid-now fees were taken — the agent
 * was paid that much) and each referral payee in the calendar year, plus commission title paid to agents
 * directly on the brokerage's behalf. Reportable from the year's threshold: $600 for 2025 payments, $2,000
 * for 2026 (One Big Beautiful Bill Act); indexed from 2027 — HQ updates the figure each year.
 */
final class Tax1099
{
    public const THRESHOLDS = [2025 => 60_000, 2026 => 200_000];

    public static function threshold(int $year): int
    {
        return $year <= 2025 ? 60_000 : (self::THRESHOLDS[$year] ?? (int) config('billing.nec_threshold_cents', 200_000));
    }

    /**
     * @return list<array{payee: string, kind: string, user_id: int|null, referral_id: int|null, paid_cents: int, reportable: bool, w9: array<string, string>|null}>
     */
    public function forYear(int $year): array
    {
        $db = DB::connection(Connections::cell());
        $w9s = app(W9s::class);
        $rows = [];
        $lines = $db->table('pay_run_lines')->join('pay_runs', 'pay_runs.id', '=', 'pay_run_lines.run_id')->where('pay_runs.status', 'sent')
            ->whereYear('pay_runs.sent_at', $year)->where('pay_run_lines.status', 'included')->whereNull('pay_run_lines.reversal_journal_id')
            ->get(['pay_run_lines.payee_type', 'pay_run_lines.user_id', 'pay_run_lines.referral_id', 'pay_run_lines.name', 'pay_run_lines.commission_cents',
                'pay_run_lines.revenue_share_cents', 'pay_run_lines.referral_cents']);
        foreach ($lines as $l) {
            $key = $l->payee_type === 'agent' ? 'u'.$l->user_id : 'r'.$l->referral_id;
            $rows[$key] ??= ['payee' => (string) $l->name, 'kind' => (string) $l->payee_type, 'user_id' => $l->user_id !== null ? (int) $l->user_id : null,
                'referral_id' => $l->referral_id !== null ? (int) $l->referral_id : null, 'paid_cents' => 0];
            $rows[$key]['paid_cents'] += (int) $l->commission_cents + (int) $l->revenue_share_cents + (int) $l->referral_cents;
        }
        foreach ($db->table('pay_recognitions')->whereNotNull('paid_by_title')->whereYear('created_at', $year)->get() as $r) {
            foreach ((array) json_decode((string) $r->paid_by_title, true) as $userId => $cents) {
                $key = 'u'.$userId;
                $rows[$key] ??= ['payee' => (string) (User::query()->find((int) $userId)->name ?? 'Agent'), 'kind' => 'agent', 'user_id' => (int) $userId, 'referral_id' => null, 'paid_cents' => 0];
                $rows[$key]['paid_cents'] += (int) $cents;
            }
        }
        $threshold = self::threshold($year);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $r + ['reportable' => $r['paid_cents'] >= $threshold, 'w9' => $w9s->current($r['user_id'], $r['user_id'] === null ? $r['referral_id'] : null)];
        }
        usort($out, fn ($a, $b) => $b['paid_cents'] <=> $a['paid_cents']);

        return $out;
    }

    /** CSV for filing (full TINs): finance only; every export is audited. */
    public function export(int $year, User $by): string
    {
        abort_unless($by->can('finance.billing.manage'), 403);
        $db = DB::connection(Connections::cell());
        $csv = ['payee,tin_type,tin,address,box1_nonemployee_compensation,w9_signed,tin_match'];
        foreach ($this->forYear($year) as $r) {
            if (! $r['reportable']) {
                continue;
            }
            $w = $db->table('pay_w9s')->where($r['user_id'] !== null ? 'user_id' : 'referral_id', $r['user_id'] ?? $r['referral_id'])->where('status', 'current')->first();
            $csv[] = implode(',', ['"'.str_replace('"', '""', $w !== null ? (string) $w->legal_name : $r['payee']).'"', $w !== null ? $w->tin_type : '',
                $w !== null ? app(W9s::class)->tin((int) $w->id) : 'MISSING', '"'.str_replace('"', '""', $w !== null ? (string) $w->address : '').'"',
                number_format($r['paid_cents'] / 100, 2, '.', ''), $w !== null ? 'yes' : 'no', $w !== null ? (string) $w->match_status : '']);
        }
        app(AuditLogger::class)->record('payouts.1099.exported', metadata: ['year' => $year, 'rows' => count($csv) - 1], severity: 'critical');

        return implode("\n", $csv)."\n";
    }
}
