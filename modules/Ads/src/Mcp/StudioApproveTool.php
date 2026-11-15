<?php

declare(strict_types=1);

namespace Modules\Ads\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Modules\Ads\Studio\Autopilot;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\QueuedPost;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;

#[Description('Approve one Ally Studio post that is waiting (use studio_posts for its id), optionally with new words or a new local time like 2026-06-02T09:00. It is then scheduled on their connected places. Shows the post first.')]
final class StudioApproveTool extends RightAllyTool
{
    protected string $name = 'studio_approve';

    protected bool $writes = true;

    protected bool $confirmFirst = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The post id from studio_posts')->required(),
            'caption' => $schema->string()->description('New words, if the person changed them'),
            'when' => $schema->string()->description('New local date and time, if the person changed it'),
        ];
    }

    protected function allows(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true);
    }

    /** @return array<mixed> */
    protected function preview(Request $request, User $user): array
    {
        $q = $this->item($request, $user);

        return ['action' => 'Schedule this Ally Studio post', 'about' => Designer::MOMENTS[$q->moment][0] ?? $q->moment,
            'when' => (string) ($request->get('when') ?: $q->post_at?->toIso8601String()), 'words' => (string) ($request->get('caption') ?: app(Autopilot::class)->text($q))];
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $q = app(Autopilot::class)->approve($this->item($request, $user), $user, ['caption' => (string) $request->get('caption', ''), 'post_at' => (string) $request->get('when', '')]);

        return ['scheduled_for' => $q->post_at?->toIso8601String(), 'status' => $q->status, 'page' => '/studio/autopilot'];
    }

    private function item(Request $request, User $user): QueuedPost
    {
        return QueuedPost::query()->where('ulid', (string) $request->get('id'))->where('user_id', $user->id)->where('status', 'awaiting')->firstOrFail();
    }
}
