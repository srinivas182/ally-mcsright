<?php

declare(strict_types=1);

namespace Modules\Crm\Workflows;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowAction;
use Modules\Crm\Actions\Tasks;

final class CreateTask implements WorkflowAction
{
    public function key(): string
    {
        return 'create_task';
    }

    public function label(): string
    {
        return 'Create a task';
    }

    public function subjects(): array
    {
        return ['contact', 'deal', 'agent'];
    }

    public function params(): array
    {
        return [
            'title' => ['label' => 'Task', 'type' => 'text', 'required' => true],
            'due_days' => ['label' => 'Due in (days)', 'type' => 'number'],
            'assign' => ['label' => 'For', 'type' => 'choice', 'options' => ['owner' => 'The agent it belongs to', 'runner' => 'Whoever switched the automation on'], 'required' => true],
        ];
    }

    public function describe(array $params): string
    {
        $days = (int) ($params['due_days'] ?? 0);

        return 'Create a task for '.(($params['assign'] ?? 'owner') === 'owner' ? 'the agent' : 'you').': "'.($params['title'] ?? '').'" (due '.($days === 0 ? 'today' : "in {$days} days").')';
    }

    public function run(Subject $subject, array $params, array $facts, User $runner): string
    {
        $for = ($params['assign'] ?? 'owner') === 'owner' && $subject->ownerUserId !== null ? (User::query()->find($subject->ownerUserId) ?? $runner) : $runner;
        $due = CarbonImmutable::now($for->timezone ?: 'America/Chicago')->addDays(max(0, (int) ($params['due_days'] ?? 0)))->setTime(17, 0);
        app(Tasks::class)->create(CrmWorkflows::contactOf($subject), ['title' => mb_substr((string) $params['title'], 0, 200), 'due_at' => $due->toIso8601String(),
            'assigned_to' => $for->id, 'notes' => 'Created by an automation for '.$subject->label.'.'], $runner);

        return 'Task for '.$for->name.': '.$params['title'];
    }
}
