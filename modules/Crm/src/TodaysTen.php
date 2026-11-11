<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactBrief;
use Modules\Crm\Models\ContactScore;
use Modules\Crm\Models\Intent;
use Modules\Crm\Models\Task;

/**
 * "Today's 10": who to call today and why. Candidates come from every signal we have (overdue
 * tasks, intents coming due, fresh leads, top scores, birthdays and anniversaries, keep-in-touch),
 * ranked by priority; contacts already spoken to today drop off. Always within ContactVisibility.
 */
final class TodaysTen
{
    public function __construct(private readonly ContactVisibility $visibility, private readonly SmartLists $lists, private readonly Consent $consent) {}

    /**
     * @param  list<int>  $skip
     * @return list<array<string, mixed>>
     */
    public function for(User $user, int $limit = 10, array $skip = []): array
    {
        $now = CarbonImmutable::now();
        $base = fn () => $this->visibility->query($user)
            ->whereNotIn('lifecycle', [Lifecycle::Archived, Lifecycle::UnderContract])
            ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $now->startOfDay()))
            ->whereNotIn('id', $skip);

        $candidates = [];
        $add = function (Contact $c, int $points, string $reason) use (&$candidates): void {
            $candidates[$c->id] ??= ['contact' => $c, 'points' => 0, 'reasons' => []];
            $candidates[$c->id]['points'] += $points;
            $candidates[$c->id]['reasons'][] = $reason;
        };

        foreach ($base()->whereExists(fn ($t) => $t->selectRaw('1')->from('crm_tasks')->whereColumn('crm_tasks.contact_id', 'crm_contacts.id')
            ->whereNull('crm_tasks.completed_at')->where('crm_tasks.due_at', '<', $now))->limit(30)->get() as $c) {
            $title = Task::query()->where('contact_id', $c->id)->whereNull('completed_at')->where('due_at', '<', $now)->orderBy('due_at')->value('title');
            $add($c, 50, 'Overdue: '.$title);
        }

        foreach (Intent::query()->where('status', 'open')->whereNotNull('due_on')->whereDate('due_on', '<=', $now->addDays(30)->toDateString())
            ->whereIn('contact_id', $base()->select('id'))->with('contact')->limit(30)->get() as $intent) {
            $add($intent->contact, 40, 'Said: "'.$intent->summary.'" ('.$intent->due_on?->format('M j').')');
        }

        foreach ($base()->where('lifecycle', Lifecycle::Lead)->whereNull('last_activity_at')->where('created_at', '>=', $now->subDays(2))->limit(20)->get() as $c) {
            $add($c, 45, 'New lead, not contacted yet');
        }

        foreach ($this->lists->apply($base(), ['birthday_within_days' => 1])->limit(10)->get() as $c) {
            $add($c, 30, 'Birthday today');
        }
        foreach ($this->lists->apply($base(), ['anniversary_within_days' => 1])->limit(10)->get() as $c) {
            $add($c, 30, 'Home anniversary today');
        }
        foreach ($this->lists->apply($base(), ['referral_moment' => true])->limit(10)->get() as $c) {
            $add($c, 25, 'Good moment to ask for a referral');
        }
        foreach ($this->lists->apply($base(), ['rhythm_due' => true])->limit(20)->get() as $c) {
            $add($c, 20, 'Due a keep-in-touch call ('.$c->rhythm.')');
        }

        $scores = ContactScore::query()->whereIn('contact_id', $base()->select('id'))->orderByRaw('(case when buyer > seller then buyer else seller end) desc')->limit(30)->get();
        foreach ($scores as $score) {
            $top = max($score->buyer, $score->seller);
            if ($top >= 30) {
                $contact = Contact::query()->find($score->contact_id);
                if ($contact !== null) {
                    $add($contact, intdiv($top, 2), ($score->buyer >= $score->seller ? 'Likely buyer' : 'Likely seller').' ('.$top.'): '.($score->reasons[0] ?? ''));
                }
            }
        }

        usort($candidates, fn (array $a, array $b): int => [$b['points'], $a['contact']->id] <=> [$a['points'], $b['contact']->id]);

        return array_map(function (array $pick): array {
            /** @var Contact $c */
            $c = $pick['contact'];

            return [
                'id' => $c->id, 'ulid' => $c->ulid, 'name' => $c->name, 'points' => $pick['points'],
                'reason' => $pick['reasons'][0], 'more' => array_slice($pick['reasons'], 1, 2),
                'phone' => $c->primary_phone, 'email' => $c->primary_email,
                'can_call' => $this->consent->canCall($c), 'can_text' => $this->consent->canText($c),
                'has_brief' => ContactBrief::query()->where('contact_id', $c->id)->exists(),
            ];
        }, array_slice($candidates, 0, $limit));
    }
}
