<?php

declare(strict_types=1);

namespace Modules\Core\Agents;

/**
 * An agent's public credentials for websites (specs/websites/09): the real estate licence (shown where state rules
 * require it) and MLS agent ids (to list their own listings). Implemented by the Roster module.
 */
interface AgentCredentials
{
    /**
     * @return array{state: string|null, number: string}|null the current licence
     */
    public function licence(int $userId): ?array;

    /**
     * @return list<string> current MLS agent ids
     */
    public function mlsIds(int $userId): array;
}
