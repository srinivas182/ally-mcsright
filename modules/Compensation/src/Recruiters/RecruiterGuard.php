<?php

declare(strict_types=1);

namespace Modules\Compensation\Recruiters;

use Modules\Transactions\DealGuard;
use Modules\Transactions\Models\Deal;

/** CM4b: recruiters have no licence, so they can never be the agent on a deal that goes under contract. */
final class RecruiterGuard implements DealGuard
{
    public function check(Deal $deal): ?string
    {
        return app(Recruiters::class)->isRecruiter($deal->agent_user_id)
            ? 'Recruiters cannot be the agent on a deal. Put a licensed agent on it first.'
            : null;
    }
}
