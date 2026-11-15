<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Models\Task;

/** Marks one of the person's tasks done (only tasks they can see, as on the Tasks screen). */
#[Description('Mark a task done. Use the task id from my_tasks or daily_brief.')]
#[IsIdempotent]
final class CompleteTask extends RightAllyTool
{
    protected string $name = 'complete_task';

    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->string()->description('The task id')->required()];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $id = (string) $request->validate(['task_id' => ['required', 'string', 'size:26']])['task_id'];
        $t = Task::query()->where('ulid', $id)->first();
        if ($t === null || ! app(Tasks::class)->canSee($user, $t)) {
            throw ValidationException::withMessages(['task_id' => 'No task with that id that you can see.']);
        }
        app(Tasks::class)->complete($t, $user);

        return ['done' => ['id' => $t->ulid, 'title' => $t->title]];
    }
}
