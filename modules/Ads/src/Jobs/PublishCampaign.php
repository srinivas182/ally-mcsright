<?php

declare(strict_types=1);

namespace Modules\Ads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ads\Meta\MetaApi;
use Modules\Ads\Models\Campaign;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\ConnectorKit;
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

    public function handle(MetaApi $api, ConnectorKit $kit): void
    {
        $c = Campaign::query()->find($this->campaignId);
        $conn = $c !== null ? Connection::query()->find($c->connection_id) : null;
        if ($c === null || $c->status !== 'publishing') {
            return;
        }
        try {
            if ($conn === null) {
                throw new \RuntimeException('Connect Meta Ads again, then try once more.');
            }
            $api->publish($c, $kit->accessToken($conn->data()));
            $c->forceFill(['status' => 'active', 'error' => null])->save();
        } catch (Throwable $e) {
            $c->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }
}
