<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;

/**
 * Adds a contact as the person (same rules and duplicate check as the Contacts screen). An agent's contacts are
 * their own; an owner's are the brokerage's. Nobody is texted: texting needs the contact's own opt-in.
 */
#[Description('Add a contact or lead to RightAlly. Needs a name and an email or phone. Refuses duplicates and says who already has them. Does not text or email them.')]
final class AddContact extends RightAllyTool
{
    protected string $name = 'add_contact';

    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'first_name' => $schema->string()->description('First name')->required(),
            'last_name' => $schema->string()->description('Last name'),
            'email' => $schema->string()->description('Email address'),
            'phone' => $schema->string()->description('US phone number'),
            'note' => $schema->string()->description('Optional first note, e.g. what they are looking for'),
        ];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $in = $request->validate(['first_name' => ['required', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'string', 'max:254'], 'phone' => ['nullable', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:5000']]);
        $c = app(ManageContacts::class)->create(['first_name' => $in['first_name'], 'last_name' => $in['last_name'] ?? '', 'email' => $in['email'] ?? null,
            'phone' => $in['phone'] ?? null, 'source' => 'manual'], $user);
        if (is_string($in['note'] ?? null) && trim($in['note']) !== '') {
            app(Timeline::class)->log($c, ['type' => 'note', 'body' => $in['note']], $user);
        }

        return ['added' => CrmMcpData::contact($c)];
    }
}
