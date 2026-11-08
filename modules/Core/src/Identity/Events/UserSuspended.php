<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user was suspended; their sessions end.
 */
final class UserSuspended
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $userId, public readonly ?int $byUserId) {}
}
