<?php

declare(strict_types=1);

namespace Modules\Crm\Actions;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Relationship;
use Modules\Crm\Models\Task;

/**
 * Finds likely duplicates and merges them. The kept contact gains the other's emails, phones,
 * empty fields, timeline, tasks, tags, households and relationships; the stricter consent wins.
 * The merged contact is soft-deleted with a pointer to the one kept.
 */
final class MergeContacts
{
    public function __construct(private readonly ContactVisibility $visibility, private readonly Timeline $timeline) {}

    /**
     * Pairs among the user's visible contacts sharing a primary email, primary phone or name.
     *
     * @return Collection<int, array{keep: Contact, duplicate: Contact, reason: string}>
     */
    public function candidates(User $user): Collection
    {
        $contacts = $this->visibility->query($user)->get();
        $pairs = collect();
        $seen = [];

        foreach (['primary_email' => 'same email', 'primary_phone' => 'same phone', 'search_name' => 'same name'] as $field => $reason) {
            foreach ($contacts->whereNotNull($field)->where($field, '!=', '')->groupBy($field) as $group) {
                if ($group->count() < 2) {
                    continue;
                }
                $sorted = $group->sortBy('id')->values();
                $first = $sorted->first();
                foreach ($sorted->slice(1) as $other) {
                    if ($first === null) {
                        continue;
                    }
                    $key = $first->id.'-'.$other->id;
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $pairs->push(['keep' => $first, 'duplicate' => $other, 'reason' => $reason]);
                    }
                }
            }
        }

        return $pairs->take(100)->values();
    }

    public function merge(Contact $keep, Contact $duplicate, User $by): Contact
    {
        if ($keep->is($duplicate)) {
            throw ValidationException::withMessages(['duplicate' => 'Choose two different contacts.']);
        }
        foreach ([$keep, $duplicate] as $contact) {
            if (! $this->visibility->canManage($by, $contact)) {
                throw ValidationException::withMessages(['duplicate' => 'You can only merge contacts you manage.']);
            }
        }
        if ($keep->origin !== $duplicate->origin && ! $by->isKind(UserKind::Owner, UserKind::Staff)) {
            throw ValidationException::withMessages(['duplicate' => 'Ask your broker to merge a personal and a company contact.']);
        }

        return DB::transaction(function () use ($keep, $duplicate, $by): Contact {
            $emails = collect($keep->emails)->concat(array_map(fn ($e) => ['primary' => false] + $e, $duplicate->emails))->unique(fn ($e) => mb_strtolower($e['address']))->values()->all();
            $phones = collect($keep->phones)->concat(array_map(fn ($p) => ['primary' => false] + $p, $duplicate->phones))->unique('e164')->values()->all();

            $fill = ['emails' => $emails, 'phones' => $phones];
            foreach (['first_name', 'last_name', 'company_name', 'address_line1', 'address_line2', 'city', 'state', 'zip', 'birthday', 'source_detail'] as $field) {
                if (($keep->getAttribute($field) === null || $keep->getAttribute($field) === '') && $duplicate->getAttribute($field) !== null) {
                    $fill[$field] = $duplicate->getAttribute($field);
                }
            }

            // The stricter consent wins: any opt-out or Do Not Call is kept.
            $doNotCall = $keep->do_not_call || $duplicate->do_not_call;
            $optedOut = $keep->sms_opt_out_at !== null || $duplicate->sms_opt_out_at !== null;
            $keep->fill($fill);
            $keep->forceFill([
                'do_not_call' => $doNotCall,
                'sms_opt_in' => $keep->sms_opt_in && ! ($duplicate->sms_opt_out_at !== null && ($keep->sms_opt_in_at === null || $duplicate->sms_opt_out_at->gt($keep->sms_opt_in_at))),
                'sms_opt_out_at' => $optedOut ? max($keep->sms_opt_out_at, $duplicate->sms_opt_out_at) : null,
                'last_activity_at' => max($keep->last_activity_at, $duplicate->last_activity_at),
            ])->save();

            Activity::query()->where('contact_id', $duplicate->id)->update(['contact_id' => $keep->id]);
            Task::query()->where('contact_id', $duplicate->id)->update(['contact_id' => $keep->id]);
            ConsentEvent::query()->where('contact_id', $duplicate->id)->toBase()->update(['contact_id' => $keep->id]);
            $keep->tags()->syncWithoutDetaching($duplicate->tags()->pluck('crm_tags.id')->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $keep->tenant_id]])->all());
            foreach ($duplicate->households as $household) {
                if (! $keep->households()->whereKey($household->id)->exists()) {
                    $keep->households()->attach($household->id, ['role' => 'member', 'tenant_id' => $keep->tenant_id]);
                }
            }
            foreach (Relationship::query()->where('contact_id', $duplicate->id)->orWhere('related_contact_id', $duplicate->id)->get() as $rel) {
                $from = $rel->contact_id === $duplicate->id ? $keep->id : $rel->contact_id;
                $to = $rel->related_contact_id === $duplicate->id ? $keep->id : $rel->related_contact_id;
                $rel->delete();
                if ($from !== $to) {
                    Relationship::query()->firstOrCreate(['contact_id' => $from, 'related_contact_id' => $to, 'relation' => $rel->relation]);
                }
            }

            $duplicate->forceFill(['merged_into_id' => $keep->id])->save();
            $duplicate->delete();
            $this->timeline->system($keep, "Merged with {$duplicate->name} by {$by->name}", $by->id);

            return $keep;
        });
    }
}
