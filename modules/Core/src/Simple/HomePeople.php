<?php

declare(strict_types=1);

namespace Modules\Core\Simple;

use App\Models\User;

/**
 * SX4: the people to call today on the simple home ("Today's 10"), supplied by the CRM (Crm\Simple\TodaysTenHome).
 * Core only shows them, so the home works (with an empty list) when the CRM is not installed or not in the plan.
 */
interface HomePeople
{
    /**
     * @return list<array{ulid: string, name: string, reason: string, phone: string|null, call: bool, text: bool, text_wait: string|null}>
     */
    public function for(User $user): array;

    /** SX6: a link to today's list that works without signing in (morning email and text); null when there is none. */
    public function listLink(User $user): ?string;
}
