<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use App\Models\User;

/**
 * Something an automation can do (specs/core/06, W1). Modules register their own: tasks, tags and follow-up plans in
 * the CRM, checklist items on deals. No action may move money, delete records or message people outside the
 * consent rules; messages go through follow-up plans, which keep them.
 */
interface WorkflowAction
{
    public function key(): string;

    public function label(): string;

    /**
     * The kinds of record it works on ([] = any).
     *
     * @return list<string>
     */
    public function subjects(): array;

    /**
     * What to fill in, for the builder.
     *
     * @return array<string, array{label: string, type: string, options?: array<string, string>, required?: bool}>
     */
    public function params(): array;

    /**
     * The step in plain English, e.g. "Create a task for the agent: Order the inspection (due in 2 days)".
     *
     * @param  array<string, mixed>  $params
     */
    public function describe(array $params): string;

    /**
     * Does the step and returns a short note for the run history.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $facts
     */
    public function run(Subject $subject, array $params, array $facts, User $runner): string;
}
