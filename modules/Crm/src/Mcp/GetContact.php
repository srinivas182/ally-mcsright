<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;

#[Description('One contact: details, the last 10 activities (calls, texts, emails, notes) and open tasks.')]
#[IsReadOnly]
final class GetContact extends RightAllyTool
{
    protected string $name = 'get_contact';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('The contact id from search_contacts')->required()];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $id = (string) $request->validate(['id' => ['required', 'string', 'size:26']])['id'];
        $c = Contact::query()->where('ulid', $id)->first();
        if ($c === null || ! app(ContactVisibility::class)->canSee($user, $c)) {
            throw ValidationException::withMessages(['id' => 'No contact with that id that you can see.']);
        }

        return CrmMcpData::contact($c) + [
            'activity' => Activity::query()->where('contact_id', $c->id)->latest('occurred_at')->limit(10)->get()
                ->map(fn (Activity $a): array => ['type' => $a->type, 'direction' => $a->direction, 'when' => $a->occurred_at->toIso8601String(),
                    'summary' => mb_substr((string) ($a->subject ?? $a->body ?? ''), 0, 300)])->values()->all(),
            'open_tasks' => Task::query()->where('contact_id', $c->id)->whereNull('completed_at')->orderBy('due_at')->limit(10)->get()
                ->map(fn (Task $t): array => ['id' => $t->ulid, 'title' => $t->title, 'type' => $t->type, 'due' => $t->due_at->toIso8601String()])->values()->all(),
        ];
    }
}
