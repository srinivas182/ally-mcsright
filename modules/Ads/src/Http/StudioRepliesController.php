<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Studio\Replies\StudioReplies;
use Modules\Ads\Studio\Replies\StudioReply;
use Modules\Ads\Studio\StudioPlans;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;

/** Studio → Replies (specs/grow/13, SA5c): comments and messages with drafted replies, to send, change or skip. */
final class StudioRepliesController
{
    public function __construct(private readonly StudioReplies $replies) {}

    public function index(Request $request, StudioPlans $plans, TenantContext $tenants): Response
    {
        $user = self::user($request);
        $tz = $tenants->current()->timezone ?: 'America/New_York';

        return Inertia::render('studio/replies', [
            'ready' => $plans->has($user, 'replies'),
            'settings' => $this->replies->settings($user),
            'items' => StudioReply::query()->where('user_id', $user->id)->whereNotIn('status', ['drafting', 'ignored'])
                ->orderByRaw("case status when 'sensitive' then 0 when 'awaiting' then 1 when 'failed' then 2 else 3 end")->latest('id')->limit(60)->get()
                ->map(fn (StudioReply $r): array => ['ulid' => $r->ulid, 'channel' => $r->channel, 'kind' => $r->kind, 'from' => $r->from_name, 'text' => $r->text,
                    'intent' => $r->intent, 'draft' => $r->draft, 'status' => $r->status, 'reason' => $r->reason, 'lead' => $r->lead_id !== null,
                    'at' => $r->received_at->setTimezone($tz)->format('M j, g:i A'),
                    'expires' => $r->kind === 'message' ? $r->received_at->addHours(24)->setTimezone($tz)->format('M j, g:i A') : null])->values()->all(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $this->replies->save(self::user($request), $request->boolean('on'), (string) $request->input('mode', 'approve'));
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('on') ? 'Replies are on.' : 'Replies are off.']);

        return back();
    }

    public function send(Request $request, string $reply): RedirectResponse
    {
        $user = self::user($request);
        $this->replies->send(StudioReply::query()->where('ulid', $reply)->where('user_id', $user->id)->firstOrFail(), $user, $request->filled('text') ? (string) $request->input('text') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent.']);

        return back();
    }

    public function skip(Request $request, string $reply): RedirectResponse
    {
        $user = self::user($request);
        $this->replies->skip(StudioReply::query()->where('ulid', $reply)->where('user_id', $user->id)->firstOrFail(), $user);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
