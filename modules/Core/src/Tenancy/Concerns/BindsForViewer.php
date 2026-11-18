<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Identity\Enums\UserKind;

/**
 * SX1 (agent privacy): a record named in a signed-in person's web address is found only if that person may see it
 * (otherwise "not found"), whatever the page or action does next. The model says who may see what (viewable()).
 * Before, each controller had to remember to check; one forgot (LN4's "use caller name"). Requests without a
 * signed-in person (signed links, webhooks) are left to their own checks; HQ has no brokerage records.
 */
trait BindsForViewer
{
    /**
     * Limits a query to the records this person may see.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    abstract public static function viewable(Builder $query, User $user): Builder;

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $user = Auth::user();

        return $query instanceof Builder && $user instanceof User && $user->kind !== UserKind::HqStaff ? static::viewable($query, $user) : $query;
    }
}
