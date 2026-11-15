<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;

#[Description('Find contacts in RightAlly by name, email or phone. Only contacts you can see in RightAlly are searched.')]
#[IsReadOnly]
final class SearchContacts extends RightAllyTool
{
    protected string $name = 'search_contacts';

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->description('Part of a name, an email address or a phone number')->required(),
            'limit' => $schema->integer()->description('How many to return (1 to 25, default 10)')];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<int, array<string, mixed>> */
    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['query' => ['required', 'string', 'min:2', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:25']]);
        $q = trim($data['query']);
        $digits = preg_replace('/\D/', '', $q) ?? '';

        return app(ContactVisibility::class)->query($user)
            ->where(fn ($w) => $w->where('search_name', 'like', '%'.mb_strtolower($q).'%')->orWhere('primary_email', 'like', '%'.mb_strtolower($q).'%')
                ->when(strlen($digits) >= 4, fn ($p) => $p->orWhere('primary_phone', 'like', '%'.$digits.'%')))
            ->orderByDesc('last_activity_at')->limit((int) ($data['limit'] ?? 10))->get()
            ->map(fn (Contact $c): array => CrmMcpData::contact($c))->values()->all();
    }
}
