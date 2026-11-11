<?php

declare(strict_types=1);

namespace Modules\Crm\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ActivityLogged
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $contactId, public readonly int $activityId, public readonly string $type) {}
}
