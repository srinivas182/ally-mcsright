<?php

declare(strict_types=1);

namespace Modules\Transactions\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\Exceptions\NoPlanForAgent;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Transactions\Deals;
use Modules\Transactions\Models\Deal;

/**
 * Close there, paid here (specs/connect/12, Sprint C5a-3). A deal brought over from dotloop, SkySlope or Lone Wolf
 * that closes there on or after the connection's "pay from" date is paid in RightAlly automatically: the agent's own
 * plan (split, fees, cap) and revenue share, exactly as for a deal closed here. No approval queue.
 *
 * - The other app owns the deal: when its price, GCI, closing date, agent or type changes after payment, the
 *   payment is reversed and made again; when the deal is reopened or cancelled there, the payment is reversed.
 * - The broker can override a deal (agent split, waive fees, keep off the cap, no revenue share) or reverse it, in
 *   RightAlly only. A reversed deal is not paid again until it changes in the other app, or the broker says so.
 * - Nothing is guessed: a closed deal without a GCI, or one the plan can't pay (no plan for the agent), waits on a
 *   short list for the broker, with the reason. A sync never fails because of money.
 */
final class SyncedPay
{
    public function __construct(private readonly CommissionService $commissions, private readonly Deals $deals) {}

    /** Called by the deal import after each deal is brought over or updated. */
    public function afterSync(Deal $deal, ?string $payFrom, string $app): void
    {
        $final = $this->finalCalculation($deal);
        if ($deal->status !== 'closed') {
            if ($final !== null) {
                $this->reverse($deal, $final, 'Reopened or cancelled in '.$app, null);
            } elseif ($deal->status === 'under_contract' && $deal->gci_cents > 0 && $deal->closing_date !== null) {
                $this->expect($deal); // a pending calculation, so forecasts and cap previews include it
            }

            return;
        }
        $closed = $deal->closed_on?->toDateString() ?? $deal->closing_date?->toDateString();
        if ($payFrom === null || $closed === null || $closed < $payFrom) {
            return; // history: paid in the old system before the switch
        }
        if ($deal->money_status === 'reversed' && $deal->paid_fingerprint === self::fingerprint($deal)) {
            return; // the broker reversed it; paid again only when the deal changes there or the broker says so
        }
        $this->pay($deal, 'Closed in '.$app, null);
    }

    /**
     * Pays (or pays again) a closed deal: reverses the current payment if the deal changed, then calculates and
     * finalises on the agent's plan with the broker's overrides.
     */
    public function pay(Deal $deal, string $why, ?User $by): void
    {
        $fingerprint = self::fingerprint($deal);
        $final = $this->finalCalculation($deal);
        if ($final !== null && $deal->money_status === 'paid' && $deal->paid_fingerprint === $fingerprint) {
            return; // nothing changed
        }
        if ($deal->gci_cents <= 0) {
            $this->wait($deal, 'needs_gci', 'Closed without a commission amount (GCI). Enter it once and the deal is paid.');

            return;
        }
        if ($final !== null) {
            $this->commissions->reverse($final, 'Changed: '.$why, $by?->id);
        }
        $closing = CarbonImmutable::parse($deal->closed_on?->toDateString() ?? $deal->closing_date?->toDateString() ?? CarbonImmutable::today()->toDateString());
        try {
            $pending = $this->commissions->calculate($this->deals->input($deal, $deal->gci_cents, $deal->price_cents, $closing), $deal->ulid, $by?->id, $why);
            $paid = $this->commissions->finalise($pending, $by?->id);
        } catch (ValidationException|InvalidArgumentException|NoPlanForAgent|InvalidRules $e) {
            $message = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->wait($deal, 'needs_review', mb_substr('Couldn\'t pay this deal: '.$message, 0, 300));

            return;
        }
        // The agent's own commission: paid outside RightAlly (the default for deals from another app), or through
        // payouts once the money received is recorded. Revenue share and bonuses always go to the wallet.
        $outside = $deal->imported_from !== null && DB::connection(Connections::cell())->table('tx_settings')->value('synced_commission') !== 'payouts';
        $deal->forceFill(['calculation_id' => $paid->id, 'money_status' => 'paid', 'money_note' => mb_substr($why, 0, 300), 'paid_fingerprint' => $fingerprint,
            'agent_paid_outside' => $outside])->save();
        app(AuditLogger::class)->record('transactions.deal.paid', $deal, metadata: ['why' => $why, 'gci_cents' => $deal->gci_cents, 'overrides' => $deal->pay_overrides],
            severity: 'notice', label: $deal->street);
    }

    /** A pending (not yet paid) calculation for a deal under contract there, kept up to date as it changes. */
    private function expect(Deal $deal): void
    {
        try {
            $pending = $this->commissions->calculate($this->deals->input($deal, $deal->gci_cents, $deal->price_cents, CarbonImmutable::parse($deal->closing_date?->toDateString())), $deal->ulid);
        } catch (ValidationException|InvalidArgumentException|NoPlanForAgent|InvalidRules) {
            return; // no plan for the agent yet: it is paid (or listed) when it closes
        }
        if ($deal->calculation_id !== $pending->id) {
            $deal->forceFill(['calculation_id' => $pending->id])->save();
        }
    }

    /** Undoes the payment in RightAlly only (split, fees, cap and revenue share). */
    public function reverse(Deal $deal, Calculation $final, string $reason, ?User $by): void
    {
        $this->commissions->reverse($final, $reason, $by?->id);
        $deal->forceFill(['money_status' => 'reversed', 'money_note' => mb_substr($reason, 0, 300), 'paid_fingerprint' => self::fingerprint($deal)])->save();
        app(AuditLogger::class)->record('transactions.deal.pay_reversed', $deal, metadata: ['reason' => $reason], severity: 'warning', label: $deal->street);
    }

    public function finalCalculation(Deal $deal): ?Calculation
    {
        return Calculation::query()->where('deal_ref', $deal->ulid)->where('status', 'final')->orderByDesc('version')->first();
    }

    /** What a payment depends on: when any of it changes, the payment is made again. */
    public static function fingerprint(Deal $deal): string
    {
        $overrides = $deal->pay_overrides ?? [];
        ksort($overrides); // MySQL stores JSON objects with its own key order: the same overrides must give the same print

        return hash('sha256', (string) json_encode([$deal->gci_cents, $deal->price_cents, $deal->closed_on?->toDateString() ?? $deal->closing_date?->toDateString(),
            $deal->agent_user_id, $deal->type, $overrides]));
    }

    private function wait(Deal $deal, string $status, string $note): void
    {
        if ($deal->money_status !== $status || $deal->money_note !== $note) {
            $deal->forceFill(['money_status' => $status, 'money_note' => $note])->save();
        }
    }
}
