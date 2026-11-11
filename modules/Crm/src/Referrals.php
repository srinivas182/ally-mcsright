<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Referral;
use Modules\Crm\Models\Task;

/**
 * The referral engine: record who referred whom, thank them straight away, celebrate when the
 * referral closes, and know when to ask (differentiator 20).
 */
final class Referrals
{
    public function __construct(private readonly ContactVisibility $visibility, private readonly Timeline $timeline) {}

    public function record(Contact $referred, Contact $referrer, User $by): Referral
    {
        if ($referred->is($referrer) || ! $this->visibility->canSee($by, $referred) || ! $this->visibility->canSee($by, $referrer)) {
            throw ValidationException::withMessages(['referrer' => 'Choose another contact you can see.']);
        }
        if (Referral::query()->where('referred_contact_id', $referred->id)->exists()) {
            throw ValidationException::withMessages(['referrer' => "{$referred->name} already has a referrer."]);
        }

        $referral = Referral::query()->create(['referrer_contact_id' => $referrer->id, 'referred_contact_id' => $referred->id, 'created_by' => $by->id]);
        $this->timeline->system($referred, "Referred by {$referrer->name}", $by->id);
        $this->timeline->system($referrer, "Referred {$referred->name}", $by->id);
        Task::query()->create(['contact_id' => $referrer->id, 'assigned_to' => $referrer->owner_user_id ?? $by->id, 'type' => 'call',
            'title' => mb_substr("Thank {$referrer->name} for referring {$referred->name}", 0, 200), 'due_at' => CarbonImmutable::now(), 'created_by' => $by->id]);

        return $referral;
    }

    /** Called when a contact's deal closes. */
    public function closed(Contact $referred): void
    {
        $referral = Referral::query()->with('referrer')->where('referred_contact_id', $referred->id)->where('status', 'received')->first();
        if ($referral === null) {
            return;
        }

        $referral->update(['status' => 'closed', 'closed_at' => CarbonImmutable::now()]);
        $referrer = $referral->referrer;
        $this->timeline->system($referrer, "Referral closed: {$referred->name}");
        Task::query()->create(['contact_id' => $referrer->id, 'assigned_to' => $referrer->owner_user_id ?? $referred->owner_user_id ?? 0, 'type' => 'follow_up',
            'title' => mb_substr("Send {$referrer->name} a thank-you gift: {$referred->name}'s deal closed", 0, 200), 'due_at' => CarbonImmutable::now(), 'priority' => 'high']);
    }

    /**
     * @return array{referred: int, closed: int}
     */
    public function stats(Contact $contact): array
    {
        $query = Referral::query()->where('referrer_contact_id', $contact->id);

        return ['referred' => (clone $query)->count(), 'closed' => (clone $query)->where('status', 'closed')->count()];
    }
}
