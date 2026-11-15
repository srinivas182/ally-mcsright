<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Models\Task;

/** Adds a task for the person (owners may not assign to others through MCP; the screens do that). */
#[Description('Add a task or follow-up for yourself, optionally on a contact, e.g. "call Maria Friday at 10am". Due accepts a date and time like 2026-11-20 10:00, in your own time zone.')]
final class AddTask extends RightAllyTool
{
    protected string $name = 'add_task';

    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('What needs doing')->required(),
            'due' => $schema->string()->description('When, e.g. 2026-11-20 10:00 (your time zone). or today, tomorrow, next_week. Default: tomorrow 9am'),
            'type' => $schema->string()->enum(Task::TYPES)->description('Kind of task (default follow_up)'),
            'contact_id' => $schema->string()->description('Optional contact id from search_contacts'),
            'high_priority' => $schema->boolean()->description('Mark as high priority'),
        ];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $in = $request->validate(['title' => ['required', 'string', 'max:200'], 'due' => ['nullable', 'string', 'max:40'], 'type' => ['nullable', 'in:'.implode(',', Task::TYPES)],
            'contact_id' => ['nullable', 'string'], 'high_priority' => ['nullable', 'boolean']]);
        $contact = isset($in['contact_id']) ? McpContacts::visible($user, $in['contact_id']) : null;
        $due = trim((string) ($in['due'] ?? ''));
        $quick = $due === '' ? 'tomorrow' : (in_array($due, ['today', 'tomorrow', 'next_week'], true) ? $due : null);
        try {
            $t = app(Tasks::class)->create($contact, ['title' => $in['title'], 'due' => $quick, 'due_at' => $quick === null ? $due : null, 'type' => $in['type'] ?? null,
                'priority' => ($in['high_priority'] ?? false) ? 'high' : 'normal'], $user);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages(['due' => 'Give the due date like 2026-11-20 10:00, or today, tomorrow or next_week.']);
        }

        return ['added' => ['id' => $t->ulid, 'title' => $t->title, 'due' => $t->due_at->toIso8601String(), 'contact' => $contact !== null ? trim($contact->first_name.' '.$contact->last_name) : null]];
    }
}
