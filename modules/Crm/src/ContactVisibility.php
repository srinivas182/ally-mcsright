<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Access\Permissions;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Models\Contact;

/**
 * The one rule for which contacts a user may see (specs/crm/01). Every list, search, export, AI
 * lookup and API response goes through it. Interim until roles (Sprints 5-6):
 *
 * - agent: their own contacts (personal and company)
 * - assistant: the contacts of the agent they assist
 * - owner and staff: all company contacts, plus their own; personal-sphere contacts of other
 *   agents stay private unless the brokerage allows owners to see them
 * - HQ staff: none
 */
final class ContactVisibility
{
    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function apply(Builder $query, User $user): Builder
    {
        // Roles may widen access to COMPANY contacts by scope (team, office, everyone). An agent's
        // personal sphere is never widened (specs/crm/01 privacy rule; specs/core/03 scopes).
        $scope = $user->kind === UserKind::HqStaff ? null : app(Permissions::class)->scopeFor($user, 'crm.contacts.view');
        if (in_array($scope, ['team', 'office', 'tenant'], true)) {
            $people = app(Permissions::class)->peopleInScope($user, $scope);

            return $query->where(function (Builder $q) use ($user, $people): void {
                $q->where(fn (Builder $base) => $this->base($base, $user))
                    ->orWhere(fn (Builder $wide) => $wide->where('origin', Origin::Company)
                        ->when($people !== null, fn (Builder $w) => $w->whereIn('owner_user_id', $people)));
            });
        }

        return $this->base($query, $user);
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function base(Builder $query, User $user): Builder
    {
        return match ($user->kind) {
            UserKind::Agent => $query->where('owner_user_id', $user->id),
            UserKind::Assistant => $user->assisted_user_id !== null
                ? $query->where('owner_user_id', $user->assisted_user_id)
                : $query->whereRaw('1 = 0'),
            UserKind::Owner, UserKind::Staff => $query->where(function (Builder $q) use ($user): void {
                $q->where('origin', Origin::Company)->orWhere('owner_user_id', $user->id);
                if ($user->kind === UserKind::Owner && $this->personalVisibleToOwners($user)) {
                    $q->orWhere('origin', Origin::Personal);
                }
            }),
            UserKind::HqStaff => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @return Builder<Contact>
     */
    public function query(User $user): Builder
    {
        return $this->apply(Contact::query(), $user);
    }

    public function canSee(User $user, Contact $contact): bool
    {
        return $this->query($user)->whereKey($contact->id)->exists();
    }

    /** Owners and staff manage company contacts (assign owner, archive). */
    public function canManage(User $user, Contact $contact): bool
    {
        if ($user->isKind(UserKind::Owner, UserKind::Staff)) {
            return $contact->origin === Origin::Company || $contact->owner_user_id === $user->id;
        }
        if ($contact->origin === Origin::Company && app(Permissions::class)->allows($user, 'crm.contacts.manage', $contact)) {
            return true;
        }

        return $this->canSee($user, $contact);
    }

    private function personalVisibleToOwners(User $user): bool
    {
        return (bool) Tenant::query()->whereKey($user->tenant_id)->value('crm_personal_visible_to_owners');
    }
}
