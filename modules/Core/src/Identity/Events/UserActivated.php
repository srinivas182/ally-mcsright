<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user accepted an invitation and is now active.
 */
final class UserActivated
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $userId, public readonly ?int $byUserId) {}
}
