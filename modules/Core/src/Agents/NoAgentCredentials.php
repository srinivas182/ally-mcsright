<?php

declare(strict_types=1);

namespace Modules\Core\Agents;

/** Used when no roster module is installed. */
final class NoAgentCredentials implements AgentCredentials
{
    public function licence(int $userId): ?array
    {
        return null;
    }

    public function mlsIds(int $userId): array
    {
        return [];
    }
}
