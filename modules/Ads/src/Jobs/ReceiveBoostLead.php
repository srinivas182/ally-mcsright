<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Meta\BoostLeads;
use Modules\Ads\Models\Campaign;

/** Takes one instant-form lead from a RightAlly Boost ad into its brokerage's CRM (specs/ads/04). */
final class ReceiveBoostLead implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $campaignId, public readonly string $leadgenId) {}

    public function handle(BoostLeads $leads): void
    {
        $c = Campaign::query()->find($this->campaignId);
        if ($c === null) {
            return;
        }
        try {
            $leads->receive($c, $this->leadgenId);
        } catch (ValidationException) {
            // a test lead, or one without a name, email or phone: nothing to take in
        }
    }
}
