<?php

declare(strict_types=1);

namespace Modules\Ads\Mcp;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioResults;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;

#[Description('Ally Studio results for this person: a month of their social posts (default this month) — posts, people reached, likes/comments/shares/saves, clicks, leads from post links, what works by format, style and time of day, the top posts, and what Autopilot has learned.')]
#[IsReadOnly]
final class StudioResultsTool extends RightAllyTool
{
    protected string $name = 'studio_results';

    public function schema(JsonSchema $schema): array
    {
        return ['month' => $schema->string()->description('The month, as YYYY-MM. Leave out for this month.')];
    }

    protected function allows(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true);
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        if (app(StudioPlans::class)->current($user) === null) {
            return ['studio' => false, 'note' => 'Ally Studio is an add-on. Offer the packages at /studio.'];
        }
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->get('month')) === 1 ? (string) $request->get('month') : CarbonImmutable::now()->format('Y-m');

        return app(StudioResults::class)->month($user, $month) + ['learned' => app(StudioResults::class)->learned($user), 'page' => '/studio/results?month='.$month];
    }
}
