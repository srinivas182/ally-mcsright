<?php

declare(strict_types=1);

namespace Modules\Ads;

use Illuminate\Support\Facades\DB;
use Modules\Ads\Models\Campaign;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Lead;

/**
 * What an ad brought in (specs/ads/02): leads that arrived through its link (the website keeps the ad's
 * utm_campaign), and the commission on deals with those people: closed (earned) and under contract (pending).
 */
final class Returns
{
    /** @return array{leads: int, pending_cents: int, earned_cents: int} */
    public function for(Campaign $c): array
    {
        $tag = 'rightally-'.strtolower($c->ulid);
        $contacts = Lead::query()->where('created_at', '>=', $c->created_at)->where('utm->utm_campaign', $tag)->pluck('contact_id')->filter()->unique()->values()->all();
        if ($contacts === []) {
            return ['leads' => 0, 'pending_cents' => 0, 'earned_cents' => 0];
        }
        $deals = DB::connection(Connections::cell())->table('tx_deals')->where('tenant_id', $c->tenant_id)->whereIn('contact_id', $contacts)
            ->where('created_at', '>=', $c->created_at)->selectRaw('status, SUM(gci_cents) AS gci')->groupBy('status')->pluck('gci', 'status');

        return ['leads' => count($contacts), 'pending_cents' => (int) ($deals['under_contract'] ?? 0), 'earned_cents' => (int) ($deals['closed'] ?? 0)];
    }
}
