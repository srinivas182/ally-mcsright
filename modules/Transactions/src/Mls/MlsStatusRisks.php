<?php

declare(strict_types=1);

namespace Modules\Transactions\Mls;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Risk\RiskSource;
use Modules\Core\Support\Connections;

/** Overdue MLS status updates on the broker's risk register. */
final class MlsStatusRisks implements RiskSource
{
    public function risks(): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_mls_checks')->join('tx_deals', 'tx_deals.id', '=', 'tx_mls_checks.deal_id')
            ->whereNull('tx_mls_checks.resolved_at')->whereNotNull('tx_mls_checks.escalated_at')
            ->get(['tx_mls_checks.expected', 'tx_mls_checks.seen', 'tx_mls_checks.due_on', 'tx_deals.ulid', 'tx_deals.street', 'tx_deals.mls_number', 'tx_deals.agent_user_id'])
            ->map(fn ($r): array => ['level' => 'high', 'category' => 'MLS status', 'title' => "MLS shows {$r->seen}, should show {$r->expected} (MLS #{$r->mls_number})",
                'deal' => (string) $r->ulid, 'street' => (string) $r->street, 'owner' => (string) (User::query()->whereKey((int) $r->agent_user_id)->first()->name ?? ''),
                'due' => substr((string) $r->due_on, 0, 10), 'link' => '/deals/'.$r->ulid])->all());
    }
}
