<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;

#[Description('Your open tasks in RightAlly (calls, showings, follow-ups), overdue first, for the next days you ask for.')]
#[IsReadOnly]
final class MyTasks extends RightAllyTool
{
    protected string $name = 'my_tasks';

    public function schema(JsonSchema $schema): array
    {
        return ['days' => $schema->integer()->description('How many days ahead (1 to 30, default 7)')];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<int, array<string, mixed>> */
    protected function run(Request $request, User $user): array
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:30']])['days'] ?? 7);
        $tasks = Task::query()->where('assigned_to', $user->id)->whereNull('completed_at')->where('due_at', '<=', CarbonImmutable::now()->addDays($days)->endOfDay())
            ->orderBy('due_at')->limit(50)->get();
        $names = Contact::query()->whereIn('id', $tasks->pluck('contact_id')->filter()->unique()->all())->get()
            ->mapWithKeys(fn (Contact $c): array => [$c->id => trim($c->first_name.' '.$c->last_name)]);

        return $tasks->map(fn (Task $t): array => ['id' => $t->ulid, 'title' => $t->title, 'type' => $t->type, 'priority' => $t->priority,
            'due' => $t->due_at->toIso8601String(), 'overdue' => $t->due_at->isPast(), 'contact' => $t->contact_id !== null ? ($names[$t->contact_id] ?? null) : null])->values()->all();
    }
}
