<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Core\Tenancy\TenantContext;

/**
 * Records which tenant was current while the job ran.
 */
final class RecordTenantJob implements ShouldQueue
{
    use Queueable;

    /** @var list<int|null> */
    public static array $seen = [];

    public function handle(TenantContext $context): void
    {
        self::$seen[] = $context->idOrNull();
    }
}
