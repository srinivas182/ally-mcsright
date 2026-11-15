<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Ailly\AillySettings;
use Modules\Sites\Ailly\Assistant;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\SiteContext;
use Modules\Sites\Visitors\SiteLeads;

/**
 * AIlly (specs/websites/11): the website chat endpoints (JSON, no cookies) and Settings → AIlly for owners and staff.
 */
final class AillyController
{
    public function __construct(private readonly Assistant $ailly) {}

    public function start(Request $request): JsonResponse
    {
        $site = self::site();
        if ($site === null || ! RateLimiter::attempt('ailly-start:'.$request->ip(), 10, fn (): bool => true)) {
            return response()->json(['ok' => false], 429);
        }
        try {
            return response()->json(['ok' => true] + $this->ailly->start($site, $request));
        } catch (ValidationException) {
            return response()->json(['ok' => false]);
        }
    }

    public function message(Request $request): JsonResponse
    {
        $site = self::site();
        $data = $request->validate(['conversation' => ['required', 'string', 'size:26'], 'message' => ['required', 'string', 'max:800']]);
        if ($site === null || ! RateLimiter::attempt('ailly-msg:'.$request->ip(), 20, fn (): bool => true)) {
            return response()->json(['ok' => false, 'reply' => 'Too many messages at once. Please wait a moment.'], 429);
        }

        return response()->json(['ok' => true] + $this->ailly->reply($site, $data['conversation'], $data['message'], $request));
    }

    public function contact(Request $request, SiteLeads $leads): JsonResponse
    {
        $site = self::site();
        $data = $request->validate(['conversation' => ['required', 'string', 'size:26'], 'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:190'], 'phone' => ['nullable', 'required_without:email', 'string', 'max:30'],
            'sms_consent' => ['nullable', 'boolean'], 'want' => ['nullable', 'in:call,showing'], 'when' => ['nullable', 'string', 'max:120'],
            'listing' => ['nullable', 'string', 'size:26'], 'intent' => ['nullable', 'in:buy,sell,rent,invest']]);
        if ($site === null || Assistant::isBot($request) || ! RateLimiter::attempt('ailly-contact:'.$request->ip(), 6, fn (): bool => true)) {
            return response()->json(['ok' => false], 429);
        }
        $this->ailly->capture($site, $data['conversation'], $data, $request, $leads);

        return response()->json(['ok' => true]);
    }

    /** Settings → AIlly: switch it on, its name and greeting, when it asks for contact details, and limits. */
    public function settings(Request $request, TenantContext $tenants, MediaLibrary $media): Response
    {
        self::manager($request);
        $site = Site::query()->where('kind', 'brokerage')->first();
        $recent = DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $tenants->id())->orderByDesc('id')->limit(30)->get();
        $messages = DB::connection(Connections::cell())->table('ailly_messages')->whereIn('conversation_id', $recent->pluck('id'))->orderBy('id')->get()->groupBy('conversation_id');
        $since = CarbonImmutable::now()->startOfMonth();
        $month = DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $tenants->id())->where('created_at', '>=', $since);

        return Inertia::render('settings/ailly', [
            'site' => $site !== null ? ['live' => $site->status === 'live', 'name' => $site->displayName()] : null,
            'settings' => AillySettings::for($site),
            'photo_url' => $site !== null ? AillySettings::persona($site, $site)['photo'] : null,
            'site_ulid' => $site?->ulid,
            'media' => $site !== null ? PageEditorController::mediaList($site, $media) : [],
            'month' => ['conversations' => (clone $month)->count(), 'leads' => (clone $month)->where('outcome', '!=', 'none')->count(),
                'calls' => (clone $month)->where('outcome', 'call')->count(), 'answers' => (int) (clone $month)->sum('ai_replies'),
                'free_answers' => (int) (clone $month)->sum('cached_replies')],
            'conversations' => $recent->map(fn (object $c): array => ['ulid' => (string) $c->ulid, 'at' => (string) $c->created_at, 'page' => (string) ($c->page ?? ''),
                'outcome' => (string) $c->outcome, 'score' => $c->lead_score !== null ? (int) $c->lead_score : null, 'answers' => (int) $c->ai_replies,
                'messages' => array_values(array_map(fn (\stdClass $m): array => ['role' => (string) $m->role, 'body' => (string) $m->body],
                    array_filter(($messages->get($c->id) ?? collect())->all(), fn ($m): bool => $m instanceof \stdClass)))])->values(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'name' => ['required', 'string', 'max:30'], 'photo' => ['nullable', 'string', 'size:26'],
            'greeting' => ['nullable', 'string', 'max:240'],
            'gate' => ['required', 'in:before,after,booking'], 'gate_after' => ['required', 'integer', 'min:1', 'max:10'],
            'visitor_daily' => ['required', 'integer', 'min:5', 'max:100'], 'daily_cap' => ['required', 'integer', 'min:10', 'max:10000']]);
        $site = Site::query()->where('kind', 'brokerage')->first() ?? throw ValidationException::withMessages(['enabled' => 'Set up your website first.']);
        AillySettings::save($site, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['enabled'] ? 'AIlly is on your website.' : 'AIlly is off.']);

        return back();
    }

    private static function site(): ?Site
    {
        return app(SiteContext::class)->agent() ?? Assistant::brokerageSite();
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff], true), 403);

        return $user;
    }
}
