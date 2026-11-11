<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\AI\AiGateway;
use Modules\AI\Models\AiBatch;
use Modules\AI\Models\AiBatchItem;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Every 10 minutes: collect finished provider batches (each inside its own tenant) and delete
 * stored answers past their purge date.
 */
#[CrossTenantAccess('Collect AI batches and purge expired results for every tenant')]
final class CollectBatches extends Command
{
    protected $signature = 'ai:batches:collect';

    protected $description = 'Collect finished AI batches and purge expired results';

    public function handle(AiGateway $gateway, TenantContext $tenants): int
    {
        $collected = 0;
        foreach (CrossTenant::query(AiBatch::class, $this)->where('status', 'submitted')->get() as $batch) {
            $tenant = Tenant::query()->find($batch->tenant_id);
            if ($tenant === null) {
                continue;
            }
            try {
                $collected += $tenants->runAs($tenant, fn (): bool => $gateway->collectBatch($batch->fresh() ?? $batch)) ? 1 : 0;
            } catch (Throwable $e) {
                $this->warn("Batch {$batch->ulid}: ".class_basename($e));
            }
        }

        $purged = CrossTenant::query(AiBatchItem::class, $this)
            ->where('purge_after', '<', CarbonImmutable::now())
            ->whereNotNull('result')
            ->update(['result' => null, 'permitted' => null]);

        $this->info("Collected {$collected} batch(es); purged {$purged} stored result(s).");

        return self::SUCCESS;
    }
}
