<?php

declare(strict_types=1);

namespace Modules\Core\Approvals;

use App\Models\User;
use Closure;

/** The actions modules registered as possibly needing approval (FL1); kept for the life of the app. */
final class ApprovalActions
{
    /** @var array<string, array{label: string, default: int, run: Closure(array<string, mixed>, User): string}> */
    public array $actions = [];
}
