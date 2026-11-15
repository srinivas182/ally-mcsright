<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Social\SocialPosts;
use Modules\Ads\Studio\Autopilot;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\QueuedPost;
use Modules\Ads\Studio\StudioPlans;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;

/** Ally Studio → Autopilot (specs/grow/13, SA2): the settings, and planned posts to approve, change or skip. */
final class AutopilotController
{
    public function __construct(private readonly Autopilot $autopilot) {}

    public function index(Request $request, Designer $designer, TenantContext $tenants): Response
    {
        $user = self::user($request);
        $tz = $tenants->current()->timezone ?: 'America/New_York';

        return Inertia::render('studio/autopilot', [
            'ready' => app(StudioPlans::class)->current($user) !== null,
            'settings' => $this->autopilot->settings($user),
            'modes' => Autopilot::MODES,
            'kinds' => Autopilot::KINDS,
            'places' => array_map(fn (array $p): array => ['key' => $p['key'], 'label' => SocialPosts::CHANNELS[$p['channel']].': '.$p['name']], app(SocialPosts::class)->places($user)),
            'timezone' => $tz,
            'queue' => QueuedPost::query()->where('user_id', $user->id)->whereNotIn('status', ['skipped'])->orderByRaw("case status when 'awaiting' then 0 when 'designing' then 1 else 2 end")
                ->latest('id')->limit(40)->get()->map(function (QueuedPost $q) use ($designer, $tz): array {
                    $d = $q->design_id !== null ? Design::query()->find($q->design_id) : null;

                    return ['ulid' => $q->ulid, 'moment' => Designer::MOMENTS[$q->moment][0] ?? $q->moment, 'status' => $q->status, 'image' => $d !== null ? $designer->url($d, 'portrait') : null,
                        'caption' => $this->autopilot->text($q), 'post_at' => $q->post_at?->setTimezone($tz)->format('Y-m-d\TH:i'), 'reason' => $q->reason];
                })->values()->all(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $this->autopilot->save(self::user($request), $request->only(['mode', 'places', 'times', 'per_week', 'moments']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Autopilot saved.']);

        return back();
    }

    public function approve(Request $request, string $item): RedirectResponse
    {
        $user = self::user($request);
        $this->autopilot->approve(QueuedPost::query()->where('ulid', $item)->where('user_id', $user->id)->firstOrFail(), $user, $request->only(['caption', 'post_at']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Scheduled.']);

        return back();
    }

    public function skip(Request $request, string $item): RedirectResponse
    {
        $user = self::user($request);
        $this->autopilot->skip(QueuedPost::query()->where('ulid', $item)->where('user_id', $user->id)->firstOrFail(), $user);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
