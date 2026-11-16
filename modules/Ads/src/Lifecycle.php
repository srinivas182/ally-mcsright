<?php

declare(strict_types=1);

namespace Modules\Ads;

use App\Models\User;
use Modules\Ads\Models\Campaign;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Audit\AuditLogger;
use Throwable;

/**
 * After each results refresh (specs/ads/02): leads from the website, the automatic stop-loss, the end date, and
 * the Boost refund of unspent credits.
 *
 * Stop-loss (on by default, can be switched off per ad):
 * - no lead after half of the most it can spend; or
 * - a cost per lead above three times the brokerage's average (once the brokerage has at least three ads with
 *   leads). The ad is paused and the person is told why; they can run it again.
 */
final class Lifecycle
{
    public const AVERAGE_TIMES = 3;

    public function __construct(private readonly Returns $returns) {}

    /** @param  array{spend_cents: int, impressions: int, clicks: int, leads: int}  $results  the network's numbers */
    public function apply(Campaign $c, array $results, string $token): void
    {
        $leads = $this->returns->for($c)['leads'];
        $c->forceFill(['spend_cents' => $results['spend_cents'], 'impressions' => $results['impressions'], 'clicks' => $results['clicks'],
            'leads' => max($leads, $results['leads']), 'results_at' => now()])->save();

        if ($c->status === 'active' && $c->ends_on->endOfDay()->isPast()) {
            $this->end($c, 'Finished on its end date.');

            return;
        }
        if ($c->status === 'active' && $c->stop_loss && ($why = $this->stopLoss($c)) !== null) {
            try {
                Networks::for($c)->setStatus($c, $token, false);
            } catch (Throwable $e) {
                report($e);

                return;
            }
            $c->forceFill(['status' => 'paused', 'stopped_reason' => $why])->save();
            app(AuditLogger::class)->record('ads.stop_loss', $c, metadata: ['reason' => $why], severity: 'notice');
            User::query()->find($c->user_id)?->notify(new StopLossPaused($c->listing_label, $why));
        }
    }

    /** Ends an ad for good: no more spending; a Boost gives back what it didn't spend. */
    public function end(Campaign $c, string $why, ?string $token = null): void
    {
        if ($token !== null && in_array($c->status, ['active', 'paused'], true)) {
            Networks::for($c)->setStatus($c, $token, false);
        }
        $c->forceFill(['status' => 'ended', 'stopped_reason' => $c->stopped_reason ?? $why])->save();
        $this->refund($c);
    }

    /** Boost: credits reserved minus credits for what Meta actually spent (with the margin it was bought with), once. */
    public function refund(Campaign $c): int
    {
        if (! $c->isBoost() || $c->credits_reserved === 0 || $c->credits_refunded > 0) {
            return 0;
        }
        $used = app(Boost::class)->credits($c->spend_cents, $c->margin_bps);
        $back = app(CreditLedger::class)->refundPart('boost:'.$c->ulid, max(0, $c->credits_reserved - $used), 'Unspent Boost budget: '.$c->listing_label);
        if ($back > 0) {
            $c->forceFill(['credits_refunded' => $back])->save();
        }

        return $back;
    }

    private function stopLoss(Campaign $c): ?string
    {
        $max = max(1, $c->maxSpendCents());
        if ($c->leads === 0 && $c->spend_cents * 2 >= $max) {
            return 'Paused automatically: half the budget is spent and it hasn\'t brought a lead yet.';
        }
        if ($c->leads > 0) {
            $withLeads = Campaign::query()->where('leads', '>', 0)->where('id', '!=', $c->id)->get(['spend_cents', 'leads']);
            if ($withLeads->count() >= 3) {
                $average = $withLeads->sum('spend_cents') / max(1, $withLeads->sum('leads'));
                $mine = $c->spend_cents / $c->leads;
                if ($average > 0 && $mine > self::AVERAGE_TIMES * $average) {
                    return sprintf('Paused automatically: each lead is costing $%s, more than three times your brokerage\'s average ($%s).',
                        number_format($mine / 100, 2), number_format($average / 100, 2));
                }
            }
        }

        return null;
    }
}
