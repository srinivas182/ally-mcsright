<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ads\ListingAds;
use Modules\Ads\Meta\MetaApi;
use Modules\Ads\Models\Campaign;
use Modules\Billing\Credits\CreditLedger;
use Throwable;

/** Publishes one listing ad to Meta (specs/ads/01). Not retried automatically: a failed publish shows why. */
final class PublishCampaign implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $campaignId)
    {
        $this->afterCommit();
    }

    public function handle(MetaApi $api, ListingAds $ads): void
    {
        $c = Campaign::query()->find($this->campaignId);
        if ($c === null || $c->status !== 'publishing') {
            return;
        }
        try {
            $api->publish($c, $ads->token($c));
            $c->forceFill(['status' => 'active', 'error' => null])->save();
        } catch (Throwable $e) {
            $c->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            if ($c->isBoost() && $c->credits_reserved > 0) {
                app(CreditLedger::class)->refund('boost:'.$c->ulid, 'Boost did not publish'); // every credit back
                $c->forceFill(['credits_refunded' => $c->credits_reserved])->save();
            }
        }
    }
}
