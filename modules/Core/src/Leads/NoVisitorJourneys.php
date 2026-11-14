<?php

declare(strict_types=1);

namespace Modules\Core\Leads;

/** Used when no website module is installed. */
final class NoVisitorJourneys implements VisitorJourneys
{
    public function summaries(array $contactIds): array
    {
        return [];
    }

    public function forContact(int $contactId): ?array
    {
        return null;
    }
}
