<?php

declare(strict_types=1);

namespace Modules\Listings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Listings\Idx\AgreementCheck;

/** IDX11: checks a signed MLS agreement (rules, then AI) so HQ can send it on or send it back. */
#[CrossTenantAccess('HQ checks brokerages\' signed MLS agreements')]
final class CheckIdxAgreement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $subscription) {}

    public function handle(AgreementCheck $check): void
    {
        $check->run($this->subscription);
    }
}
