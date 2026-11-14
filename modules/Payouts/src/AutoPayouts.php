<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * Automatic payouts on closing (specs/finance/02): when a deal's money is matched to the CDA (or a
 * difference is accepted), a run is prepared for that deal's agents and brokerage-paid referral payees.
 * Within the owner's limit it is approved under the owner's standing rule and, on an automatic rail, sent at
 * once; on the bank file, finance is asked to send it; over the limit, it waits for approval.
 */
final class AutoPayouts
{
    public function __construct(private readonly PayoutRuns $runs, private readonly TenantContext $tenants) {}

    /**
     * @return array{enabled: bool, limit_cents: int, same_day: bool}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('pay_settings')->first();

        return ['enabled' => (bool) ($row->auto_pay_enabled ?? false), 'limit_cents' => (int) ($row->auto_pay_limit_cents ?? 1_500_000), 'same_day' => (bool) ($row->auto_pay_same_day ?? false)];
    }

    public function configure(bool $enabled, int $limitCents, bool $sameDay, User $by): void
    {
        if (! $by->isKind(UserKind::Owner)) {
            throw ValidationException::withMessages(['auto' => 'Only an owner can set the automatic payout rule.']);
        }
        if ($limitCents < 0) {
            throw ValidationException::withMessages(['auto' => 'The limit cannot be negative.']);
        }
        DB::connection(Connections::cell())->table('pay_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['auto_pay_enabled' => $enabled,
            'auto_pay_limit_cents' => $limitCents, 'auto_pay_same_day' => $sameDay, 'auto_pay_set_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('payouts.auto.configured', metadata: ['enabled' => $enabled, 'limit_cents' => $limitCents, 'same_day' => $sameDay], severity: 'critical');
    }

    /** Called once a deal's money is settled; returns the run prepared, if any. */
    public function afterSettled(Deal $deal, User $by): ?string
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return null;
        }
        $db = DB::connection(Connections::cell());
        // Deal-IQ holds payouts while a money or wire-fraud blocker is open; the payout resumes when it clears.
        $hold = $db->table('tx_deals')->where('id', $deal->id)->value('payout_hold_reason');
        if ($hold !== null) {
            $db->table('tx_deals')->where('id', $deal->id)->update(['payout_waiting_by' => $by->id]);
            Notification::send(User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get(), new PayoutHeld((string) $deal->street, (string) $deal->ulid, (string) $hold));
            app(AuditLogger::class)->record('payouts.auto.held', $deal, metadata: ['reason' => $hold], severity: 'warning', label: $deal->street);

            return null;
        }
        $payables = (array) json_decode((string) $db->table('pay_recognitions')->where('deal_id', $deal->id)->value('payables'), true);
        $userIds = array_map('intval', array_keys(array_filter($payables, fn ($c) => (int) $c > 0)));
        $referralIds = $db->table('tx_referrals')->where('deal_id', $deal->id)->where('direction', 'out')->where('paid_by', 'brokerage')->whereNull('paid_at')
            ->pluck('id')->all();
        $referralIds = array_values(array_map('intval', $referralIds));
        if ($userIds === [] && $referralIds === []) {
            return null;
        }

        $ulid = $this->runs->build(CarbonImmutable::today(), $settings['same_day'], $by, $userIds, $referralIds, 'auto', system: true);
        $runId = (int) $db->table('pay_runs')->where('ulid', $ulid)->value('id');
        $lines = $db->table('pay_run_lines')->where('run_id', $runId)->where('status', 'included')->get();
        if ($lines->isEmpty()) {
            $db->table('pay_runs')->where('id', $runId)->update(['status' => 'cancelled', 'updated_at' => now()]);

            return null;
        }
        $owners = User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get();
        $over = $lines->first(fn ($l): bool => PayoutRuns::net($l) > $settings['limit_cents']);
        if ($over !== null) {
            Notification::send($owners, new AutoPayoutWaiting($deal->street, (string) $over->name, PayoutRuns::net($over), $settings['limit_cents'], false));
            app(AuditLogger::class)->record('payouts.auto.needs_approval', $deal, metadata: ['run' => $ulid, 'payee' => $over->name, 'cents' => PayoutRuns::net($over)], severity: 'notice', label: $deal->street);

            return $ulid;
        }
        $this->runs->approveAutomatically($ulid, 'Automatic payouts up to $'.number_format($settings['limit_cents'] / 100, 2).' per payee, after the deal\'s money matched');
        if ($db->table('pay_runs')->where('id', $runId)->value('rail') !== 'bank_file') {
            $this->runs->send($ulid, $by, system: true);
        } else {
            Notification::send($owners, new AutoPayoutWaiting($deal->street, null, (int) $lines->sum(fn ($l): int => PayoutRuns::net($l)), $settings['limit_cents'], true));
        }

        return $ulid;
    }
}
