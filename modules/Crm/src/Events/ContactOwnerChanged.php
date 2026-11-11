<?php

declare(strict_types=1);

namespace Modules\Crm\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ContactOwnerChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $contactId,
        public readonly ?int $fromUserId,
        public readonly int $toUserId,
        public readonly ?int $byUserId,
    ) {}
}
