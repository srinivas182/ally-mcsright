<?php

declare(strict_types=1);

namespace Modules\Ledger\Accounting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Money\Money;

/**
 * Invoices to collect (AC1, specs/finance/20): money owed to the brokerage by others (a builder's co-op, a
 * relocation company's referral fee, a sponsor, a sublease). Issuing books it (Dr 1150 Accounts receivable, Cr the
 * income account); recording the payment moves it to the bank (Dr 1000, Cr 1150). Numbers run 1, 2, 3 per brokerage.
 * Agents' own fees are billed in Agent billing, not here.
 */
final class Invoices
{
    public function __construct(private readonly TenantContext $tenants, private readonly ChartOfAccounts $chart, private readonly LedgerService $ledger) {}

    /** @return list<array{code: string, name: string}> */
    public function incomeAccounts(): array
    {
        $this->chart->seed();

        return array_values(LedgerAccount::query()->where('type', AccountType::Revenue)->where('status', 'active')->whereNull('parent_id')->orderBy('code')->get()
            ->map(fn (LedgerAccount $a): array => ['code' => $a->code, 'name' => $a->name])->all());
    }

    /**
     * @param  array{customer: string, customer_email?: string|null, customer_address?: string|null, issue_date: string, due_date: string, account_code: string, memo?: string|null, lines: list<array{description: string, amount: string}>}  $in
     */
    public function create(array $in, User $by): string
    {
        $account = LedgerAccount::query()->where('code', $in['account_code'])->where('type', AccountType::Revenue)->where('status', 'active')->first()
            ?? throw ValidationException::withMessages(['account_code' => 'Choose the kind of income.']);
        $lines = [];
        foreach ($in['lines'] as $l) {
            $cents = Money::fromDecimal(str_replace([',', '$'], '', (string) $l['amount']))->cents;
            if (trim((string) $l['description']) !== '' && $cents > 0) {
                $lines[] = ['description' => mb_substr(trim((string) $l['description']), 0, 200), 'cents' => $cents];
            }
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line with a description and an amount.']);
        }
        $total = array_sum(array_column($lines, 'cents'));
        $ulid = (string) Str::ulid();
        $db = DB::connection(Connections::cell());
        $number = $db->transaction(function () use ($db, $in, $lines, $total, $ulid, $account, $by): int {
            $number = (int) $db->table('acct_invoices')->where('tenant_id', $this->tenants->id())->lockForUpdate()->max('number') + 1;
            $issue = CarbonImmutable::parse($in['issue_date']);
            $customer = mb_substr(trim($in['customer']), 0, 160);
            $journal = $this->ledger->post(JournalDraft::make('invoice', 'invoice:'.$ulid)->effectiveOn($issue)->source('invoice', $ulid)->by($by->id)
                ->describe('Invoice '.$number.' to '.$customer)->debit($this->chart->account('1150'), Money::of($total))->credit($account, Money::of($total)));
            $db->table('acct_invoices')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'number' => $number, 'customer' => $customer,
                'customer_email' => ($in['customer_email'] ?? '') !== '' ? $in['customer_email'] : null, 'customer_address' => ($in['customer_address'] ?? '') !== '' ? mb_substr((string) $in['customer_address'], 0, 300) : null,
                'issue_date' => $issue->toDateString(), 'due_date' => CarbonImmutable::parse($in['due_date'])->toDateString(), 'lines' => (string) json_encode($lines), 'total_cents' => $total,
                'account_code' => $account->code, 'memo' => ($in['memo'] ?? '') !== '' ? mb_substr((string) $in['memo'], 0, 500) : null, 'status' => 'open', 'journal_id' => $journal->id,
                'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);

            return $number;
        });
        app(AuditLogger::class)->record('finance.invoice_issued', metadata: ['invoice' => $ulid, 'number' => $number, 'cents' => $total], severity: 'notice');

        return $ulid;
    }

    public function paid(string $ulid, string $paidOn, ?string $reference, User $by): void
    {
        $inv = $this->open($ulid);
        $on = CarbonImmutable::parse($paidOn);
        $journal = $this->ledger->post(JournalDraft::make('invoice_payment', 'invoice-paid:'.$ulid)->effectiveOn($on)->source('invoice', $ulid)->by($by->id)
            ->describe('Payment for invoice '.$inv->number.' from '.$inv->customer)
            ->debit($this->chart->account('1000'), Money::of((int) $inv->total_cents))->credit($this->chart->account('1150'), Money::of((int) $inv->total_cents)));
        DB::connection(Connections::cell())->table('acct_invoices')->where('id', $inv->id)->update(['status' => 'paid', 'paid_on' => $on->toDateString(),
            'payment_ref' => $reference !== null ? mb_substr($reference, 0, 60) : null, 'paid_journal_id' => $journal->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.invoice_paid', metadata: ['invoice' => $ulid], severity: 'notice');
    }

    public function void(string $ulid, string $reason, User $by): void
    {
        $inv = $this->open($ulid);
        $journal = LedgerJournal::query()->whereKey((int) $inv->journal_id)->first();
        if ($journal !== null) {
            $this->ledger->reverse($journal, 'Invoice voided: '.mb_substr($reason, 0, 150), $by->id);
        }
        DB::connection(Connections::cell())->table('acct_invoices')->where('id', $inv->id)->update(['status' => 'void', 'memo' => mb_substr(trim(($inv->memo ?? '').' Void: '.$reason), 0, 500), 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.invoice_voided', metadata: ['invoice' => $ulid, 'reason' => $reason], severity: 'warning');
    }

    /**
     * @return array{invoices: list<array<string, mixed>>, open_cents: int, overdue_cents: int}
     */
    public function summary(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $rows = DB::connection(Connections::cell())->table('acct_invoices')->where('tenant_id', $this->tenants->id())->orderByDesc('number')->limit(300)->get();
        $open = $rows->where('status', 'open');

        return ['invoices' => array_values($rows->map(fn ($i): array => $this->row($i, $today))->all()), 'open_cents' => (int) $open->sum('total_cents'),
            'overdue_cents' => (int) $open->where('due_date', '<', $today->toDateString())->sum('total_cents')];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $ulid): ?array
    {
        $row = DB::connection(Connections::cell())->table('acct_invoices')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();

        return $row instanceof \stdClass ? $this->row($row, CarbonImmutable::today()) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(\stdClass $i, CarbonImmutable $today): array
    {
        return ['ulid' => (string) $i->ulid, 'number' => (int) $i->number, 'customer' => (string) $i->customer, 'customer_email' => $i->customer_email, 'customer_address' => $i->customer_address,
            'issue_date' => (string) $i->issue_date, 'due_date' => (string) $i->due_date, 'lines' => (array) json_decode((string) $i->lines, true), 'total_cents' => (int) $i->total_cents,
            'memo' => $i->memo, 'status' => (string) $i->status, 'paid_on' => $i->paid_on, 'overdue' => $i->status === 'open' && (string) $i->due_date < $today->toDateString()];
    }

    private function open(string $ulid): \stdClass
    {
        $inv = DB::connection(Connections::cell())->table('acct_invoices')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if (! $inv instanceof \stdClass) {
            abort(404);
        }
        if ($inv->status !== 'open') {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already '.$inv->status.'.']);
        }

        return $inv;
    }
}
