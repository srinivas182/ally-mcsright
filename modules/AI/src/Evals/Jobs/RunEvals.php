<?php

declare(strict_types=1);

namespace Modules\AI\Evals\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\AI\Evals\EvalRunner;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;

/** Runs the AI test sets live, started from HQ (AQ1). */
final class RunEvals implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /** @param  list<string>|null  $only */
    public function __construct(public readonly int $budgetCents, public readonly ?array $only = null, public readonly ?int $byUserId = null) {}

    public function handle(EvalRunner $runner): void
    {
        $hq = Tenant::query()->where('type', TenantType::Hq)->first();
        if ($hq !== null) {
            $runner->live($hq, $this->budgetCents * 1_000_000, $this->only, $this->byUserId);
        }
    }
}
