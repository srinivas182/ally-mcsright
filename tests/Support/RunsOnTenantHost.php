<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * For PHPUnit-style test classes: runs every test on a brokerage's own address
 * (Laravel calls setUp{TraitName} automatically).
 */
trait RunsOnTenantHost
{
    protected function setUpRunsOnTenantHost(): void
    {
        $this->onTenantHost();
    }
}
