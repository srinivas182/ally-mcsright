<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use App\Models\User;
use Modules\Transactions\Models\Deal;

final class NoFormDrafter implements FormDrafter
{
    public function draft(Deal $deal, string $instruction, User $by): ?array
    {
        return null;
    }
}
