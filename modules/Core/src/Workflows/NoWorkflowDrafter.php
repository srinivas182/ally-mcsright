<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use App\Models\User;

final class NoWorkflowDrafter implements WorkflowDrafter
{
    public function draft(string $text, array $catalogue, User $user): ?array
    {
        return null;
    }
}
