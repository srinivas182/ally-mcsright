<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;

/**
 * Monthly agent statements (specs/finance/02, addition 4): opening and closing balances from the ledger,
 * and every line in between — commission per deal, revenue share, invoices, payments, invoice deductions
 * and get-paid-now fees — so every cent is explained.
 */
final class Statements
{
    /**
     * @return array{month: string, opening: array{owed_to_you: int, you_owe: int}, closing: array{owed_to_you: int, you_owe: int}, lines: list<array{date: string, text: string, cents: int}>, earned: int, paid: int}
     */
    public function month(User $agent, string $month): array
    {
        $from = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $to = $from->endOfMonth();
        $db = DB::connection(Connections::cell());
        $lines = [];

        foreach ($db->table('pay_recognitions')->whereBetween('created_at', [$from, $to->endOfDay()])->get() as $r) {
            $cents = (int) (((array) json_decode((string) $r->payables, true))[(string) $agent->id] ?? 0);
            $direct = (int) (((array) json_decode((string) ($r->paid_by_title ?? '[]'), true))[(string) $agent->id] ?? 0);
            $street = (string) $db->table('tx_deals')->where('id', $r->deal_id)->value('street');
            if ($cents > 0) {
                $lines[] = ['date' => substr((string) $r->created_at, 0, 10), 'text' => "Commission for {$street}", 'cents' => $cents];
            }
            if ($direct > 0) {
                $lines[] = ['date' => substr((string) $r->created_at, 0, 10), 'text' => "Commission for {$street}, paid to you directly by title", 'cents' => 0];
            }
        }
        foreach ($db->table('rs_entries')->where('recipient_user_id', $agent->id)->whereIn('kind', ['share', 'root', 'bonus'])
            ->whereBetween('closing_date', [$from->toDateString(), $to->toDateString()])->orderBy('closing_date')->get() as $e) {
            $source = User::query()->find((int) $e->source_agent_user_id)->name ?? 'a former agent';
            $text = $e->kind === 'bonus' ? "Milestone bonus: {$source}'s first closing" : "Revenue share from {$source}'s closing (level {$e->level})";
            $lines[] = ['date' => (string) $e->closing_date, 'text' => $text, 'cents' => (int) $e->amount_cents];
        }
        foreach ($db->table('bill_invoices')->where('user_id', $agent->id)->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])->get() as $i) {
            $what = $db->table('bill_invoice_lines')->where('invoice_id', $i->id)->pluck('description')->implode(', ');
            $lines[] = ['date' => (string) $i->issued_on, 'text' => "Invoice {$i->number}: {$what}", 'cents' => -(int) $i->total_cents];
        }
        foreach ($db->table('pay_run_lines')->join('pay_runs', 'pay_runs.id', '=', 'pay_run_lines.run_id')->where('pay_run_lines.user_id', $agent->id)
            ->where('pay_runs.status', 'sent')->whereBetween('pay_runs.sent_at', [$from, $to->endOfDay()])->where('pay_run_lines.status', 'included')
            ->get(['pay_runs.sent_at', 'pay_run_lines.*']) as $l) {
            $date = substr((string) $l->sent_at, 0, 10);
            $how = match ((string) $l->method) {
                'ach' => 'by direct deposit', 'check' => 'by check'.($l->check_number !== null ? " #{$l->check_number}" : ''), default => ''
            };
            if ((int) $l->deduction_cents > 0) {
                $lines[] = ['date' => $date, 'text' => 'Invoices paid from your commission', 'cents' => 0];
            }
            if ((int) $l->fee_cents > 0) {
                $lines[] = ['date' => $date, 'text' => 'Get paid now fee', 'cents' => -(int) $l->fee_cents];
            }
            if (PayoutRuns::net($l) > 0) {
                $lines[] = ['date' => $date, 'text' => trim("Paid to you {$how}").($l->rail_status === 'returned' || $l->rail_status === 'failed' ? ' — did not go through; back in your balance' : ''),
                    'cents' => -PayoutRuns::net($l)];
            }
        }
        usort($lines, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return ['month' => $from->format('Y-m'), 'opening' => $this->balances($agent, $from->subDay()->endOfDay()), 'closing' => $this->balances($agent, $to->endOfDay()),
            'lines' => $lines, 'earned' => array_sum(array_map(fn ($l) => max(0, $l['cents']), $lines)),
            'paid' => -array_sum(array_map(fn ($l) => str_starts_with($l['text'], 'Paid to you') ? $l['cents'] : 0, $lines))];
    }

    /**
     * @return array{owed_to_you: int, you_owe: int}
     */
    private function balances(User $agent, CarbonImmutable $asOf): array
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();
        $ledger = app(LedgerService::class);
        $owed = $ledger->balance($accounts->subAccount('2000', 'user', $agent->id, 'Commission payable'), $asOf)->cents
            + $ledger->balance($accounts->subAccount('2100', 'user', $agent->id, 'Revenue share'), $asOf)->cents;

        return ['owed_to_you' => max(0, $owed), 'you_owe' => $ledger->balance($accounts->subAccount('1200', 'user', $agent->id, 'Agent fees receivable'), $asOf)->cents + max(0, -$owed)];
    }
}
