<?php

declare(strict_types=1);

namespace Modules\Crm\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ContactStageChanged
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $contactId, public readonly int $stageId, public readonly ?int $byUserId) {}
}
