<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Access\Can;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Transactions\Models\Deal;

/**
 * The one rule for which deals a person may see (specs/transactions/01): owners, staff and reviewers see every
 * deal; an agent sees their own; an assistant sees the deals of the agent they assist. Screens and the MCP server
 * (specs/connect/03) both use it.
 */
final class DealAccess
{
    public static function reviewer(User $user): bool
    {
        return Can::manage($user, 'transactions.review');
    }

    public static function actingFor(User $user): int
    {
        return $user->kind === UserKind::Assistant && $user->assisted_user_id !== null ? $user->assisted_user_id : $user->id;
    }

    /** @return Builder<Deal> */
    public static function query(User $user): Builder
    {
        $q = Deal::query();

        return self::reviewer($user) ? $q : $q->where('agent_user_id', self::actingFor($user));
    }

    public static function canSee(User $user, Deal $deal): bool
    {
        return self::reviewer($user) || $deal->agent_user_id === self::actingFor($user);
    }
}
