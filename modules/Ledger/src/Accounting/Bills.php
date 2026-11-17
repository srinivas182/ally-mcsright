<?php

declare(strict_types=1);

namespace Modules\Ledger\Accounting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
 * Bills to pay (AC1, specs/finance/20): rent, MLS dues, software, marketing. Entering a bill books the expense and
 * what the brokerage owes (Dr expense, Cr 2400 Accounts payable); paying it moves the money (Dr 2400, Cr 1000
 * Operating cash). Voiding reverses the entries, never deletes them. Attach the vendor's PDF for the audit trail.
 */
final class Bills
{
    public function __construct(private readonly TenantContext $tenants, private readonly ChartOfAccounts $chart, private readonly LedgerService $ledger) {}

    /** @return list<array{code: string, name: string}> the expense accounts a bill can go to */
    public function expenseAccounts(): array
    {
        $this->chart->seed();

        return array_values(LedgerAccount::query()->where('type', AccountType::Expense)->where('status', 'active')->whereNull('parent_id')->orderBy('code')->get()
            ->map(fn (LedgerAccount $a): array => ['code' => $a->code, 'name' => $a->name])->all());
    }

    /**
     * @param  array{vendor: string, bill_number?: string|null, bill_date: string, due_date: string, account_code: string, amount: string, memo?: string|null}  $in
     */
    public function create(array $in, ?UploadedFile $file, User $by): string
    {
        $account = LedgerAccount::query()->where('code', $in['account_code'])->where('type', AccountType::Expense)->where('status', 'active')->first()
            ?? throw ValidationException::withMessages(['account_code' => 'Choose what the bill is for.']);
        $cents = Money::fromDecimal(str_replace([',', '$'], '', $in['amount']))->cents;
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount.']);
        }
        $billDate = CarbonImmutable::parse($in['bill_date']);
        $path = null;
        if ($file !== null) {
            if ($file->getMimeType() !== 'application/pdf' && ! in_array($file->getMimeType(), ['image/jpeg', 'image/png'], true)) {
                throw ValidationException::withMessages(['file' => 'Attach the bill as a PDF, JPG or PNG.']);
            }
            $path = 'acct/'.$this->tenants->id().'/bills/'.Str::ulid().'.'.($file->getMimeType() === 'application/pdf' ? 'pdf' : ($file->getMimeType() === 'image/png' ? 'png' : 'jpg'));
            Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));
        }
        $ulid = (string) Str::ulid();
        $vendor = mb_substr(trim($in['vendor']), 0, 160);
        $journal = $this->ledger->post(JournalDraft::make('bill', 'bill:'.$ulid)->effectiveOn($billDate)->source('bill', $ulid)->by($by->id)
            ->describe('Bill from '.$vendor.(($in['bill_number'] ?? '') !== '' ? ' #'.$in['bill_number'] : ''))
            ->debit($account, Money::of($cents))->credit($this->chart->account('2400'), Money::of($cents)));
        DB::connection(Connections::cell())->table('acct_bills')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'vendor' => $vendor,
            'bill_number' => ($in['bill_number'] ?? '') !== '' ? mb_substr((string) $in['bill_number'], 0, 60) : null, 'bill_date' => $billDate->toDateString(),
            'due_date' => CarbonImmutable::parse($in['due_date'])->toDateString(), 'account_code' => $account->code, 'amount_cents' => $cents,
            'memo' => ($in['memo'] ?? '') !== '' ? mb_substr((string) $in['memo'], 0, 500) : null, 'status' => 'open', 'journal_id' => $journal->id, 'file_path' => $path,
            'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.bill_added', metadata: ['bill' => $ulid, 'cents' => $cents], severity: 'notice');

        return $ulid;
    }

    public function pay(string $ulid, string $paidOn, ?string $reference, User $by): void
    {
        $bill = $this->open($ulid);
        $on = CarbonImmutable::parse($paidOn);
        $journal = $this->ledger->post(JournalDraft::make('bill_payment', 'bill-paid:'.$ulid)->effectiveOn($on)->source('bill', $ulid)->by($by->id)
            ->describe('Paid '.$bill->vendor.($reference ? ' ('.$reference.')' : ''))
            ->debit($this->chart->account('2400'), Money::of((int) $bill->amount_cents))->credit($this->chart->account('1000'), Money::of((int) $bill->amount_cents)));
        DB::connection(Connections::cell())->table('acct_bills')->where('id', $bill->id)->update(['status' => 'paid', 'paid_on' => $on->toDateString(),
            'payment_ref' => $reference !== null ? mb_substr($reference, 0, 60) : null, 'paid_journal_id' => $journal->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.bill_paid', metadata: ['bill' => $ulid], severity: 'notice');
    }

    public function void(string $ulid, string $reason, User $by): void
    {
        $bill = $this->open($ulid);
        $journal = LedgerJournal::query()->whereKey((int) $bill->journal_id)->first();
        if ($journal !== null) {
            $this->ledger->reverse($journal, 'Bill voided: '.mb_substr($reason, 0, 150), $by->id);
        }
        DB::connection(Connections::cell())->table('acct_bills')->where('id', $bill->id)->update(['status' => 'void', 'memo' => mb_substr(trim(($bill->memo ?? '').' Void: '.$reason), 0, 500), 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.bill_voided', metadata: ['bill' => $ulid, 'reason' => $reason], severity: 'warning');
    }

    /**
     * @return array{bills: list<array<string, mixed>>, due_cents: int, overdue_cents: int, due_week_cents: int}
     */
    public function summary(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $rows = DB::connection(Connections::cell())->table('acct_bills')->where('tenant_id', $this->tenants->id())->orderByRaw("status = 'open' desc")->orderBy('due_date')->limit(300)->get();
        $open = $rows->where('status', 'open');

        return ['bills' => array_values($rows->map(fn ($b): array => ['ulid' => (string) $b->ulid, 'vendor' => (string) $b->vendor, 'bill_number' => $b->bill_number,
            'bill_date' => (string) $b->bill_date, 'due_date' => (string) $b->due_date, 'account_code' => (string) $b->account_code, 'amount_cents' => (int) $b->amount_cents,
            'memo' => $b->memo, 'status' => (string) $b->status, 'paid_on' => $b->paid_on, 'overdue' => $b->status === 'open' && (string) $b->due_date < $today->toDateString(),
            'has_file' => $b->file_path !== null])->all()),
            'due_cents' => (int) $open->sum('amount_cents'), 'overdue_cents' => (int) $open->where('due_date', '<', $today->toDateString())->sum('amount_cents'),
            'due_week_cents' => (int) $open->where('due_date', '<=', $today->addDays(7)->toDateString())->sum('amount_cents')];
    }

    public function file(string $ulid): ?string
    {
        $path = DB::connection(Connections::cell())->table('acct_bills')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->value('file_path');

        return is_string($path) ? $path : null;
    }

    private function open(string $ulid): \stdClass
    {
        $bill = DB::connection(Connections::cell())->table('acct_bills')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if (! $bill instanceof \stdClass) {
            abort(404);
        }
        if ($bill->status !== 'open') {
            throw ValidationException::withMessages(['bill' => 'This bill is already '.$bill->status.'.']);
        }

        return $bill;
    }
}
