<?php

declare(strict_types=1);

namespace Modules\Core\Learning;

use App\Models\User;

/** No training required for any tool (the default). */
final class NoTrainingGate implements TrainingGate
{
    public function missing(User $user, string $tool): ?string
    {
        return null;
    }
}
