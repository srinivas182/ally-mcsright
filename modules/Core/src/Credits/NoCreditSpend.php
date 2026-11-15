<?php

declare(strict_types=1);

namespace Modules\Core\Credits;

use Carbon\CarbonImmutable;

final class NoCreditSpend implements CreditSpend
{
    public function spent(string $actionKey, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['credits' => 0, 'microcents' => 0];
    }
}
