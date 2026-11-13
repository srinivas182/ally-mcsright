<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;

/**
 * Agent billing (specs/finance/02, part 4): fee items and agents' recurring fees, one-off charges and
 * invoices (receivable against agent fee revenue), payment by deduction from commission or online to the
 * brokerage's own Stripe account, reminders and a one-time late fee, and payment plans for agents who owe
 * the brokerage (a negative commission balance moved into instalment invoices).
 */
final class AgentBilling
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{deduct_from_commission: bool, late_fee_cents: int, grace_days: int, due_days: int}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('bill_settings')->first();

        return ['deduct_from_commission' => (bool) ($row->deduct_from_commission ?? true), 'late_fee_cents' => (int) ($row->late_fee_cents ?? 0),
            'grace_days' => (int) ($row->grace_days ?? 5), 'due_days' => (int) ($row->due_days ?? 15)];
    }

    public function saveSettings(bool $deduct, int $lateFeeCents, int $graceDays, int $dueDays, User $by): void
    {
        $this->guard($by);
        DB::connection(Connections::cell())->table('bill_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['deduct_from_commission' => $deduct,
            'late_fee_cents' => max(0, $lateFeeCents), 'grace_days' => max(0, $graceDays), 'due_days' => max(1, $dueDays), 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('billing.agent.settings', metadata: compact('deduct', 'lateFeeCents', 'graceDays', 'dueDays'), severity: 'notice');
    }

    public function saveItem(string $name, int $amountCents, string $frequency, User $by): string
    {
        $this->guard($by);
        if (trim($name) === '' || $amountCents <= 0 || ! in_array($frequency, ['monthly', 'yearly', 'once'], true)) {
            throw ValidationException::withMessages(['item' => 'Give the fee a name, an amount and how often it is charged.']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('bill_items')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'name' => mb_substr(trim($name), 0, 80),
            'amount_cents' => $amountCents, 'frequency' => $frequency, 'created_at' => now(), 'updated_at' => now()]);

        return $ulid;
    }

    public function assign(User $agent, string $itemUlid, CarbonImmutable $startsOn, User $by, ?int $amountCents = null): void
    {
        $this->guard($by);
        $item = DB::connection(Connections::cell())->table('bill_items')->where('ulid', $itemUlid)->where('active', true)->first();
        if ($item === null) {
            throw ValidationException::withMessages(['item' => 'Choose an active fee.']);
        }
        DB::connection(Connections::cell())->table('bill_assignments')->insert(['tenant_id' => $this->tenants->id(), 'user_id' => $agent->id, 'item_id' => $item->id,
            'amount_cents' => $amountCents ?? (int) $item->amount_cents, 'next_bill_on' => $startsOn->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.agent.fee_assigned', $agent, metadata: ['fee' => $item->name, 'from' => $startsOn->toDateString()], severity: 'notice', label: $agent->name);
    }

    public function charge(User $agent, string $description, int $cents, User $by): string
    {
        $this->guard($by);
        if (trim($description) === '' || $cents <= 0) {
            throw ValidationException::withMessages(['charge' => 'Describe the charge and give an amount.']);
        }

        return $this->issue($agent->id, [['description' => trim($description), 'cents' => $cents, 'item_id' => null]], CarbonImmutable::today());
    }

    /**
     * @param  list<array{description: string, cents: int, item_id: int|null}>  $lines
     */
    public function issue(int $userId, array $lines, CarbonImmutable $on, ?int $planId = null, bool $postsRevenue = true, ?CarbonImmutable $dueOn = null): string
    {
        $db = DB::connection(Connections::cell());

        return $db->transaction(function () use ($db, $userId, $lines, $on, $planId, $postsRevenue, $dueOn): string {
            $total = array_sum(array_column($lines, 'cents'));
            $number = (int) ($db->table('bill_settings')->value('next_number') ?? 1);
            $db->table('bill_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['next_number' => $number + 1, 'updated_at' => now(), 'created_at' => now()]);
            $ulid = (string) Str::ulid();
            $id = $db->table('bill_invoices')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $userId,
                'number' => 'INV-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT), 'issued_on' => $on->toDateString(),
                'due_on' => ($dueOn ?? $on->addDays($this->settings()['due_days']))->toDateString(), 'status' => 'open', 'total_cents' => $total,
                'plan_id' => $planId, 'posts_revenue' => $postsRevenue, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $l) {
                $db->table('bill_invoice_lines')->insert(['tenant_id' => $this->tenants->id(), 'invoice_id' => $id, 'description' => mb_substr($l['description'], 0, 160),
                    'amount_cents' => $l['cents'], 'item_id' => $l['item_id']]);
            }
            if ($postsRevenue) {
                $journal = $this->revenue($userId, $total, 'invoice:'.$ulid, $on, 'Agent invoice');
                $db->table('bill_invoices')->where('id', $id)->update(['journal_id' => $journal]);
            }
            app(AuditLogger::class)->record('billing.agent.invoice', metadata: ['invoice' => $ulid, 'user_id' => $userId, 'total_cents' => $total], severity: 'notice');

            return $ulid;
        });
    }

    /** What the agent owes on open invoices. */
    public function owed(int $userId): int
    {
        return (int) DB::connection(Connections::cell())->table('bill_invoices')->where('user_id', $userId)->where('status', 'open')
            ->selectRaw('coalesce(sum(total_cents - paid_cents), 0) as owed')->value('owed');
    }

    /** Applies money to the agent's open invoices, oldest first; returns what was applied. */
    public function settle(int $userId, int $cents, string $method, ?int $runLineId = null, ?string $reference = null): int
    {
        $db = DB::connection(Connections::cell());
        $left = $cents;
        foreach ($db->table('bill_invoices')->where('user_id', $userId)->where('status', 'open')->orderBy('due_on')->orderBy('id')->get() as $inv) {
            if ($left <= 0) {
                break;
            }
            $use = min($left, (int) $inv->total_cents - (int) $inv->paid_cents);
            $this->record((int) $inv->id, $use, $method, $runLineId, $reference);
            $left -= $use;
        }

        return $cents - $left;
    }

    /** Agent pays an invoice online to the brokerage's own Stripe account. */
    public function payOnline(string $invoiceUlid, User $agent, string $returnBase): string
    {
        $inv = DB::connection(Connections::cell())->table('bill_invoices')->where('ulid', $invoiceUlid)->where('user_id', $agent->id)->where('status', 'open')->first();
        $account = $this->tenants->current()->getAttribute('connect_account');
        if ($inv === null) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already paid.']);
        }
        if (! is_string($account) || ! $this->tenants->current()->getAttribute('connect_ready')) {
            throw ValidationException::withMessages(['invoice' => 'Your brokerage takes payment from your commission instead.']);
        }

        return app(PaymentGateway::class)->paymentCheckout($account, $agent->email, 'Invoice '.$inv->number, (int) $inv->total_cents - (int) $inv->paid_cents,
            $returnBase.'/my-money?paid=1', $returnBase.'/my-money', ['kind' => 'agent_invoice', 'tenant_id' => (string) $this->tenants->id(), 'invoice' => (string) $inv->ulid]);
    }

    /** From the payment webhook: an invoice paid online. */
    public function paidOnline(string $invoiceUlid, int $cents, string $reference): void
    {
        $inv = DB::connection(Connections::cell())->table('bill_invoices')->where('ulid', $invoiceUlid)->first();
        if ($inv === null || $inv->status !== 'open') {
            return;
        }
        $this->record((int) $inv->id, min($cents, (int) $inv->total_cents - (int) $inv->paid_cents), 'online', null, $reference);
        $accounts = $this->accounts();
        app(LedgerService::class)->post(JournalDraft::make('agent_payment', 'invoice-paid:'.$reference)->effectiveOn(CarbonImmutable::now())->source('invoice', $invoiceUlid)
            ->describe('Agent paid invoice online')->debit($accounts->account('1300'), Money::of($cents))
            ->credit($accounts->subAccount('1200', 'user', (int) $inv->user_id, 'Agent fees receivable'), Money::of($cents)));
    }

    /**
     * An agent who owes the brokerage (negative commission balance): the debt moves into instalment invoices.
     */
    public function plan(User $agent, int $totalCents, int $instalments, string $reason, User $by): string
    {
        $this->guard($by);
        $accounts = $this->accounts();
        $payable = app(LedgerService::class)->balance($accounts->subAccount('2000', 'user', $agent->id, 'Commission payable'))->cents;
        if ($payable >= 0 || $totalCents <= 0 || $totalCents > -$payable || $instalments < 1 || $instalments > 24 || mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['plan' => 'A payment plan covers what the agent owes (up to $'.number_format(max(0, -$payable) / 100, 2).'), in 1–24 monthly instalments, with a reason.']);
        }
        $db = DB::connection(Connections::cell());

        return $db->transaction(function () use ($db, $agent, $totalCents, $instalments, $reason, $by, $accounts): string {
            $ulid = (string) Str::ulid();
            $journal = app(LedgerService::class)->post(JournalDraft::make('agent_plan', 'plan:'.$ulid)->effectiveOn(CarbonImmutable::now())->source('plan', $ulid)
                ->describe('Amount owed moved to a payment plan')
                ->debit($accounts->subAccount('1200', 'user', $agent->id, 'Agent fees receivable'), Money::of($totalCents))
                ->credit($accounts->subAccount('2000', 'user', $agent->id, 'Commission payable'), Money::of($totalCents)))->id;
            $planId = $db->table('bill_plans')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $agent->id, 'total_cents' => $totalCents,
                'instalments' => $instalments, 'reason' => mb_substr(trim($reason), 0, 200), 'created_by' => $by->id, 'journal_id' => $journal, 'created_at' => now(), 'updated_at' => now()]);
            $start = CarbonImmutable::today();
            foreach (Money::of($totalCents)->allocate(array_fill(0, $instalments, '1')) as $i => $part) {
                $this->issue($agent->id, [['description' => 'Payment plan instalment '.($i + 1).' of '.$instalments, 'cents' => $part->cents, 'item_id' => null]],
                    $start, $planId, false, $start->addMonthsNoOverflow($i + 1));
            }
            app(AuditLogger::class)->record('billing.agent.plan', $agent, metadata: ['total_cents' => $totalCents, 'instalments' => $instalments, 'reason' => $reason], severity: 'warning', label: $agent->name);

            return $ulid;
        });
    }

    /**
     * Daily: invoices for fees that are due, reminders, and a one-time late fee after the grace period.
     *
     * @return array{issued: int, reminded: int, late: int}
     */
    public function daily(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $db = DB::connection(Connections::cell());
        $issued = 0;
        foreach ($db->table('bill_assignments')->whereNull('ended_on')->where('next_bill_on', '<=', $today->toDateString())->get() as $a) {
            $item = $db->table('bill_items')->where('id', $a->item_id)->first();
            if ($item === null || ! $item->active) {
                continue;
            }
            $on = CarbonImmutable::parse((string) $a->next_bill_on);
            $this->issue((int) $a->user_id, [['description' => $item->name.' — '.$on->format('F Y'), 'cents' => (int) $a->amount_cents, 'item_id' => (int) $item->id]], $on);
            $next = match ($item->frequency) {
                'monthly' => $on->addMonthNoOverflow(), 'yearly' => $on->addYear(), default => null
            };
            $db->table('bill_assignments')->where('id', $a->id)->update($next !== null ? ['next_bill_on' => $next->toDateString(), 'updated_at' => now()] : ['ended_on' => $on->toDateString(), 'updated_at' => now()]);
            $issued++;
        }

        $settings = $this->settings();
        $reminded = 0;
        $late = 0;
        foreach ($db->table('bill_invoices')->where('status', 'open')->get() as $inv) {
            $due = CarbonImmutable::parse((string) $inv->due_on);
            $dueSoon = $due->isAfter($today) && $due->lte($today->addDays(3)) && $inv->reminded_on === null;
            $overdue = $due->isBefore($today) && ($inv->reminded_on === null || (string) $inv->reminded_on < (string) $inv->due_on);
            if ($dueSoon || $overdue) {
                User::query()->find((int) $inv->user_id)?->notify(new InvoiceReminder((string) $inv->number, (int) $inv->total_cents - (int) $inv->paid_cents, $due, $overdue));
                $db->table('bill_invoices')->where('id', $inv->id)->update(['reminded_on' => $today->toDateString()]);
                $reminded++;
            }
            if ($settings['late_fee_cents'] > 0 && ! $inv->late_fee_applied && $inv->plan_id === null && $due->addDays($settings['grace_days'])->isBefore($today)) {
                $db->table('bill_invoice_lines')->insert(['tenant_id' => $inv->tenant_id, 'invoice_id' => $inv->id, 'description' => 'Late fee', 'amount_cents' => $settings['late_fee_cents']]);
                $db->table('bill_invoices')->where('id', $inv->id)->update(['total_cents' => (int) $inv->total_cents + $settings['late_fee_cents'], 'late_fee_applied' => true, 'updated_at' => now()]);
                $this->revenue((int) $inv->user_id, $settings['late_fee_cents'], 'late-fee:'.$inv->ulid, $today, 'Late fee');
                $late++;
            }
        }

        return compact('issued', 'reminded', 'late');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function invoicesFor(int $userId): array
    {
        $db = DB::connection(Connections::cell());

        return $db->table('bill_invoices')->where('user_id', $userId)->orderByDesc('issued_on')->orderByDesc('id')->limit(50)->get()
            ->map(fn ($i): array => ['ulid' => $i->ulid, 'number' => $i->number, 'issued_on' => $i->issued_on, 'due_on' => $i->due_on, 'status' => $i->status,
                'total_cents' => (int) $i->total_cents, 'paid_cents' => (int) $i->paid_cents, 'plan' => $i->plan_id !== null,
                'lines' => $db->table('bill_invoice_lines')->where('invoice_id', $i->id)->get(['description', 'amount_cents'])])->values()->all();
    }

    private function record(int $invoiceId, int $cents, string $method, ?int $runLineId, ?string $reference): void
    {
        if ($cents <= 0) {
            return;
        }
        $db = DB::connection(Connections::cell());
        $inv = $db->table('bill_invoices')->where('id', $invoiceId)->first();
        if ($inv === null) {
            return;
        }
        $db->table('bill_payments')->insert(['tenant_id' => $inv->tenant_id, 'invoice_id' => $invoiceId, 'amount_cents' => $cents, 'method' => $method,
            'run_line_id' => $runLineId, 'reference' => $reference, 'paid_at' => now()]);
        $paid = (int) $inv->paid_cents + $cents;
        $db->table('bill_invoices')->where('id', $invoiceId)->update(['paid_cents' => $paid, 'status' => $paid >= (int) $inv->total_cents ? 'paid' : 'open', 'updated_at' => now()]);
    }

    private function revenue(int $userId, int $cents, string $key, CarbonImmutable $on, string $description): int
    {
        $accounts = $this->accounts();

        return app(LedgerService::class)->post(JournalDraft::make('agent_invoice', $key)->effectiveOn($on)->source('agent_billing', $key)->describe($description)
            ->debit($accounts->subAccount('1200', 'user', $userId, 'Agent fees receivable'), Money::of($cents))
            ->credit($accounts->account('4100'), Money::of($cents)))->id;
    }

    private function accounts(): ChartOfAccounts
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();

        return $accounts;
    }

    private function guard(User $by): void
    {
        if (! $by->can('finance.billing.manage')) {
            throw ValidationException::withMessages(['billing' => 'Agent billing needs the "Bill agents" permission.']);
        }
    }
}
