<?php

declare(strict_types=1);

namespace Modules\Ledger\Close\Checks;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Ledger\Close\CloseCheck;
use Modules\Ledger\Close\Finding;
use Modules\Ledger\Models\LedgerPeriod;

/** No manual adjustment for the month is still waiting for its second person. */
final class WaitingAdjustments implements CloseCheck
{
    public function key(): string
    {
        return 'waiting_adjustments';
    }

    public function label(): string
    {
        return 'No adjustment is waiting for approval';
    }

    public function run(LedgerPeriod $period): array
    {
        return array_values(DB::connection(Connections::cell())->table('ledger_adjustment_requests')->where('tenant_id', $period->tenant_id)->where('status', 'waiting')
            ->whereBetween('effective_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->orderBy('id')->get()
            ->map(fn ($r): Finding => Finding::stop('An adjustment is waiting for approval', '"'.$r->description.'" needs a second person to approve or decline it.', 'r'.$r->ulid, '/finance/adjustments'))
            ->all());
    }
}
