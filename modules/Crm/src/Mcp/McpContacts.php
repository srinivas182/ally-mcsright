<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;

/** Finds a contact for an MCP write tool, with the same rule as the screens: only one the person can see. */
final class McpContacts
{
    public static function visible(User $user, mixed $id, string $field = 'contact_id'): Contact
    {
        $c = is_string($id) && strlen($id) === 26 ? Contact::query()->where('ulid', $id)->first() : null;
        if ($c === null || ! app(ContactVisibility::class)->canSee($user, $c)) {
            throw ValidationException::withMessages([$field => 'No contact with that id that you can see. Use search_contacts to find it.']);
        }

        return $c;
    }
}
