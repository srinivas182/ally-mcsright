<?php

declare(strict_types=1);

namespace Modules\Crm\Plans;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\PlanEnrollment;
use Modules\Crm\Models\Task;

/**
 * Follow-up that reacts to what the person does (specs/grow/03, SO2). Plans still run on their days; on top of that:
 *
 * - **Steps up** when someone shows they are ready on the website (saves a home, works out a payment, keeps coming back
 *   to the same home, or returns after two weeks away): the agent gets a call task now, with the reason in its title,
 *   at most once a day per person.
 * - **Hands over** when the person replies or a call connects: the plan stops (FollowUpPlans) and the agent takes it.
 * - **Keeps going** when someone goes quiet: once a plan finishes with no reply, they move to the monthly "Not ready yet"
 *   plan instead of being forgotten.
 *
 * Every step it adds says why. Nothing is sent by itself: the agent sends, and texts still need consent (8am–9pm).
 */
final class Reactions
{
    public const QUIET_AFTER_DAYS = 3;

    public function __construct(private readonly FollowUpPlans $plans, private readonly Timeline $timeline, private readonly Consent $consent) {}

    /** The person did something that says "now": the agent gets a call task today. */
    public function stepUp(Contact $contact, string $why): ?Task
    {
        if (in_array($contact->lifecycle, [Lifecycle::UnderContract, Lifecycle::PastClient, Lifecycle::Archived], true)) {
            return null;
        }
        $agentId = $contact->owner_user_id ?? PlanEnrollment::query()->where('contact_id', $contact->id)->where('status', 'active')->value('assigned_to');
        if ($agentId === null) {
            return null;
        }
        $today = CarbonImmutable::now()->startOfDay();
        if (Task::query()->where('contact_id', $contact->id)->where('title', 'like', 'Reach out now:%')->where('created_at', '>=', $today)->exists()) {
            return null;
        }
        $how = $this->consent->canCall($contact) ? 'call' : ($contact->primary_email !== null ? 'email' : 'follow_up');
        $task = app(AuditLogger::class)->asAutomation('follow_up_reaction', (int) $agentId, 'Plan step-up: '.$why, fn (): Task => Task::query()->create([
            'contact_id' => $contact->id, 'assigned_to' => (int) $agentId, 'type' => $how, 'title' => mb_substr('Reach out now: '.$contact->name.' '.$why, 0, 200),
            'due_at' => CarbonImmutable::now(), 'created_by' => null,
            'plan_enrollment_id' => PlanEnrollment::query()->where('contact_id', $contact->id)->where('status', 'active')->value('id'),
        ]));
        $contact->newQuery()->whereKey($contact->id)->update(['next_task_at' => CarbonImmutable::now()]);
        $this->timeline->system($contact, 'Follow-up stepped up: '.$contact->name.' '.$why.'. A '.str_replace('_', '-', $how).' task was added for today.');

        return $task;
    }

    /**
     * Daily: people whose plan finished a few days ago without a reply move to the monthly plan, unless they are on
     * another plan, replied, or moved on.
     */
    public function keepGoing(): int
    {
        $plan = FollowUpPlan::query()->where('template_key', FollowUpPlans::QUIET_PLAN)->where('active', true)->first();
        if ($plan === null) {
            return 0;
        }
        $moved = 0;
        $finished = PlanEnrollment::query()->where('status', 'completed')->where('plan_id', '!=', $plan->id)
            ->whereBetween('updated_at', [CarbonImmutable::now()->subDays(self::QUIET_AFTER_DAYS + 7), CarbonImmutable::now()->subDays(self::QUIET_AFTER_DAYS)])->limit(1000)->get();
        foreach ($finished as $done) {
            $contact = Contact::query()->find($done->contact_id);
            if ($contact === null || ! in_array($contact->lifecycle, [Lifecycle::Lead, Lifecycle::Active], true)
                || PlanEnrollment::query()->where('contact_id', $contact->id)->where('status', 'active')->exists()
                || PlanEnrollment::query()->where('contact_id', $contact->id)->where('plan_id', $plan->id)->exists()
                || DB::connection($contact->getConnectionName())->table('crm_activities')->where('contact_id', $contact->id)->where('occurred_at', '>=', $done->started_at)
                    ->where(fn ($q) => $q->where(fn ($c) => $c->where('type', 'call')->where('outcome', 'connected'))->orWhere(fn ($m) => $m->whereIn('type', ['text', 'email'])->where('direction', 'in')))->exists()) {
                continue;
            }
            $agent = User::query()->find($done->assigned_to);
            if ($agent === null) {
                continue;
            }
            $this->plans->enroll($contact, $plan, $agent);
            $this->timeline->system($contact, 'Moved to "'.$plan->name.'": no reply after "'.$done->plan->name.'".');
            $moved++;
        }

        return $moved;
    }
}
