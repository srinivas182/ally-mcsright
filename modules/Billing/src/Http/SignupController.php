<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Affiliates\Affiliates;
use Modules\Billing\HealthCheck\HealthChecks;
use Modules\Billing\Signup\Signups;
use Modules\Core\Security\BotProtection;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Host;
use Modules\Listings\Idx\Coverage;

/**
 * Public prices and self-serve signup on the RightAlly host (specs/billing/07). The pricing page is indexable with
 * offer markup; the welcome page is private and shows the new brokerage's address once it is live.
 */
final class SignupController
{
    public function store(Request $request, Signups $signups): RedirectResponse
    {
        abort_if(filled($request->input('fax')), 422);
        if (! app(BotProtection::class)->passes($request)) { // TR1
            throw ValidationException::withMessages(['bot' => 'Please complete the check that shows you are not a robot, then try again.']);
        }
        $data = $request->validate([
            'brokerage' => ['required', 'string', 'max:150'], 'slug' => ['required', 'string', 'max:40'], 'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email:rfc', 'max:190'], 'state' => ['nullable', 'string', 'size:2', 'alpha'], 'agents' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'package' => ['required', 'string', 'size:26'], 'interval' => ['required', 'in:month,year'], 'terms' => ['accepted'], 'check' => ['nullable', 'string', 'size:40'],
            'boards' => ['nullable', 'array', 'max:20'], 'boards.*' => ['string', 'size:26'],
        ], ['terms.accepted' => 'Please agree to the terms to continue.']);
        $check = isset($data['check']) ? HealthChecks::byToken($data['check']) : null;

        return redirect()->away($signups->begin([
            'brokerage' => (string) $data['brokerage'], 'slug' => (string) $data['slug'], 'owner_name' => (string) $data['owner_name'], 'owner_email' => (string) $data['owner_email'],
            'state' => $data['state'] ?? null, 'agents' => isset($data['agents']) ? (int) $data['agents'] : null, 'package' => (string) $data['package'], 'interval' => (string) $data['interval'],
            'health_check_id' => $check !== null ? (int) $check->id : null, 'ref' => is_string($request->cookie(Affiliates::COOKIE)) ? $request->cookie(Affiliates::COOKIE) : null,
            'boards' => array_values($data['boards'] ?? []),
        ], 'https://'.config('core.hq_host')));
    }

    public function storeAgent(Request $request, Signups $signups): RedirectResponse
    {
        abort_if(filled($request->input('fax')), 422);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'business' => ['nullable', 'string', 'max:150'], 'slug' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email:rfc', 'max:190'], 'state' => ['nullable', 'string', 'size:2', 'alpha'],
            'package' => ['required', 'string', 'size:26'], 'interval' => ['required', 'in:month,year'], 'terms' => ['accepted'],
            'boards' => ['nullable', 'array', 'max:20'], 'boards.*' => ['string', 'size:26'],
        ], ['terms.accepted' => 'Please agree to the terms to continue.']);
        $name = trim((string) $data['name']);

        return redirect()->away($signups->begin([
            'brokerage' => trim((string) ($data['business'] ?? '')) !== '' ? trim((string) $data['business']) : $name, 'slug' => (string) $data['slug'], 'owner_name' => $name,
            'owner_email' => (string) $data['email'], 'state' => $data['state'] ?? null, 'agents' => 1, 'package' => (string) $data['package'], 'interval' => (string) $data['interval'],
            'ref' => is_string($request->cookie(Affiliates::COOKIE)) ? $request->cookie(Affiliates::COOKIE) : null,
            'boards' => array_values($data['boards'] ?? []),
        ], 'https://'.config('core.hq_host'), 'solo'));
    }

    /**
     * The boards and states the signup offers (SU2: shared by both signup pages).
     *
     * @return array{idxStates: array<string, string>, idxBoards: list<array<string, mixed>>, signupStates: array<string, string>}
     */
    public static function boardsAndStates(): array
    {
        return self::boards() + ['signupStates' => self::states()];
    }

    /**
     * IDX7: the MLS boards offered at sign-up (open states, public, priced), by state. Never connector names.
     *
     * @return array{idxStates: array<string, string>, idxBoards: list<array<string, mixed>>}
     */
    private static function boards(): array
    {
        if (! class_exists(Coverage::class)) {
            return ['idxStates' => [], 'idxBoards' => []];
        }
        try {
            $coverage = app(Coverage::class);
            $open = $coverage->enabledStates();
            $boards = $coverage->offered($open);
            // SU3: a state only shows when at least one board there can be chosen; without a board there is no IDX.
            $withBoards = [];
            foreach ($boards as $b) {
                $withBoards = array_merge($withBoards, $b['states']);
            }
            $names = collect($coverage->states())->pluck('name', 'code')->only($withBoards)->sort()->all();

            return ['idxStates' => $names, 'idxBoards' => $boards];
        } catch (\Throwable) {
            return ['idxStates' => [], 'idxBoards' => []]; // before the IDX tables exist
        }
    }

    /** Whether an address is free, while the broker types it. */
    public function address(Request $request): JsonResponse
    {
        $slug = mb_strtolower(trim((string) $request->query('slug', '')));
        $email = is_string($request->query('email')) ? $request->query('email') : null;
        $problem = Signups::slugProblem($slug, $email);

        return response()->json(['ok' => $problem === null, 'message' => $problem, 'host' => Signups::websiteHost($slug),
            'suggestion' => $problem !== null && $slug !== '' ? Signups::freeNear($slug, $email) : null]);
    }

    /** SU1: "Stop these emails" in a signup reminder. */
    public function stopReminders(string $signup): HttpResponse
    {
        DB::connection(Connections::control())->table('signups')->where('ulid', $signup)->update(['no_reminders' => true, 'updated_at' => now()]);

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Reminders stopped</title>'
            .'<body style="font:18px/1.5 system-ui;max-width:36rem;margin:3rem auto;padding:0 1rem"><h1>Done</h1><p>We won\'t send you any more reminders about signing up.</p>'
            .'<p><a href="https://'.e((string) config('core.hq_host')).'/start">Sign up any time</a></p></body>', 200, ['X-Robots-Tag' => 'noindex']);
    }

    /**
     * SU1: the states to choose from at sign-up: the ones RightAlly works in (HQ › IDX coverage › States), or every
     * state while none is open yet.
     *
     * @return array<string, string>
     */
    private static function states(): array
    {
        try {
            $all = collect(app(Coverage::class)->states());
            $open = $all->where('enabled', true);

            return ($open->isEmpty() ? $all : $open)->pluck('name', 'code')->map(fn ($n): string => (string) $n)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** QW1: "Send it again" on the welcome page, for an owner whose sign-in email did not arrive. */
    public function resend(string $signup, Signups $signups): RedirectResponse
    {
        $row = Signups::find($signup) ?? abort(404);
        $result = $signups->resendWelcome($row);

        return redirect()->route('signup.welcome', $signup)->with('resent', $result);
    }

    /** EM5: the welcome page asks whether setup has finished, so it can move on without reloading. */
    public function welcomeStatus(string $signup): JsonResponse
    {
        $row = Signups::find($signup) ?? abort(404);

        return response()->json(['status' => (string) $row->status])->header('Cache-Control', 'no-store');
    }

    public function welcome(string $signup): HttpResponse
    {
        $row = Signups::find($signup) ?? abort(404);
        $response = response()->view('billing::signup.welcome', ['s' => $row, 'host' => Host::platformHostFor((string) $row->slug), 'website' => Signups::websiteHost((string) $row->slug),
            'signer' => (array) config('core.welcome_signer')]);
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store']);

        return $response;
    }
}
