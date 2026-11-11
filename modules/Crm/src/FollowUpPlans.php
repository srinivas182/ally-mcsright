<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\PlanEnrollment;
use Modules\Crm\Models\PlanStep;
use Modules\Crm\Models\Task;

/**
 * Follow-up plans: steps ("Day 0 · Call · Introduce yourself") that arrive as tasks on schedule,
 * one at a time. Plans stop when the person engages or the deal moves on. Text steps only for
 * contacts with texting consent; templates are checked for Fair Housing wording.
 */
final class FollowUpPlans
{
    public const STEP_TYPES = ['call', 'text', 'email', 'task'];

    private const STOP_STAGES = [Lifecycle::UnderContract, Lifecycle::PastClient, Lifecycle::Archived];

    private const TEMPLATES = [
        ['New online lead (10 days)', 'Fast, steady follow-up for website and portal leads.', [
            [0, 'call', 'Call to introduce yourself', null],
            [0, 'text', 'Send an intro text', 'Hi {first_name}, it\'s {agent_first_name}. Thanks for reaching out about homes! What area and price range are you thinking about?'],
            [1, 'call', 'Second call attempt', null],
            [3, 'email', 'Email a few matching homes', 'Hi {first_name}, here are a few homes I think you\'ll like. Want to see any of them this week? — {agent_first_name}'],
            [5, 'call', 'Third call attempt', null],
            [7, 'text', 'Check-in text', 'Hi {first_name}, still looking? I can set up a search that sends new homes the day they list. — {agent_first_name}'],
            [10, 'email', 'Offer a short planning call', 'Hi {first_name}, would a 10-minute call help to plan your next steps? — {agent_first_name}'],
        ]],
        ['Open house follow-up', 'Thank visitors and find out what they need.', [
            [0, 'text', 'Thank them for visiting', 'Hi {first_name}, thanks for stopping by the open house today! Any questions about the home? — {agent_first_name}'],
            [2, 'call', 'Call: what did they think, what are they looking for?', null],
            [7, 'email', 'Send similar homes', 'Hi {first_name}, here are a few homes similar to the one you visited. — {agent_first_name}'],
        ]],
        ['Sphere check-in (quarterly)', 'Stay in touch with friends and past contacts.', [
            [0, 'call', 'Catch-up call', null],
            [90, 'email', 'Share a local market update', 'Hi {first_name}, here\'s what\'s happening in the local market this quarter. — {agent_first_name}'],
            [180, 'call', 'Catch-up call', null],
            [270, 'email', 'Share a local market update', 'Hi {first_name}, here\'s the latest on home values near you. — {agent_first_name}'],
        ]],
        ['Past client — first year', 'Look after new homeowners and earn referrals.', [
            [7, 'call', 'How is the new home?', null],
            [30, 'email', 'Helpful homeowner tips', 'Hi {first_name}, a few tips for your first months in the new home. — {agent_first_name}'],
            [180, 'call', 'Six-month check-in', null],
            [365, 'text', 'Happy home anniversary!', 'Happy one-year home anniversary, {first_name}! If friends are thinking of moving, I\'d love to help. — {agent_first_name}'],
        ]],
    ];

    public function __construct(private readonly ContactVisibility $visibility, private readonly Consent $consent, private readonly Timeline $timeline) {}

    /**
     * Plans this user may use (brokerage-shared and their own), creating the templates first time.
     *
     * @return Collection<int, FollowUpPlan>
     */
    public function plansFor(User $user): Collection
    {
        if (! FollowUpPlan::query()->exists()) {
            DB::transaction(function (): void {
                foreach (self::TEMPLATES as [$name, $description, $steps]) {
                    $plan = FollowUpPlan::query()->create(['name' => $name, 'description' => $description, 'shared' => true, 'active' => true, 'trigger' => 'manual']);
                    foreach ($steps as $i => [$day, $type, $title, $message]) {
                        PlanStep::query()->create(['plan_id' => $plan->id, 'sort' => $i, 'day' => $day, 'type' => $type, 'title' => $title, 'message' => $message]);
                    }
                }
            });
        }

        return FollowUpPlan::query()->with('steps')->where(fn ($q) => $q->where('shared', true)->orWhere('owner_user_id', $user->id))->orderBy('name')->get();
    }

    /**
     * @param  array{name: string, description?: string|null, trigger?: string|null, trigger_value?: string|null, shared?: bool|null, steps: list<array{day: int, type: string, title: string, message?: string|null}>}  $data
     */
    public function save(?FollowUpPlan $plan, array $data, User $by): FollowUpPlan
    {
        $isManager = $by->isKind(UserKind::Owner, UserKind::Staff);
        if ($plan !== null && ! $this->canEdit($plan, $by)) {
            throw ValidationException::withMessages(['plan' => 'You can only change your own plans.']);
        }

        $errors = [];
        $name = trim($data['name']);
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Give the plan a short name.';
        }
        $steps = $data['steps'];
        if ($steps === [] || count($steps) > 30) {
            $errors['steps'] = 'A plan has 1 to 30 steps.';
        }
        $previous = -1;
        foreach ($steps as $i => $step) {
            if (! in_array($step['type'], self::STEP_TYPES, true)) {
                $errors["steps.{$i}.type"] = 'Choose call, text, email or task.';
            }
            if ($step['day'] < 0 || $step['day'] > 365 || $step['day'] < $previous) {
                $errors["steps.{$i}.day"] = 'Days run from 0 to 365, in order.';
            }
            $previous = $step['day'];
            if (trim($step['title']) === '') {
                $errors["steps.{$i}.title"] = 'Describe the step.';
            }
            $flags = FairHousing::flags($step['title'].' '.($step['message'] ?? ''));
            if ($flags !== []) {
                $errors["steps.{$i}.message"] = 'Please reword: "'.implode('", "', $flags).'" could raise Fair Housing concerns.';
            }
        }
        $trigger = in_array($data['trigger'] ?? 'manual', ['manual', 'source', 'stage'], true) ? (string) ($data['trigger'] ?? 'manual') : 'manual';
        if ($trigger !== 'manual' && ! $isManager && ($data['shared'] ?? false)) {
            $errors['trigger'] = 'Only owners and staff can enrol the whole brokerage automatically.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($plan, $data, $name, $trigger, $by, $isManager): FollowUpPlan {
            $attributes = ['name' => $name, 'description' => $data['description'] ?? null, 'trigger' => $trigger,
                'trigger_value' => $trigger === 'manual' ? null : ($data['trigger_value'] ?? null),
                'shared' => $isManager && (bool) ($data['shared'] ?? false), 'active' => true];
            $plan = $plan ?? new FollowUpPlan(['owner_user_id' => $by->id]);
            $plan->fill($attributes)->save();

            PlanStep::query()->where('plan_id', $plan->id)->delete();
            foreach ($data['steps'] as $i => $step) {
                PlanStep::query()->create(['plan_id' => $plan->id, 'sort' => $i, 'day' => $step['day'], 'type' => $step['type'],
                    'title' => trim($step['title']), 'message' => isset($step['message']) && trim((string) $step['message']) !== '' ? trim((string) $step['message']) : null]);
            }

            return $plan;
        });
    }

    public function enroll(Contact $contact, FollowUpPlan $plan, User $by): PlanEnrollment
    {
        if (! $this->visibility->canSee($by, $contact)) {
            throw ValidationException::withMessages(['contact' => 'Contact not found.']);
        }
        if (! $plan->active || (! $plan->shared && $plan->owner_user_id !== $by->id && $plan->owner_user_id !== $contact->owner_user_id)) {
            throw ValidationException::withMessages(['plan' => 'Choose an active plan you can use.']);
        }
        if (PlanEnrollment::query()->where('contact_id', $contact->id)->where('plan_id', $plan->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['plan' => "{$contact->name} is already on this plan."]);
        }

        $first = $plan->steps()->first();
        $now = CarbonImmutable::now();
        $enrollment = PlanEnrollment::query()->create([
            'plan_id' => $plan->id, 'contact_id' => $contact->id, 'assigned_to' => $contact->owner_user_id ?? $by->id,
            'status' => 'active', 'next_step' => 0, 'started_at' => $now, 'next_due_at' => $now->addDays($first !== null ? $first->day : 0),
        ]);
        $this->timeline->system($contact, "Started follow-up plan: {$plan->name}", $by->id);
        $this->process($enrollment);

        return $enrollment;
    }

    public function stop(PlanEnrollment $enrollment, string $reason, ?int $byUserId = null): void
    {
        if ($enrollment->status !== 'active') {
            return;
        }

        $enrollment->update(['status' => 'stopped', 'stop_reason' => mb_substr($reason, 0, 100), 'next_due_at' => null]);
        Task::query()->where('plan_enrollment_id', $enrollment->id)->whereNull('completed_at')->delete();
        $this->timeline->system($enrollment->contact, "Follow-up plan stopped ({$enrollment->plan->name}): {$reason}", $byUserId);
    }

    public function stopAllFor(Contact $contact, string $reason): void
    {
        foreach (PlanEnrollment::query()->where('contact_id', $contact->id)->where('status', 'active')->get() as $enrollment) {
            $this->stop($enrollment, $reason);
        }
    }

    /**
     * Creates the tasks for steps whose day has come; completes or stops the plan as needed.
     */
    public function process(PlanEnrollment $enrollment): void
    {
        $contact = $enrollment->contact;
        if (in_array($contact->lifecycle, self::STOP_STAGES, true)) {
            $this->stop($enrollment, 'Contact is now '.str_replace('_', ' ', $contact->lifecycle->value));

            return;
        }

        $steps = $enrollment->plan->steps()->get()->values();
        $index = $enrollment->next_step;
        $now = CarbonImmutable::now();

        $step = $steps->get($index);
        while ($step !== null && $enrollment->started_at->addDays($step->day)->lte($now)) {
            $this->createTask($enrollment, $step, $contact);
            $index++;
            $step = $steps->get($index);
        }

        $enrollment->update([
            'next_step' => $index,
            'status' => $step === null ? 'completed' : 'active',
            'next_due_at' => $step === null ? null : $enrollment->started_at->addDays($step->day),
        ]);
    }

    public function processDue(): int
    {
        $count = 0;
        foreach (PlanEnrollment::query()->where('status', 'active')->where('next_due_at', '<=', CarbonImmutable::now())->limit(2000)->get() as $enrollment) {
            $this->process($enrollment);
            $count++;
        }

        return $count;
    }

    public function canEdit(FollowUpPlan $plan, User $user): bool
    {
        return $plan->shared ? $user->isKind(UserKind::Owner, UserKind::Staff) : $plan->owner_user_id === $user->id;
    }

    private function createTask(PlanEnrollment $enrollment, PlanStep $step, Contact $contact): void
    {
        $owner = User::query()->find($enrollment->assigned_to);
        $type = $step->type;
        $title = $step->title;

        // Respect consent: no text without texting consent, no call when Do Not Call.
        if ($type === 'text' && ! $this->consent->canText($contact)) {
            $type = $contact->primary_email !== null ? 'email' : 'call';
            $title .= ' (sent by '.$type.': no texting consent)';
        }
        if ($type === 'call' && ! $this->consent->canCall($contact)) {
            $type = $contact->primary_email !== null ? 'email' : 'task';
            $title .= ' (Do Not Call: '.($type === 'email' ? 'email instead' : 'no call').')';
        }

        $message = $step->message !== null
            ? strtr($step->message, ['{first_name}' => $contact->first_name !== '' ? $contact->first_name : 'there', '{agent_first_name}' => $owner instanceof User ? $owner->first_name : ''])
            : null;

        Task::query()->create([
            'contact_id' => $contact->id, 'assigned_to' => $enrollment->assigned_to, 'type' => $type === 'task' ? 'follow_up' : $type,
            'title' => mb_substr($title, 0, 200), 'due_at' => CarbonImmutable::now(), 'plan_enrollment_id' => $enrollment->id, 'message' => $message,
            'created_by' => null,
        ]);
        $contact->newQuery()->whereKey($contact->id)->update(['next_task_at' => Task::query()->where('contact_id', $contact->id)->whereNull('completed_at')->min('due_at')]);
    }
}
