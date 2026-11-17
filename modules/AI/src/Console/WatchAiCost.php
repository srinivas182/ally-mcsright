<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\AI\AiCost;
use Modules\AI\AiCostSpike;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;

/** Daily (AQ2, specs/ai/08): tells HQ when a brokerage's AI cost jumps to more than three times its usual day. */
#[CrossTenantAccess('Reads AI cost across brokerages and emails RightAlly HQ staff')]
final class WatchAiCost extends Command
{
    protected $signature = 'ai:cost-watch';

    protected $description = 'Email HQ when a brokerage\'s AI cost jumps';

    public function handle(AiCost $cost): int
    {
        $spikes = $cost->spikes($this);
        if ($spikes === []) {
            $this->info('No AI cost jumps yesterday.');

            return self::SUCCESS;
        }
        $hq = CrossTenant::query(User::class, $this)->where('kind', UserKind::HqStaff)->where('status', 'active')->get();
        if ($hq->isNotEmpty()) {
            Notification::send($hq, new AiCostSpike($spikes));
        }
        $this->warn(count($spikes).' brokerage(s) with an AI cost jump.');

        return self::SUCCESS;
    }
}
