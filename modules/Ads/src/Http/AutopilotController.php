<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialPosts;
use Modules\Ads\Studio\Autopilot;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\QueuedPost;
use Modules\Ads\Studio\Reels;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioVideo;
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
            'has_voice' => app(StudioPlans::class)->has($user, 'voice_learning'),
            'usage' => app(StudioPlans::class)->usage($user),
            'rules' => $user->kind === UserKind::Owner ? $this->autopilot->rules() : null,
            'review_required' => $this->autopilot->rules()['require_review'] && $user->kind === UserKind::Agent,
            'queue' => QueuedPost::query()->where('user_id', $user->id)->whereNotIn('status', ['skipped'])->orderByRaw("case status when 'awaiting' then 0 when 'enhancing' then 1 when 'designing' then 1 else 2 end")
                ->latest('id')->limit(40)->get()->map(function (QueuedPost $q) use ($designer, $tz): array {
                    $d = $q->design_id !== null ? Design::query()->find($q->design_id) : null;
                    $v = $q->video_id !== null ? StudioVideo::query()->find($q->video_id) : null;

                    return ['ulid' => $q->ulid, 'moment' => Designer::MOMENTS[$q->moment][0] ?? $q->moment, 'status' => $q->status, 'image' => $v !== null ? app(Reels::class)->poster($v) : ($d !== null ? $designer->url($d, 'portrait') : null), 'reel' => $v !== null,
                        'caption' => $this->autopilot->text($q), 'post_at' => $q->post_at?->setTimezone($tz)->format('Y-m-d\TH:i'), 'reason' => $q->reason,
                        'needs_client_ok' => $q->needs_client_ok];
                })->values()->all(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $this->autopilot->save(self::user($request), $request->only(['mode', 'places', 'times', 'per_week', 'moments', 'learn_voice', 'auto_enhance', 'auto_reels', 'extra_credits']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Autopilot saved.']);

        return back();
    }

    public function approve(Request $request, string $item): RedirectResponse
    {
        $user = self::user($request);
        $this->autopilot->approve(QueuedPost::query()->where('ulid', $item)->where('user_id', $user->id)->firstOrFail(), $user, $request->only(['caption', 'post_at', 'client_ok']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Scheduled.']);

        return back();
    }

    public function skip(Request $request, string $item): RedirectResponse
    {
        $user = self::user($request);
        $this->autopilot->skip(QueuedPost::query()->where('ulid', $item)->where('user_id', $user->id)->firstOrFail(), $user);

        return back();
    }

    public function move(Request $request, string $item): RedirectResponse
    {
        $user = self::user($request);
        $this->autopilot->move(QueuedPost::query()->where('ulid', $item)->where('user_id', $user->id)->firstOrFail(), $user, (string) $request->input('date'));

        return back();
    }

    /** Studio → Calendar: the month's planned and sent posts; Studio's posts can be dragged to another day. */
    public function calendar(Request $request, Designer $designer, TenantContext $tenants): Response
    {
        $user = self::user($request);
        $tz = $tenants->current()->timezone ?: 'America/New_York';
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) === 1 ? CarbonImmutable::parse($request->query('month').'-01', $tz) : CarbonImmutable::now($tz)->startOfMonth();
        [$from, $to] = [$month->startOfMonth()->utc(), $month->endOfMonth()->utc()];
        $studio = QueuedPost::query()->where('user_id', $user->id)->whereIn('status', ['enhancing', 'designing', 'filming', 'awaiting', 'review', 'scheduled'])->whereBetween('post_at', [$from, $to])->get();
        $linked = $studio->pluck('social_post_id')->filter()->all();
        $events = $studio->map(function (QueuedPost $q) use ($designer, $tz): array {
            $d = $q->design_id !== null ? Design::query()->find($q->design_id) : null;

            return ['id' => $q->ulid, 'kind' => 'studio', 'title' => Designer::MOMENTS[$q->moment][0] ?? $q->moment, 'date' => $q->post_at?->setTimezone($tz)->format('Y-m-d'),
                'time' => $q->post_at?->setTimezone($tz)->format('g:i A'), 'status' => $q->status, 'image' => $d !== null ? $designer->url($d, 'square') : null,
                'movable' => $q->post_at !== null && $q->post_at->isFuture()];
        })->concat(SocialPost::query()->where('user_id', $user->id)->whereNotIn('id', $linked)->whereIn('status', ['scheduled', 'posted', 'partial'])->whereBetween('post_at', [$from, $to])->get()
            ->map(fn (SocialPost $p): array => ['id' => $p->ulid, 'kind' => 'post', 'title' => mb_substr(strtok($p->text, "\n") ?: 'Post', 0, 40), 'date' => $p->post_at->setTimezone($tz)->format('Y-m-d'),
                'time' => $p->post_at->setTimezone($tz)->format('g:i A'), 'status' => $p->status, 'image' => $p->image_url, 'movable' => false]))->values()->all();

        return Inertia::render('studio/calendar', ['month' => $month->format('Y-m'), 'label' => $month->format('F Y'), 'first_weekday' => (int) $month->startOfMonth()->dayOfWeek,
            'days' => $month->daysInMonth, 'today' => CarbonImmutable::now($tz)->format('Y-m-d'), 'events' => $events, 'timezone' => $tz]);
    }

    /** Studio → Review (owners and staff, when the brokerage reviews agents' posts). */
    public function reviewList(Request $request, Designer $designer, TenantContext $tenants): Response
    {
        $user = self::user($request);
        abort_unless(in_array($user->kind, [UserKind::Owner, UserKind::Staff], true), 403);
        $tz = $tenants->current()->timezone ?: 'America/New_York';
        $names = User::query()->pluck('first_name', 'id');

        return Inertia::render('studio/review', ['items' => $this->autopilot->forReview()->map(function (QueuedPost $q) use ($designer, $tz, $names): array {
            $d = $q->design_id !== null ? Design::query()->find($q->design_id) : null;
            $v = $q->video_id !== null ? StudioVideo::query()->find($q->video_id) : null;

            return ['ulid' => $q->ulid, 'agent' => (string) ($names[$q->user_id] ?? ''), 'moment' => Designer::MOMENTS[$q->moment][0] ?? $q->moment,
                'image' => $v !== null ? app(Reels::class)->poster($v) : ($d !== null ? $designer->url($d, 'portrait') : null), 'reel' => $v !== null, 'caption' => $this->autopilot->text($q), 'post_at' => $q->post_at?->setTimezone($tz)->format('M j, g:i A')];
        })->values()->all()]);
    }

    public function review(Request $request, string $item): RedirectResponse
    {
        $user = self::user($request);
        $this->autopilot->review(QueuedPost::query()->where('ulid', $item)->firstOrFail(), $user, $request->boolean('ok'), (string) $request->input('why', ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('ok') ? 'Approved and scheduled.' : 'Sent back to the agent.']);

        return back();
    }

    public function saveRules(Request $request): RedirectResponse
    {
        $this->autopilot->saveRules(self::user($request), $request->only(['require_review', 'caption_disclaimer']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Brokerage rules saved.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
