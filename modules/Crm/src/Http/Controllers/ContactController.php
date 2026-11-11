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
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Relation;
use Modules\Crm\Enums\Source;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Relationship;

/**
 * Contacts list, quick add and contact detail. Everything goes through ContactVisibility; a
 * contact the user may not see is "not found", so its existence is never revealed.
 */
final class ContactController
{
    public function __construct(private readonly ContactVisibility $visibility) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $search = trim((string) $request->query('search', ''));
        $origin = (string) $request->query('origin', '');
        $lifecycle = (string) $request->query('lifecycle', '');
        $digits = (string) preg_replace('/\D/', '', $search);

        $contacts = $this->visibility->query($user)
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search, $digits): void {
                $q->where('search_name', 'like', '%'.mb_strtolower($search).'%')
                    ->orWhere('primary_email', 'like', '%'.mb_strtolower($search).'%');
                if (strlen($digits) >= 3) {
                    $q->orWhere('primary_phone', 'like', '%'.$digits.'%');
                }
            }))
            ->when(Origin::tryFrom($origin) !== null, fn ($q) => $q->where('origin', $origin))
            ->when(Lifecycle::tryFrom($lifecycle) !== null, fn ($q) => $q->where('lifecycle', $lifecycle), fn ($q) => $q->where('lifecycle', '!=', Lifecycle::Archived))
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Contact $c): array => self::row($c));

        return Inertia::render('crm/contacts/index', [
            'contacts' => $contacts,
            'filters' => ['search' => $search, 'origin' => $origin, 'lifecycle' => $lifecycle],
            'lifecycles' => array_map(fn (Lifecycle $l): string => $l->value, Lifecycle::cases()),
            'sources' => array_map(fn (Source $s): string => $s->value, Source::cases()),
            'isManager' => $user->isKind(UserKind::Owner, UserKind::Staff),
        ]);
    }

    public function store(Request $request, ManageContacts $contacts): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30'],
            'source' => ['nullable', Rule::enum(Source::class)],
            'origin' => ['nullable', Rule::enum(Origin::class)],
        ]);

        $contact = $contacts->create($data, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$contact->name} added."]);

        return to_route('crm.contacts.show', $contact);
    }

    public function show(Request $request, Contact $contact): Response
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);

        $visibleIds = $this->visibility->query($user)->pluck('id')->all();

        return Inertia::render('crm/contacts/show', [
            'contact' => self::row($contact) + [
                'first_name' => $contact->first_name, 'last_name' => $contact->last_name, 'company_name' => $contact->company_name,
                'emails' => $contact->emails, 'phones' => $contact->phones,
                'address' => ['line1' => $contact->address_line1, 'line2' => $contact->address_line2, 'city' => $contact->city, 'state' => $contact->state, 'zip' => $contact->zip],
                'birthday' => $contact->birthday?->toDateString(),
                'consent' => ['email' => $contact->email_opt_in, 'sms' => $contact->sms_opt_in, 'do_not_call' => $contact->do_not_call],
            ],
            'households' => $contact->households()->with('members')->get()->map(fn ($h): array => [
                'ulid' => $h->ulid, 'name' => $h->name,
                'members' => $h->members->whereIn('id', $visibleIds)->map(fn (Contact $m): array => ['ulid' => $m->ulid, 'name' => $m->name])->values(),
            ]),
            'relationships' => Relationship::query()->where('contact_id', $contact->id)->whereIn('related_contact_id', $visibleIds)->with('related')->get()
                ->map(fn (Relationship $r): array => ['relation' => $r->relation->value, 'ulid' => $r->related->ulid, 'name' => $r->related->name]),
            'canManage' => $this->visibility->canManage($user, $contact),
            'agents' => $user->isKind(UserKind::Owner, UserKind::Staff) && $contact->origin === Origin::Company
                ? User::query()->where('status', UserStatus::Active)->whereIn('kind', [UserKind::Agent, UserKind::Owner, UserKind::Staff])
                    ->orderBy('first_name')->get()->map(fn (User $a): array => ['id' => $a->id, 'name' => $a->name])
                : [],
            'lifecycles' => array_map(fn (Lifecycle $l): string => $l->value, Lifecycle::cases()),
            'relations' => array_map(fn (Relation $r): string => $r->value, Relation::cases()),
        ]);
    }

    public function update(Request $request, Contact $contact, ManageContacts $contacts): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);
        abort_unless($this->visibility->canManage($user, $contact), 403);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:150'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'birthday' => ['sometimes', 'nullable', 'date'],
            'lifecycle' => ['sometimes', Rule::enum(Lifecycle::class)],
        ]);

        $contacts->update($contact, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    public function assign(Request $request, Contact $contact, ManageContacts $contacts): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);
        $data = $request->validate(['owner_user_id' => ['required', 'integer']]);
        $newOwner = User::query()->whereKey($data['owner_user_id'])->first();
        abort_if($newOwner === null, 422);

        $contacts->assignOwner($contact, $newOwner, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Assigned to {$newOwner->name}."]);

        return back();
    }

    public function relate(Request $request, Contact $contact, ManageContacts $contacts): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);
        $data = $request->validate([
            'related' => ['required', 'string', 'size:26'],
            'relation' => ['required', Rule::enum(Relation::class)],
            'household' => ['sometimes', 'boolean'],
        ]);
        $related = Contact::query()->where('ulid', $data['related'])->first();
        abort_if($related === null || ! $this->visibility->canSee($user, $related), 404);

        $contacts->relate($contact, $related, Relation::from($data['relation']), $user);
        if (($data['household'] ?? false) === true) {
            $contacts->createHousehold(trim($contact->last_name.' household') ?: 'Household', [$contact, $related], $user);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Contact $c): array
    {
        return [
            'ulid' => $c->ulid,
            'name' => $c->name,
            'email' => $c->primary_email,
            'phone' => $c->primary_phone,
            'origin' => $c->origin->value,
            'source' => $c->source->value,
            'lifecycle' => $c->lifecycle->value,
            'owner_user_id' => $c->owner_user_id,
            'owner' => $c->owner_user_id !== null ? User::query()->whereKey($c->owner_user_id)->first()?->name : null,
            'last_activity_at' => $c->last_activity_at?->toIso8601String(),
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
