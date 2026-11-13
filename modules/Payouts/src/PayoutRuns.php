<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;

/**
 * Payout runs (specs/finance/02, addition 3): built from what is ready (commission payable from settled
 * deals, revenue share wallets, referral fees the brokerage pays), previewed line by line, approved by
 * someone who is not a payee and has not changed a payee's bank details in the last 30 days, then sent as
 * a NACHA file and/or checks with a positive pay file. Agents in their 72-hour bank-change hold are
 * skipped. Sending posts the payments to the ledger and tells each agent they've been paid.
 */
final class PayoutRuns
{
    public function __construct(private readonly BankAccounts $banks, private readonly TenantContext $tenants) {}

    public function build(CarbonImmutable $payOn, bool $sameDay, User $by): string
    {
        $this->guardFinance($by);
        $db = DB::connection(Connections::cell());
        if ($db->table('pay_runs')->where('kind', 'regular')->whereIn('status', ['draft', 'approved'])->exists()) {
            throw ValidationException::withMessages(['run' => 'Finish or cancel the open payout run first.']);
        }
        $accounts = $this->accounts();
        $ledger = app(LedgerService::class);
        $ulid = (string) Str::ulid();

        return $db->transaction(function () use ($db, $ulid, $payOn, $sameDay, $by, $accounts, $ledger): string {
            $rails = app(Rails\Rails::class)->settings();
            $rail = $rails['rail'] !== 'bank_file' && $rails['status'] === 'ready' ? $rails['rail'] : 'bank_file';
            $runId = $db->table('pay_runs')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'status' => 'draft', 'pay_on' => $payOn->toDateString(),
                'same_day' => $sameDay, 'rail' => $rail, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
            $total = 0;

            foreach (User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->orderBy('first_name')->get() as $user) {
                $commission = $ledger->balance($accounts->subAccount('2000', 'user', $user->id, 'Commission payable — '.$user->first_name))->cents;
                $share = $ledger->balance($accounts->subAccount('2100', 'user', $user->id, 'Revenue share — '.$user->first_name))->cents;
                $amount = max(0, $commission) + max(0, $share);
                if ($amount === 0) {
                    continue;
                }
                $bank = $this->banks->current($user);
                [$method, $status, $reason] = match (true) {
                    $user->status !== UserStatus::Active => ['none', 'skipped', 'Account not active — review before paying'],
                    $bank !== null && ! $bank['usable'] => ['none', 'skipped', 'Bank account changed recently — held until '.CarbonImmutable::parse($bank['usable_from'])->toDayDateTimeString()],
                    $bank !== null => ['ach', 'included', null],
                    default => ['check', 'included', null],
                };
                // Unpaid agent invoices are taken from commission first (when the brokerage does that).
                $deduction = $status === 'included' && app(AgentBilling::class)->settings()['deduct_from_commission'] ? min($amount, app(AgentBilling::class)->owed($user->id)) : 0;
                if ($status === 'included' && $amount - $deduction === 0) {
                    $method = 'none';
                }
                $db->table('pay_run_lines')->insert(['tenant_id' => $this->tenants->id(), 'run_id' => $runId, 'payee_type' => 'agent', 'user_id' => $user->id, 'name' => $user->name,
                    'commission_cents' => max(0, $commission), 'revenue_share_cents' => max(0, $share), 'amount_cents' => $amount, 'deduction_cents' => $deduction,
                    'method' => $method, 'status' => $status, 'skip_reason' => $reason]);
                $total += $status === 'included' ? $amount : 0;
            }

            foreach ($db->table('tx_referrals')->where('direction', 'out')->where('paid_by', 'brokerage')->whereNull('paid_at')->get() as $ref) {
                $due = $ledger->balance($accounts->subAccount('2200', 'referral', (int) $ref->id, 'Referral payable — '.$ref->brokerage))->cents;
                if ($due > 0) {
                    $db->table('pay_run_lines')->insert(['tenant_id' => $this->tenants->id(), 'run_id' => $runId, 'payee_type' => 'referral', 'referral_id' => $ref->id,
                        'name' => $ref->brokerage.' ('.$ref->agent_name.')', 'referral_cents' => $due, 'amount_cents' => $due, 'method' => 'check', 'status' => 'included']);
                    $total += $due;
                }
            }
            $db->table('pay_runs')->where('id', $runId)->update(['total_cents' => $total]);
            app(AuditLogger::class)->record('payouts.run.built', metadata: ['run' => $ulid, 'total_cents' => $total, 'pay_on' => $payOn->toDateString()], severity: 'notice');

            return $ulid;
        });
    }

    public function approve(string $ulid, User $by): void
    {
        $run = $this->run($ulid, 'draft');
        if (! ($by->isKind(UserKind::Owner) || $by->can('finance.payouts.approve'))) {
            throw ValidationException::withMessages(['run' => 'Approving payouts needs the "Approve payouts" permission.']);
        }
        $db = DB::connection(Connections::cell());
        $payees = $db->table('pay_run_lines')->where('run_id', $run['id'])->where('status', 'included')->whereNotNull('user_id')->pluck('user_id')->map(fn ($v): int => (int) $v)->all();
        if (in_array($by->id, $payees, true)) {
            throw ValidationException::withMessages(['run' => 'You are paid in this run, so someone else must approve it.']);
        }
        $changed = $db->table('pay_bank_accounts')->whereIn('user_id', $payees)->where('changed_by', $by->id)->where('created_at', '>=', now()->subDays(30))->exists();
        if ($changed) {
            throw ValidationException::withMessages(['run' => 'You changed a payee\'s bank details in the last 30 days, so someone else must approve this run.']);
        }
        $db->table('pay_runs')->where('id', $run['id'])->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('payouts.run.approved', metadata: ['run' => $ulid, 'total_cents' => $run['total_cents']], severity: 'warning');
    }

    public function cancel(string $ulid, User $by): void
    {
        $run = DB::connection(Connections::cell())->table('pay_runs')->where('ulid', $ulid)->whereIn('status', ['draft', 'approved'])->first();
        if ($run === null) {
            throw ValidationException::withMessages(['run' => 'Only an unsent run can be cancelled.']);
        }
        DB::connection(Connections::cell())->table('pay_runs')->where('id', $run->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        app(AuditLogger::class)->record('payouts.run.cancelled', metadata: ['run' => $ulid], severity: 'notice');
    }

    /**
     * Sends an approved run: numbers the checks, posts the payments, returns the files to hand to the bank.
     *
     * @return array{nacha: string|null, positive_pay: string|null, checks: list<array<string, mixed>>}
     */
    public function send(string $ulid, User $by, bool $system = false): array
    {
        if (! $system) {
            $this->guardFinance($by);
        }
        $run = $this->run($ulid, 'approved');
        $settings = $this->settings();
        $db = DB::connection(Connections::cell());
        $lines = $db->table('pay_run_lines')->where('run_id', $run['id'])->where('status', 'included')->orderBy('id')->get();
        $ach = $lines->where('method', 'ach')->filter(fn ($l): bool => self::net($l) > 0);
        $rail = (string) (DB::connection(Connections::cell())->table('pay_runs')->where('ulid', $ulid)->value('rail') ?? 'bank_file');
        if ($rail === 'bank_file' && $ach->isNotEmpty() && ($settings['company_id'] === null || $settings['odfi_routing'] === null)) {
            throw ValidationException::withMessages(['run' => 'Add the brokerage\'s ACH company ID and bank routing number in Finance › Payout settings first.']);
        }

        $result = $db->transaction(function () use ($db, $run, $ulid, $lines, $ach, $settings, $rail): array {
            $payOn = CarbonImmutable::parse($run['pay_on']);
            $checks = [];
            $numbers = [];
            $next = $settings['next_check_number'];
            foreach ($lines->where('method', 'check')->filter(fn ($l): bool => self::net($l) > 0) as $line) {
                $db->table('pay_run_lines')->where('id', $line->id)->update(['check_number' => $next]);
                $numbers[(int) $line->id] = $next;
                $checks[] = ['number' => $next, 'name' => (string) $line->name, 'cents' => self::net($line), 'date' => $payOn->toDateString(), 'memo' => 'Commission'];
                $next++;
            }
            $db->table('pay_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['next_check_number' => $next, 'updated_at' => now(), 'created_at' => now()]);

            $nacha = null;
            if ($ach->isNotEmpty() && $rail === 'bank_file') {
                $entries = [];
                foreach ($ach as $line) {
                    $bank = $this->banks->forPayout((int) $line->user_id);
                    if ($bank === null) {
                        throw ValidationException::withMessages(['run' => "{$line->name}'s bank account is no longer available; rebuild the run."]);
                    }
                    $entries[] = ['name' => $bank['holder'], 'routing' => $bank['routing'], 'account' => $bank['account'], 'type' => $bank['type'], 'cents' => self::net($line), 'id' => (string) $line->user_id];
                }
                $nacha = Nacha::build(['company_name' => (string) $settings['company_name'], 'company_id' => (string) $settings['company_id'],
                    'odfi_routing' => (string) $settings['odfi_routing'], 'bank_name' => (string) $settings['bank_name']], $entries, $payOn, CarbonImmutable::now());
            }
            $positivePay = $checks === [] ? null : $this->positivePay($checks, $settings['operating_account']);

            // Instant (get paid now) runs were already posted when the agent confirmed.
            $journal = $db->table('pay_runs')->where('ulid', $ulid)->value('journal_id') ?? $this->post($ulid, $lines, $payOn);
            $db->table('pay_runs')->where('id', $run['id'])->update(['status' => 'sent', 'sent_at' => now(), 'journal_id' => $journal,
                'file_sha256' => $nacha !== null ? hash('sha256', $nacha) : null, 'updated_at' => now()]);
            $db->table('tx_referrals')->whereIn('id', $lines->where('payee_type', 'referral')->pluck('referral_id'))->update(['paid_at' => now()]);
            foreach ($lines->where('payee_type', 'agent') as $line) {
                if ((int) $line->deduction_cents > 0) {
                    app(AgentBilling::class)->settle((int) $line->user_id, (int) $line->deduction_cents, 'commission', (int) $line->id);
                }
                if (self::net($line) === 0) {
                    continue;
                }
                User::query()->find((int) $line->user_id)?->notify(new YouvePaid(self::net($line), (string) $line->method, $payOn,
                    (int) $line->commission_cents, (int) $line->revenue_share_cents, $numbers[(int) $line->id] ?? null));
            }
            app(AuditLogger::class)->record('payouts.run.sent', metadata: ['run' => $ulid, 'rail' => $rail, 'ach' => $ach->count(), 'checks' => count($checks)], severity: 'warning');

            return ['nacha' => $nacha, 'positive_pay' => $positivePay, 'checks' => $checks];
        });

        // Automatic ACH: each payment goes to the partner after the books are committed, with an
        // idempotency key, so a partner outage never leaves the ledger half-posted and a retry never pays twice.
        if ($rail !== 'bank_file') {
            $this->submit($ulid, $run['same_day']);
        }

        return $result;
    }

    /** Submits (or retries) the run's direct-deposit payments through the brokerage's rail. */
    public function submit(string $ulid, bool $sameDay = false): int
    {
        $db = DB::connection(Connections::cell());
        $runRow = $db->table('pay_runs')->where('ulid', $ulid)->where('status', 'sent')->first();
        if ($runRow === null || $runRow->rail === 'bank_file') {
            return 0;
        }
        $registry = app(Rails\Rails::class);
        $rail = $registry->get((string) $runRow->rail);
        $config = $registry->settings()['config'];
        $submitted = 0;
        foreach ($db->table('pay_run_lines')->where('run_id', $runRow->id)->where('status', 'included')->where('method', 'ach')->whereNull('rail_ref')->whereNull('reversal_journal_id')->get()->filter(fn ($l): bool => self::net($l) > 0) as $line) {
            try {
                $bank = $this->banks->forPayout((int) $line->user_id) ?? throw new \RuntimeException('No usable bank account.');
                $payee = $db->table('pay_payees')->where('user_id', $line->user_id)->where('rail', $rail->key())->where('bank_account_id', $bank['id'])->value('ref');
                if ($payee === null) {
                    $user = User::query()->findOrFail((int) $line->user_id);
                    $payee = $rail->payee($bank['holder'], $user->email, $bank, $config);
                    $db->table('pay_payees')->insert(['tenant_id' => $this->tenants->id(), 'user_id' => $line->user_id, 'rail' => $rail->key(),
                        'bank_account_id' => $bank['id'], 'ref' => $payee, 'created_at' => now(), 'updated_at' => now()]);
                }
                $ref = $rail->pay((string) $payee, self::net($line), (bool) $runRow->same_day, 'rightally-line-'.$this->tenants->id().'-'.$line->id,
                    'Commission '.$runRow->pay_on, $config);
                $db->table('pay_run_lines')->where('id', $line->id)->update(['rail_ref' => $ref, 'rail_status' => 'submitted']);
                DB::connection(Connections::control())->table('pay_rail_refs')->insertOrIgnore(['ref' => $ref, 'rail' => $rail->key(), 'tenant_id' => $this->tenants->id(), 'created_at' => now()]);
                $submitted++;
            } catch (\Throwable $e) {
                $db->table('pay_run_lines')->where('id', $line->id)->update(['rail_status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 200)]);
                app(AuditLogger::class)->record('payouts.payment.not_submitted', metadata: ['line' => (int) $line->id, 'reason' => $e->getMessage()], severity: 'warning');
            }
        }

        return $submitted;
    }

    /**
     * A failed or returned payment: the money goes back to the payee's balance (one reversing journal)
     * and the agent and finance are told. Safe to call twice.
     */
    public function reverseLine(int $lineId, string $status, ?string $reason): void
    {
        $db = DB::connection(Connections::cell());
        $line = $db->table('pay_run_lines')->where('id', $lineId)->first();
        if ($line === null || $line->reversal_journal_id !== null) {
            return;
        }
        $accounts = $this->accounts();
        $draft = JournalDraft::make('payout_return', 'payout-return:'.$lineId)->effectiveOn(CarbonImmutable::now())->source('payout_line', (string) $lineId)
            ->describe('Payment '.$status.' — returned to balance')->debit($accounts->account('1000'), Money::of(self::net($line)));
        if ((int) $line->fee_cents > 0) {
            $draft->debit($accounts->account('4100'), Money::of((int) $line->fee_cents)); // the fee is refunded
        }
        // Deducted invoices stay paid; only what was sent (plus any fee) goes back to the balance.
        $deducted = (int) $line->deduction_cents;
        $commissionBack = (int) $line->commission_cents - min($deducted, (int) $line->commission_cents);
        $shareBack = (int) $line->revenue_share_cents - max(0, $deducted - (int) $line->commission_cents);
        if ($commissionBack > 0) {
            $draft->credit($accounts->subAccount('2000', 'user', (int) $line->user_id, 'Commission payable'), Money::of($commissionBack));
        }
        if ($shareBack > 0) {
            $draft->credit($accounts->subAccount('2100', 'user', (int) $line->user_id, 'Revenue share'), Money::of($shareBack));
        }
        if ((int) $line->referral_cents > 0) {
            $draft->credit($accounts->subAccount('2200', 'referral', (int) $line->referral_id, 'Referral payable'), Money::of((int) $line->referral_cents));
        }
        $journal = app(LedgerService::class)->post($draft)->id;
        $db->table('pay_run_lines')->where('id', $lineId)->update(['rail_status' => $status, 'failure_reason' => $reason !== null ? mb_substr($reason, 0, 200) : null, 'reversal_journal_id' => $journal]);
        if ($line->user_id !== null) {
            User::query()->find((int) $line->user_id)?->notify(new PaymentReturned((int) $line->amount_cents, $reason));
        }
        app(AuditLogger::class)->record('payouts.payment.'.$status, metadata: ['line' => $lineId, 'cents' => (int) $line->amount_cents, 'reason' => $reason], severity: 'warning');
    }

    /** What actually leaves the bank for a line: gross, less the get-paid-now fee and invoice deductions. */
    public static function net(\stdClass $line): int
    {
        return (int) $line->amount_cents - (int) ($line->fee_cents ?? 0) - (int) ($line->deduction_cents ?? 0);
    }

    /**
     * @return array{company_name: string|null, company_id: string|null, odfi_routing: string|null, bank_name: string|null, operating_account: string|null, next_check_number: int, instant_enabled: bool, instant_fee_flat_cents: int, instant_fee_percent: string, instant_min_cents: int}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('pay_settings')->first();

        return ['company_name' => $row?->company_name, 'company_id' => $row?->company_id, 'odfi_routing' => $row?->odfi_routing, 'bank_name' => $row?->bank_name,
            'operating_account' => $row?->operating_account_sealed !== null ? Crypt::decryptString((string) $row->operating_account_sealed) : null,
            'next_check_number' => (int) ($row->next_check_number ?? 1001), 'instant_enabled' => (bool) ($row->instant_enabled ?? false),
            'instant_fee_flat_cents' => (int) ($row->instant_fee_flat_cents ?? 0), 'instant_fee_percent' => (string) ($row->instant_fee_percent ?? '0'),
            'instant_min_cents' => (int) ($row->instant_min_cents ?? 10_000)];
    }

    public function saveSettings(string $companyName, string $companyId, string $odfiRouting, string $bankName, ?string $operatingAccount, ?int $nextCheck, User $by): void
    {
        if (! $by->can('finance.bank_details.manage')) {
            throw ValidationException::withMessages(['settings' => 'Changing payout settings needs the bank-details permission.']);
        }
        if (! BankAccounts::validRouting($odfiRouting) || trim($companyName) === '' || preg_match('/^[0-9A-Z ]{9,10}$/', $companyId) !== 1) {
            throw ValidationException::withMessages(['settings' => 'Give the company name, the 10-character ACH company ID from your bank, and a valid routing number.']);
        }
        $values = ['company_name' => mb_substr(mb_strtoupper(trim($companyName)), 0, 16), 'company_id' => $companyId, 'odfi_routing' => $odfiRouting,
            'bank_name' => mb_substr(trim($bankName), 0, 23), 'updated_at' => now(), 'created_at' => now()];
        if ($operatingAccount !== null && $operatingAccount !== '') {
            $values['operating_account_sealed'] = Crypt::encryptString(preg_replace('/\D/', '', $operatingAccount) ?? '');
        }
        if ($nextCheck !== null) {
            $values['next_check_number'] = $nextCheck;
        }
        DB::connection(Connections::cell())->table('pay_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], $values);
        app(AuditLogger::class)->record('payouts.settings.changed', metadata: ['company_id' => $companyId, 'odfi_routing_last4' => substr($odfiRouting, -4)], severity: 'critical');
    }

    /**
     * @param  list<array{number: int, name: string, cents: int, date: string, memo: string}>  $checks
     */
    private function positivePay(array $checks, ?string $account): string
    {
        $rows = ['account_number,check_number,issue_date,amount,payee'];
        foreach ($checks as $c) {
            $rows[] = implode(',', [$account ?? '', $c['number'], $c['date'], number_format($c['cents'] / 100, 2, '.', ''), '"'.str_replace('"', '""', $c['name']).'"']);
        }

        return implode("\n", $rows)."\n";
    }

    /**
     * @param  Collection<int, \stdClass>  $lines
     */
    public function post(string $ulid, Collection $lines, CarbonImmutable $on): int
    {
        $accounts = $this->accounts();
        $draft = JournalDraft::make('payout', 'payout-run:'.$ulid)->effectiveOn($on)->source('payout_run', $ulid)->describe('Payout run '.$on->toDateString());
        $total = 0;
        foreach ($lines as $l) {
            if ((int) $l->commission_cents > 0) {
                $draft->debit($accounts->subAccount('2000', 'user', (int) $l->user_id, 'Commission payable'), Money::of((int) $l->commission_cents));
            }
            if ((int) $l->revenue_share_cents > 0) {
                $draft->debit($accounts->subAccount('2100', 'user', (int) $l->user_id, 'Revenue share'), Money::of((int) $l->revenue_share_cents));
            }
            if ((int) $l->referral_cents > 0) {
                $draft->debit($accounts->subAccount('2200', 'referral', (int) $l->referral_id, 'Referral payable'), Money::of((int) $l->referral_cents));
            }
            $total += (int) $l->amount_cents;
        }
        $fees = (int) $lines->sum(fn ($l): int => (int) $l->fee_cents);
        $deductions = 0;
        foreach ($lines as $l) {
            if ((int) ($l->deduction_cents ?? 0) > 0) {
                $draft->credit($accounts->subAccount('1200', 'user', (int) $l->user_id, 'Agent fees receivable'), Money::of((int) $l->deduction_cents));
                $deductions += (int) $l->deduction_cents;
            }
        }
        if ($total - $fees - $deductions > 0) {
            $draft->credit($accounts->account('1000'), Money::of($total - $fees - $deductions));
        }
        if ($fees > 0) {
            $draft->credit($accounts->account('4100'), Money::of($fees)); // get-paid-now fees are the brokerage's income
        }

        return app(LedgerService::class)->post($draft)->id;
    }

    /**
     * @return array{id: int, total_cents: int, pay_on: string, same_day: bool}
     */
    private function run(string $ulid, string $status): array
    {
        $run = DB::connection(Connections::cell())->table('pay_runs')->where('ulid', $ulid)->first();
        if ($run === null || $run->status !== $status) {
            throw ValidationException::withMessages(['run' => 'This run is '.($run->status ?? 'missing').'.']);
        }

        return ['id' => (int) $run->id, 'total_cents' => (int) $run->total_cents, 'pay_on' => (string) $run->pay_on, 'same_day' => (bool) $run->same_day];
    }

    private function guardFinance(User $by): void
    {
        if (! $by->can('finance.receipts.record')) {
            throw ValidationException::withMessages(['run' => 'Preparing and sending payouts is for finance.']);
        }
    }

    private function accounts(): ChartOfAccounts
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();

        return $accounts;
    }
}
