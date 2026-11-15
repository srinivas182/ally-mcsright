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
use Modules\Crm\Models\Contact;
use Modules\Crm\TodaysTen;

#[Description("Today's 10: the people you should contact today, and why (from your RightAlly database).")]
#[IsReadOnly]
final class TodaysList extends RightAllyTool
{
    protected string $name = 'todays_list';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return list<array<string, mixed>> */
    protected function run(Request $request, User $user): array
    {
        return array_map(function (array $row): array {
            $c = $row['contact'] ?? null;

            return $c instanceof Contact ? CrmMcpData::contact($c) + ['why' => array_values(array_map('strval', (array) ($row['reasons'] ?? [])))] : [];
        }, app(TodaysTen::class)->for($user));
    }
}
