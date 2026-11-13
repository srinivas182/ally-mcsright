<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Payouts\PayoutRuns;

/** Applies payment status changes from a rail's signed webhook: completed, or failed/returned (reversed). */
final class RailWebhooks
{
    public function __construct(private readonly Rails $rails, private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, string>  $headers  lower-case names
     */
    public function handle(string $railKey, string $payload, array $headers, ?Tenant $tenant = null): int
    {
        $config = null;
        if ($tenant !== null) {
            $config = $this->tenants->runAs($tenant, fn (): array => $this->rails->settings()['config']);
        }
        $changes = $this->rails->get($railKey)->webhook($payload, $headers, $config);
        $applied = 0;
        foreach ($changes as $change) {
            $tenantId = DB::connection(Connections::control())->table('pay_rail_refs')->where('ref', $change['ref'])->value('tenant_id');
            $owner = $tenantId !== null ? Tenant::query()->find((int) $tenantId) : null;
            if ($owner === null || ($tenant !== null && $owner->id !== $tenant->id)) {
                continue;
            }
            $applied += $this->tenants->runAs($owner, function () use ($change): int {
                $line = DB::connection(Connections::cell())->table('pay_run_lines')->where('rail_ref', $change['ref'])->first();
                if ($line === null) {
                    return 0;
                }
                if ($change['status'] === 'completed') {
                    DB::connection(Connections::cell())->table('pay_run_lines')->where('id', $line->id)->whereNull('reversal_journal_id')->update(['rail_status' => 'completed']);
                } else {
                    app(PayoutRuns::class)->reverseLine((int) $line->id, $change['status'], $change['reason']);
                }

                return 1;
            });
        }

        return $applied;
    }
}
