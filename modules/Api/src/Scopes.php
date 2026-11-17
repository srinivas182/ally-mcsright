<?php

declare(strict_types=1);

namespace Modules\Api;

/**
 * What an API key or partner app may do (API1). Never offered: deleting anything, messaging many people at once,
 * money movements, bank details or commission splits.
 */
final class Scopes
{
    public const ALL = [
        'contacts:read' => 'See the contacts you can see',
        'contacts:write' => 'Add and change contacts, notes and tasks, as you',
        'deals:read' => 'See the deals you work on (no commission splits or bank details)',
        'tasks:read' => 'See your tasks',
        'webhooks:manage' => 'Send events to another app (owners and staff)',
    ];

    /** Scope names as OAuth sees them ("api:contacts:read"), so they never clash with the AI assistant scopes. */
    public static function oauth(string $scope): string
    {
        return 'api:'.$scope;
    }
}
