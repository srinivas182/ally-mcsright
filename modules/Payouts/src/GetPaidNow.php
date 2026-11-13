<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;

/**
 * Get paid now (specs/finance/02, addition 6): an agent withdraws what is ready at any time and sees the
 * fee before confirming. The money is committed at once (payables down, the fee booked as the brokerage's
 * income); automatic rails pay same-day immediately, bank-file brokerages send it in their next file.
 */
final class GetPaidNow
{
    public function __construct(private readonly BankAccounts $banks, private readonly PayoutRuns $runs, private readonly TenantContext $tenants) {}

    /**
     * @return array{max_flat_cents: int, max_percent: string}
     */
    public function limits(): array
    {
        $row = DB::connection(Connections::control())->table('pay_instant_limits')->first();

        return ['max_flat_cents' => (int) ($row->max_flat_cents ?? 2_500), 'max_percent' => (string) ($row->max_percent ?? '2.00')];
    }

    public function saveLimits(int $maxFlatCents, string $maxPercent, User $by): void
    {
        if ($maxFlatCents < 0 || ! is_numeric($maxPercent) || BigDecimal::of($maxPercent)->isNegative() || BigDecimal::of($maxPercent)->isGreaterThan(10)) {
            throw ValidationException::withMessages(['limits' => 'Use a flat maximum in dollars and a percentage up to 10%.']);
        }
        $db = DB::connection(Connections::control());
        $db->table('pay_instant_limits')->exists()
            ? $db->table('pay_instant_limits')->update(['max_flat_cents' => $maxFlatCents, 'max_percent' => $maxPercent, 'updated_at' => now()])
            : $db->table('pay_instant_limits')->insert(['max_flat_cents' => $maxFlatCents, 'max_percent' => $maxPercent, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('payouts.instant.limits', metadata: ['max_flat_cents' => $maxFlatCents, 'max_percent' => $maxPercent], severity: 'warning');
    }

    public function configure(bool $enabled, int $flatCents, string $percent, int $minCents, User $by): void
    {
        if (! $by->can('finance.bank_details.manage')) {
            throw ValidationException::withMessages(['instant' => 'Changing get paid now needs the bank-details permission.']);
        }
        $limits = $this->limits();
        if ($flatCents < 0 || $flatCents > $limits['max_flat_cents'] || ! is_numeric($percent) || BigDecimal::of($percent)->isNegative()
            || BigDecimal::of($percent)->isGreaterThan($limits['max_percent']) || $minCents < 100) {
            throw ValidationException::withMessages(['instant' => 'The fee can be up to $'.number_format($limits['max_flat_cents'] / 100, 2).' plus up to '.$limits['max_percent'].'%, and the minimum at least $1.']);
        }
        DB::connection(Connections::cell())->table('pay_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['instant_enabled' => $enabled,
            'instant_fee_flat_cents' => $flatCents, 'instant_fee_percent' => $percent, 'instant_min_cents' => $minCents, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('payouts.instant.configured', metadata: ['enabled' => $enabled, 'flat_cents' => $flatCents, 'percent' => $percent, 'min_cents' => $minCents], severity: 'warning');
    }

    /**
     * What the agent would get right now, and why not if they can't.
     *
     * @return array{enabled: bool, available_cents: int, deduction_cents: int, fee_cents: int, net_cents: int, commission_cents: int, revenue_share_cents: int, blocked: string|null, automatic: bool}
     */
    public function quote(User $agent): array
    {
        $settings = $this->runs->settings();
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();
        $ledger = app(LedgerService::class);
        $commission = max(0, $ledger->balance($accounts->subAccount('2000', 'user', $agent->id, 'Commission payable'))->cents);
        $share = max(0, $ledger->balance($accounts->subAccount('2100', 'user', $agent->id, 'Revenue share'))->cents);
        $available = $commission + $share;
        // Unpaid invoices come off first (when the brokerage deducts them); the fee applies to what is paid out.
        $billing = app(AgentBilling::class);
        $deduction = $billing->settings()['deduct_from_commission'] ? min($available, $billing->owed($agent->id)) : 0;
        $payable = $available - $deduction;
        $fee = $payable === 0 ? 0 : min($payable, $settings['instant_fee_flat_cents']
            + BigDecimal::of($payable)->multipliedBy($settings['instant_fee_percent'])->dividedBy(100, 0, RoundingMode::HalfUp)->toInt());
        $bank = $this->banks->current($agent);
        $open = DB::connection(Connections::cell())->table('pay_runs')->where('kind', 'instant')->where('requested_by', $agent->id)->where('status', 'approved')->exists();
        $rail = app(Rails\Rails::class)->settings();

        $blocked = match (true) {
            ! $settings['instant_enabled'] => 'Your brokerage has not turned on get paid now.',
            $agent->status !== UserStatus::Active => 'Your account is not active.',
            $bank === null => 'Add your bank account first.',
            ! $bank['usable'] => 'Your bank account changed recently; for your protection it can be paid from '.CarbonImmutable::parse($bank['usable_from'])->toDayDateTimeString().'.',
            $open => 'You already have a payment on its way.',
            $payable < $settings['instant_min_cents'] => 'Get paid now starts at $'.number_format($settings['instant_min_cents'] / 100, 2).'.',
            default => null,
        };

        return ['enabled' => $settings['instant_enabled'], 'available_cents' => $available, 'deduction_cents' => $deduction, 'fee_cents' => $fee, 'net_cents' => $payable - $fee,
            'commission_cents' => $commission, 'revenue_share_cents' => $share, 'blocked' => $blocked,
            'automatic' => $rail['rail'] !== 'bank_file' && $rail['status'] === 'ready'];
    }

    /** The agent confirms: the quoted amount is committed and sent (or queued for the bank file). */
    public function request(User $agent, int $expectedNetCents): string
    {
        if (! $agent->isKind(UserKind::Agent, UserKind::Owner)) {
            throw ValidationException::withMessages(['instant' => 'Get paid now is for agents.']);
        }
        $quote = $this->quote($agent);
        if ($quote['blocked'] !== null) {
            throw ValidationException::withMessages(['instant' => $quote['blocked']]);
        }
        if ($quote['net_cents'] !== $expectedNetCents) {
            throw ValidationException::withMessages(['instant' => 'The amount changed since you looked. Please check it again.']);
        }
        $db = DB::connection(Connections::cell());
        $ulid = (string) Str::ulid();
        $today = CarbonImmutable::today();
        $rail = $quote['automatic'] ? app(Rails\Rails::class)->settings()['rail'] : 'bank_file';

        $db->transaction(function () use ($db, $ulid, $agent, $quote, $today, $rail): void {
            $runId = $db->table('pay_runs')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'kind' => 'instant', 'status' => 'approved',
                'pay_on' => $today->toDateString(), 'same_day' => true, 'rail' => $rail, 'total_cents' => $quote['available_cents'], 'created_by' => $agent->id,
                'requested_by' => $agent->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $db->table('pay_run_lines')->insert(['tenant_id' => $this->tenants->id(), 'run_id' => $runId, 'payee_type' => 'agent', 'user_id' => $agent->id, 'name' => $agent->name,
                'commission_cents' => $quote['commission_cents'], 'revenue_share_cents' => $quote['revenue_share_cents'], 'amount_cents' => $quote['available_cents'],
                'fee_cents' => $quote['fee_cents'], 'deduction_cents' => $quote['deduction_cents'], 'method' => 'ach', 'status' => 'included']);
            $journal = $this->runs->post($ulid, $db->table('pay_run_lines')->where('run_id', $runId)->get(), $today);
            $db->table('pay_runs')->where('id', $runId)->update(['journal_id' => $journal]);
        });
        app(AuditLogger::class)->record('payouts.instant.requested', $agent, metadata: ['net_cents' => $quote['net_cents'], 'fee_cents' => $quote['fee_cents'], 'rail' => $rail],
            severity: 'notice', label: $agent->name);

        if ($quote['automatic']) {
            $this->runs->send($ulid, $agent, system: true);
        } else {
            $finance = User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get();
            Notification::send($finance, new InstantRequested($agent->name, $quote['net_cents']));
        }

        return $ulid;
    }
}
