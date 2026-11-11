<?php

declare(strict_types=1);

namespace Modules\Crm\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Relation;
use Modules\Crm\Enums\Source;
use Modules\Crm\Events\ContactCreated;
use Modules\Crm\Events\ContactOwnerChanged;
use Modules\Crm\Events\ContactUpdated;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Household;
use Modules\Crm\Models\Relationship;

/**
 * Quick add, update, owner changes, households and relationships (specs/crm/01, Sprint 4D).
 */
final class ManageContacts
{
    public function __construct(private readonly ContactVisibility $visibility, private readonly Timeline $timeline) {}

    /**
     * Quick add: a name plus an email or phone. Agents' contacts are personal by default; owners
     * and staff add company contacts. Duplicates are refused without revealing hidden contacts.
     *
     * @param  array{first_name?: string, last_name?: string, company_name?: string|null, email?: string|null, phone?: string|null, source?: string|null, origin?: string|null, owner_user_id?: int|null}  $data
     */
    public function create(array $data, User $by): Contact
    {
        $email = isset($data['email']) && trim((string) $data['email']) !== '' ? mb_strtolower(trim((string) $data['email'])) : null;
        $phone = isset($data['phone']) && trim((string) $data['phone']) !== '' ? self::e164((string) $data['phone']) : null;
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $company = isset($data['company_name']) ? trim((string) $data['company_name']) : null;

        $errors = [];
        if ($first === '' && $last === '' && ($company === null || $company === '')) {
            $errors['first_name'] = 'Enter a name.';
        }
        if ($email === null && $phone === null) {
            $errors['email'] = 'Enter an email or a phone number.';
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (isset($data['phone']) && trim((string) $data['phone']) !== '' && $phone === null) {
            $errors['phone'] = 'Enter a 10-digit US phone number.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->guardDuplicate($email, $phone, $by);

        $isManager = $by->isKind(UserKind::Owner, UserKind::Staff);
        $origin = Origin::tryFrom((string) ($data['origin'] ?? '')) ?? ($isManager ? Origin::Company : Origin::Personal);
        $owner = $by->kind === UserKind::Assistant ? $by->assisted_user_id : $by->id;
        if ($isManager && array_key_exists('owner_user_id', $data)) {
            $owner = $data['owner_user_id'];
        }

        $contact = Contact::query()->create([
            'type' => ($first === '' && $last === '') ? 'company' : 'person',
            'first_name' => $first,
            'last_name' => $last,
            'company_name' => $company !== '' ? $company : null,
            'emails' => $email !== null ? [['address' => $email, 'label' => 'personal', 'primary' => true]] : [],
            'phones' => $phone !== null ? [['e164' => $phone, 'label' => 'mobile', 'primary' => true, 'can_text' => true]] : [],
            'origin' => $origin,
            'source' => Source::tryFrom((string) ($data['source'] ?? '')) ?? Source::Manual,
            'lifecycle' => Lifecycle::Lead,
            'owner_user_id' => $owner,
            'created_by' => $by->id,
        ]);

        $this->timeline->system($contact, 'Contact added by '.$by->name.' (source: '.str_replace('_', ' ', $contact->source->value).')', $by->id);
        ContactCreated::dispatch($contact->tenant_id, $contact->id, $by->id);

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Contact $contact, array $data, User $by): Contact
    {
        $allowed = array_intersect_key($data, array_flip(['first_name', 'last_name', 'company_name', 'address_line1', 'address_line2', 'city', 'state', 'zip', 'birthday', 'lifecycle', 'source_detail', 'rhythm', 'closed_on']));
        if (array_key_exists('rhythm', $allowed) && ! in_array($allowed['rhythm'], [null, 'A', 'B', 'C', 'D'], true)) {
            throw ValidationException::withMessages(['rhythm' => 'Choose A, B, C or D.']);
        }
        if (isset($allowed['lifecycle'])) {
            $allowed['lifecycle'] = Lifecycle::from((string) $allowed['lifecycle']);
        }
        if (isset($allowed['state'])) {
            $allowed['state'] = mb_strtoupper((string) $allowed['state']);
        }

        $previousStage = $contact->lifecycle;
        $contact->update($allowed);
        if (isset($allowed['lifecycle']) && $allowed['lifecycle'] !== $previousStage) {
            $this->timeline->system($contact, 'Stage changed from '.str_replace('_', ' ', $previousStage->value).' to '.str_replace('_', ' ', $contact->lifecycle->value), $by->id);
        }
        ContactUpdated::dispatch($contact->tenant_id, $contact->id, $by->id);

        return $contact;
    }

    public function assignOwner(Contact $contact, User $newOwner, User $by): Contact
    {
        if (! $by->isKind(UserKind::Owner, UserKind::Staff) || $contact->origin !== Origin::Company) {
            throw ValidationException::withMessages(['owner_user_id' => 'Only company contacts can be reassigned, by the brokerage.']);
        }
        if ($newOwner->tenant_id !== $contact->tenant_id || ! $newOwner->isActive() || ! $newOwner->isKind(UserKind::Agent, UserKind::Owner, UserKind::Staff)) {
            throw ValidationException::withMessages(['owner_user_id' => 'Choose an active agent in this brokerage.']);
        }

        $from = $contact->owner_user_id;
        $contact->update(['owner_user_id' => $newOwner->id]);
        $this->timeline->system($contact, "Assigned to {$newOwner->name} by {$by->name}", $by->id);
        ContactOwnerChanged::dispatch($contact->tenant_id, $contact->id, $from, $newOwner->id, $by->id);

        return $contact;
    }

    /**
     * Links contacts who buy or sell together. Every member must be visible to the user.
     *
     * @param  list<Contact>  $members
     */
    public function createHousehold(string $name, array $members, User $by): Household
    {
        foreach ($members as $member) {
            if (! $this->visibility->canSee($by, $member)) {
                throw ValidationException::withMessages(['members' => 'You can only group contacts you can see.']);
            }
        }

        return DB::transaction(function () use ($name, $members): Household {
            $household = Household::query()->create(['name' => trim($name)]);
            foreach ($members as $member) {
                $household->members()->attach($member->id, ['role' => 'member', 'tenant_id' => $household->getAttribute('tenant_id')]);
            }

            return $household;
        });
    }

    public function relate(Contact $contact, Contact $related, Relation $relation, User $by): Relationship
    {
        if ($contact->is($related) || ! $this->visibility->canSee($by, $contact) || ! $this->visibility->canSee($by, $related)) {
            throw ValidationException::withMessages(['related' => 'Choose another contact you can see.']);
        }

        return Relationship::query()->firstOrCreate(['contact_id' => $contact->id, 'related_contact_id' => $related->id, 'relation' => $relation]);
    }

    /**
     * Refuses a duplicate email or phone in the brokerage. If the user can see the existing
     * contact they are pointed to it; otherwise nothing about it is revealed.
     */
    private function guardDuplicate(?string $email, ?string $phone, User $by): void
    {
        $existing = Contact::query()->where(function ($q) use ($email, $phone): void {
            if ($email !== null) {
                $q->orWhere('primary_email', $email);
            }
            if ($phone !== null) {
                $q->orWhere('primary_phone', $phone);
            }
        })->first();

        if ($existing === null) {
            return;
        }

        $message = $this->visibility->canSee($by, $existing)
            ? "You already have this contact: {$existing->name}."
            : 'A contact with this email or phone already exists in the brokerage. Ask your broker to share or reassign it.';

        throw ValidationException::withMessages([$email !== null ? 'email' : 'phone' => $message, 'duplicate' => $this->visibility->canSee($by, $existing) ? $existing->ulid : '']);
    }

    public static function e164(string $raw): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? '+1'.$digits : null;
    }
}
