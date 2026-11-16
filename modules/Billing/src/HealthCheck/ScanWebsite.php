<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Runs a Health Check's website check in the background, so the broker sees the report at once. */
final class ScanWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $ulid) {}

    public function handle(HealthChecks $checks): void
    {
        $checks->scanWebsite($this->ulid);
    }
}
