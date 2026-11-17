<?php

declare(strict_types=1);

namespace Modules\Core\Learning;

use App\Models\User;

/**
 * "Finish this course before you use this tool" (LN2, specs/learn/02). A module asks before a risky action, such
 * as starting an automatic follow-up plan or calling prospects. Returns why the person may not, or null when they
 * may. Without the Learn module (or with no certification set), every tool is open.
 */
interface TrainingGate
{
    public function missing(User $user, string $tool): ?string;
}
