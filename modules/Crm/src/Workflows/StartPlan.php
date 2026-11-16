<?php

declare(strict_types=1);

namespace Modules\Crm\Workflows;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowAction;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Models\FollowUpPlan;

/** Start a follow-up plan: the only way an automation reaches a client, so consent and quiet hours always apply. */
final class StartPlan implements WorkflowAction
{
    public function key(): string
    {
        return 'start_plan';
    }

    public function label(): string
    {
        return 'Start a follow-up plan (texts and emails keep consent rules)';
    }

    public function subjects(): array
    {
        return ['contact', 'deal'];
    }

    public function params(): array
    {
        return ['plan' => ['label' => 'Plan', 'type' => 'choice', 'options' => FollowUpPlan::query()->where('active', true)->where('shared', true)->orderBy('name')->pluck('name', 'ulid')->all(), 'required' => true]];
    }

    public function describe(array $params): string
    {
        $name = FollowUpPlan::query()->where('ulid', $params['plan'] ?? '')->value('name');

        return 'Start the follow-up plan "'.($name ?? 'a plan').'"';
    }

    public function run(Subject $subject, array $params, array $facts, User $runner): string
    {
        $contact = CrmWorkflows::contactOf($subject) ?? throw ValidationException::withMessages(['plan' => 'This deal has no client contact.']);
        $plan = FollowUpPlan::query()->where('ulid', $params['plan'] ?? '')->where('active', true)->first() ?? throw ValidationException::withMessages(['plan' => 'That plan is off or was removed.']);
        $owner = $contact->owner_user_id !== null ? User::query()->find($contact->owner_user_id) : null;
        app(FollowUpPlans::class)->enroll($contact, $plan, $owner ?? $runner);

        return 'Started "'.$plan->name.'" for '.$contact->first_name.'.';
    }
}
