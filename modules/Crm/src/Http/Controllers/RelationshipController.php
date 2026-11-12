<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\LeadPond;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Memory;
use Modules\Crm\PersonalMemory;
use Modules\Crm\Referrals;

/**
 * Personal memory, referrals and the lead pond.
 */
final class RelationshipController
{
    public function __construct(private readonly ContactVisibility $visibility) {}

    public function addMemory(Request $request, Contact $contact, PersonalMemory $memory): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);
        $data = $request->validate(['category' => ['required', Rule::in(Memory::CATEGORIES)], 'detail' => ['required', 'string', 'max:200']]);
        $memory->add($contact, $data['category'], $data['detail'], $user);

        return back();
    }

    public function removeMemory(Request $request, int $memory): RedirectResponse
    {
        $model = Memory::query()->findOrFail($memory);
        $contact = Contact::query()->findOrFail($model->contact_id);
        abort_unless($this->visibility->canSee(self::user($request), $contact), 404);
        $model->delete();

        return back();
    }

    public function referredBy(Request $request, Contact $contact, Referrals $referrals): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);
        $data = $request->validate(['referrer' => ['required', 'string', 'size:26']]);
        $referrer = Contact::query()->where('ulid', $data['referrer'])->first();
        abort_if($referrer === null || ! $this->visibility->canSee($user, $referrer), 404);

        $referrals->record($contact, $referrer, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Referral recorded. A task to thank {$referrer->name} is on your list."]);

        return back();
    }

    public function pond(Request $request, LeadPond $pond): Response
    {
        $user = self::user($request);

        return Inertia::render('crm/contacts/pond', [
            'leads' => $pond->pond()->orderBy('in_pond_since')->limit(100)->get()->map(fn (Contact $c): array => [
                'ulid' => $c->ulid, 'name' => $c->name, 'source' => str_replace('_', ' ', $c->source->value), 'city' => $c->city,
                'added' => $c->created_at?->toIso8601String(), 'in_pond_since' => $c->in_pond_since?->toIso8601String(),
            ]),
            'settings' => $pond->settings(),
            'claimsLeft' => max(0, $pond->settings()['daily_claims'] - $pond->claimsToday($user)),
            'isManager' => CrmAccess::manager($user),
        ]);
    }

    public function claim(Request $request, Contact $contact, LeadPond $pond): RedirectResponse
    {
        $user = self::user($request);
        $pond->claim($contact, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$contact->name} is yours. Call them now!"]);

        return to_route('crm.contacts.show', $contact);
    }

    public function pondSettings(Request $request, LeadPond $pond): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'after_days' => ['required', 'integer', 'min:1', 'max:30'], 'daily_claims' => ['required', 'integer', 'min:1', 'max:50']]);
        $pond->configure((bool) $data['enabled'], (int) $data['after_days'], (int) $data['daily_claims'], self::user($request));

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
