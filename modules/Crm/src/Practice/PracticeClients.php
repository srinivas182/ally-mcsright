<?php

declare(strict_types=1);

namespace Modules\Crm\Practice;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Source;
use Modules\Crm\Models\Contact;

/**
 * SX5: practice mode — five made-up clients to try things on (add a note, call, set a to-do, start a deal) without
 * touching real clients.
 *
 * - Their last name is "Practice", their numbers are 555-01xx (reserved for fiction) and their emails are at
 *   example.com (reserved), so nothing can reach a real person; on top of that nothing is ever sent to them
 *   (SendGuard).
 * - Only the person practising (and their assistant) sees them: owners, managers and teammates never do
 *   (ContactVisibility), and they do not start automations (no "contact created" event).
 * - "Remove practice people" deletes them and everything on their timeline; they are removed by themselves after
 *   DAYS days (crm:practice-cleanup, daily).
 */
final class PracticeClients
{
    public const DAYS = 14;

    /** First name, phone suffix, what they want, note. */
    private const PEOPLE = [
        ['Rosa', '0142', 'Wants to sell her 3-bedroom in the spring', 'Kids moved out; thinking about something smaller near the lake.'],
        ['Tom', '0157', 'First-time buyer, pre-approved', 'Pre-approved up to $350,000. Wants a yard for the dog.'],
        ['Ana', '0163', 'Asked about her home\'s value', 'Filled in the home value form on the website last week.'],
        ['Walt', '0178', 'Past client, bought in 2019', 'Bought on Elm Street in 2019. Good time to ask for a referral.'],
        ['June', '0186', 'Renting now, may buy next year', 'Lease ends in March. Saving for a down payment.'],
    ];

    public function __construct(private readonly Tasks $tasks, private readonly Timeline $timeline, private readonly AuditLogger $audit) {}

    /** Adds the five practice people (once; again only after removing them). Returns how many were added. */
    public function add(User $user): int
    {
        $owner = self::owner($user);
        if (Contact::query()->where('practice', true)->where('owner_user_id', $owner)->exists()) {
            return 0;
        }
        foreach (self::PEOPLE as $i => [$first, $suffix, $want, $note]) {
            $phone = '+1512555'.$suffix;
            $email = strtolower($first).'.practice@example.com';
            $contact = new Contact;
            $contact->forceFill([
                'type' => 'person', 'first_name' => $first, 'last_name' => 'Practice', 'practice' => true,
                'emails' => [['address' => $email, 'label' => 'personal', 'primary' => true]],
                'phones' => [['e164' => $phone, 'label' => 'mobile', 'primary' => true, 'can_text' => true]],
                'origin' => Origin::Personal, 'source' => Source::Manual, 'lifecycle' => $i === 3 ? Lifecycle::PastClient : Lifecycle::Lead,
                'owner_user_id' => $owner, 'created_by' => $user->id,
                // One of them agreed to texts, so the Text button can be tried too (nothing is ever sent).
                'sms_opt_in' => $i === 1, 'sms_opt_in_at' => $i === 1 ? CarbonImmutable::now() : null,
            ])->save();
            $this->timeline->log($contact, ['type' => 'note', 'body' => $want.'. '.$note], $user);
            if ($i === 0) {
                $this->tasks->create($contact, ['title' => 'Call Rosa about listing in the spring', 'due' => 'today'], $user);
            }
        }
        $this->audit->record('crm.practice.added', $user, severity: 'info');

        return count(self::PEOPLE);
    }

    /** Removes this person's practice people and everything on their timelines. */
    public function remove(User $user): int
    {
        return $this->removeWhere(fn ($q) => $q->where('owner_user_id', self::owner($user)));
    }

    /** Daily: practice people older than DAYS days. */
    public function cleanup(): int
    {
        return $this->removeWhere(fn ($q) => $q->where('created_at', '<', CarbonImmutable::now()->subDays(self::DAYS)));
    }

    public function has(User $user): bool
    {
        return Contact::query()->where('practice', true)->where('owner_user_id', self::owner($user))->exists();
    }

    /** @param callable(Builder<Contact>): mixed $where */
    private function removeWhere(callable $where): int
    {
        $query = Contact::query()->withTrashed()->where('practice', true);
        $where($query);
        $ids = $query->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }
        DB::transaction(function () use ($ids): void {
            foreach (['crm_activities', 'crm_tasks'] as $table) {
                DB::table($table)->whereIn('contact_id', $ids)->delete();
            }
            Contact::query()->withTrashed()->whereIn('id', $ids)->forceDelete();
        });

        return count($ids);
    }

    private static function owner(User $user): int
    {
        return $user->kind === UserKind::Assistant && $user->assisted_user_id !== null ? $user->assisted_user_id : $user->id;
    }
}
