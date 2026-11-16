<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use App\Models\User;

/**
 * Turns a plain-English description into a draft automation for the person to check (specs/core/06, W1). The AI
 * module provides it; without it the builder simply starts empty.
 */
interface WorkflowDrafter
{
    /**
     * @param  array{triggers: array<string, mixed>, actions: array<string, mixed>}  $catalogue
     * @return array{name: string, trigger: string, conditions: list<array{fact: string, op: string, value?: mixed}>, steps: list<array{type: string, params?: array<string, mixed>}>}|null
     */
    public function draft(string $text, array $catalogue, User $user): ?array;
}
