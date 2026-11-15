<?php

declare(strict_types=1);

namespace Modules\Core\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;

/**
 * The person's day in one call (specs/connect/03): each module adds its section (who to call, tasks, new leads,
 * deal deadlines), with the same access rules as the screens. A section that fails is left out, never the brief.
 */
#[Description('Your day in RightAlly in one call: who to call today, overdue and due tasks, new leads, and deal deadlines this week. Use it for "what should I do today?".')]
#[IsReadOnly]
final class DailyBrief extends RightAllyTool
{
    protected string $name = 'daily_brief';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $brief = ['for' => $user->first_name, 'date' => now()->toDateString()];
        foreach (app(McpToolbox::class)->briefs() as $name => $section) {
            try {
                $brief[$name] = $section($user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $brief;
    }
}
