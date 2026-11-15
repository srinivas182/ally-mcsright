<?php

declare(strict_types=1);

namespace Modules\Ads\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Ads\Studio\Autopilot;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\QueuedPost;
use Modules\Ads\Studio\StudioPlans;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;

#[Description('Ally Studio: the posts Autopilot designed and wrote for this person that are waiting for their OK, and what is scheduled next. If they have no Studio package, says so.')]
#[IsReadOnly]
final class StudioQueueTool extends RightAllyTool
{
    protected string $name = 'studio_posts';

    public function schema(JsonSchema $schema): array
    {
        return [];
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
        $row = fn (QueuedPost $q): array => ['id' => $q->ulid, 'about' => Designer::MOMENTS[$q->moment][0] ?? $q->moment, 'when' => $q->post_at?->toIso8601String(),
            'caption' => mb_substr(app(Autopilot::class)->text($q), 0, 400), 'note' => $q->reason];

        return ['waiting_for_ok' => QueuedPost::query()->where('user_id', $user->id)->where('status', 'awaiting')->orderBy('post_at')->limit(10)->get()->map($row)->values()->all(),
            'scheduled' => QueuedPost::query()->where('user_id', $user->id)->where('status', 'scheduled')->where('post_at', '>', now())->orderBy('post_at')->limit(5)->get()->map($row)->values()->all(),
            'page' => '/studio/autopilot'];
    }
}
