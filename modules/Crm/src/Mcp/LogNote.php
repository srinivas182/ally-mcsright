<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\Actions\Timeline;

/** Logs a note or a finished call on a contact the person can see (same as the contact's timeline). */
#[Description('Log a note, or a call that already happened, on a contact. Use the contact id from search_contacts.')]
final class LogNote extends RightAllyTool
{
    protected string $name = 'log_note';

    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->string()->description('The contact id from search_contacts')->required(),
            'note' => $schema->string()->description('What to write down')->required(),
            'call_outcome' => $schema->string()->enum(['connected', 'left_voicemail', 'no_answer', 'wrong_number'])->description('Only for a call that happened'),
        ];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $in = $request->validate(['contact_id' => ['required', 'string'], 'note' => ['required', 'string', 'max:5000'],
            'call_outcome' => ['nullable', 'in:connected,left_voicemail,no_answer,wrong_number']]);
        $c = McpContacts::visible($user, $in['contact_id']);
        $call = isset($in['call_outcome']);
        $a = app(Timeline::class)->log($c, ['type' => $call ? 'call' : 'note', 'body' => $in['note'], 'outcome' => $in['call_outcome'] ?? null], $user);

        return ['logged' => ['type' => $a->type, 'contact' => trim($c->first_name.' '.$c->last_name), 'when' => $a->occurred_at->toIso8601String()]];
    }
}
