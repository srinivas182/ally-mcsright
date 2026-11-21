<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Affiliates\Affiliates;
use Modules\Billing\HealthCheck\HealthChecks;
use Modules\Billing\Models\Package;
use Modules\Billing\ProductFeatures;
use Modules\Billing\Signup\Signups;
use Modules\Core\Security\BotProtection;
use Modules\Core\Tenancy\Host;
use Modules\Listings\Idx\Coverage;

/**
 * Public prices and self-serve signup on the RightAlly host (specs/billing/07). The pricing page is indexable with
 * offer markup; the welcome page is private and shows the new brokerage's address once it is live.
 */
final class SignupController
{
    public function start(Request $request, Signups $signups): HttpResponse
    {
        $check = is_string($request->query('check')) ? HealthChecks::byToken($request->query('check')) : null;

        return response()->view('billing::signup.start', [
            'packages' => Package::offered('brokerage'),
            'features' => ProductFeatures::all(),
            'open' => $signups->open('brokerage'),
            'cancelled' => $request->boolean('cancelled'),
            'prefill' => $check === null ? [] : ['brokerage' => (string) $check->brokerage, 'owner_name' => (string) $check->contact_name, 'owner_email' => (string) $check->email,
                'state' => (string) ($check->state ?? ''), 'agents' => (string) $check->agents, 'slug' => Signups::suggest((string) $check->brokerage), 'check' => (string) $request->query('check')],
            'canonical' => 'https://'.config('core.hq_host').'/start',
            // SG1: a package chosen on the RightAlly website's price page arrives already selected.
            'chosen' => is_string($request->query('package')) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $request->query('package')) === 1 ? $request->query('package') : null,
        ] + self::boards());
    }

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

    /** RightAlly for Agents: solo prices and signup (specs/grow/03). */
    public function agents(Request $request, Signups $signups): HttpResponse
    {
        return response()->view('billing::signup.agents', [
            'packages' => Package::offered('agent'), 'features' => ProductFeatures::all(), 'open' => $signups->open('agent'), 'cancelled' => $request->boolean('cancelled'),
            'canonical' => 'https://'.config('core.hq_host').'/agents',
        ] + self::boards());
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
            $names = collect($coverage->states())->pluck('name', 'code')->only($open)->all();

            return ['idxStates' => $names, 'idxBoards' => $coverage->offered($open)];
        } catch (\Throwable) {
            return ['idxStates' => [], 'idxBoards' => []]; // before the IDX tables exist
        }
    }

    /** Whether an address is free, while the broker types it. */
    public function address(Request $request): JsonResponse
    {
        $slug = mb_strtolower(trim((string) $request->query('slug', '')));
        $problem = Signups::slugProblem($slug);

        return response()->json(['ok' => $problem === null, 'message' => $problem, 'host' => Host::platformHostFor($slug)]);
    }

    public function welcome(string $signup): HttpResponse
    {
        $row = Signups::find($signup) ?? abort(404);
        $response = response()->view('billing::signup.welcome', ['s' => $row, 'host' => Host::platformHostFor((string) $row->slug)]);
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store']);

        return $response;
    }
}
