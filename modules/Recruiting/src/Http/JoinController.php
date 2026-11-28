<?php

declare(strict_types=1);

namespace Modules\Recruiting\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\Coverage;
use Modules\Recruiting\MyNetwork;
use Modules\Recruiting\SelfJoin;
use Modules\Roster\Brokerage\BrokerageLicences;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * CM4: the public join page behind every agent's link (/join/{code}); /join on its own joins with no sponsor.
 */
final class JoinController
{
    public function show(Request $request, SelfJoin $join, ?string $code = null): HttpResponse
    {
        $sponsor = $join->sponsorFor($code);
        if ($sponsor !== null && ! $request->session()->has('join_opened.'.$code)) {
            $request->session()->put('join_opened.'.$code, true); // AG1: counted once per visitor session
            MyNetwork::opened($sponsor);
        }
        $page = $join->page();
        $response = Inertia::render('public/join', [
            'brokerage' => app(TenantContext::class)->current()->name,
            'sponsor' => $sponsor !== null ? ['name' => $sponsor->name, 'since' => $sponsor->created_at?->format('Y')] : null,
            // FX3: with no sponsor, the new agent joins under the company's root account.
            'root' => $join->rootReferrer()?->name,
            'suggested' => $sponsor !== null && $request->query('via') === 'page', // FX4: from the agent page they browsed
            'page' => ['headline' => $page['headline'], 'pitch' => $page['pitch'], 'reasons' => $page['reasons'], 'video' => SelfJoin::player($page['video'])],
            'code' => $sponsor !== null ? $code : null,
            'unknownCode' => $code !== null && $sponsor === null,
            'packs' => $join->packs(),
            'plans' => $join->plans(),
            'states' => $join->states(self::states()),
            'cancelled' => $request->boolean('cancelled'),
        ])->toResponse($request);
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store']);

        return $response;
    }

    public function store(Request $request, SelfJoin $join, ?string $code = null): RedirectResponse|HttpResponse
    {
        $states = $join->states(self::states());
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'], 'last_name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email:rfc,filter', 'max:160'], 'phone' => ['required', 'regex:/^\+1[2-9]\d{9}$/'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'licence_state' => ['required', 'string', 'size:2', 'in:'.implode(',', array_keys($states))], 'licence_number' => ['required', 'string', 'max:40'],
            'pack' => ['nullable', 'string', 'size:26'], 'plan' => ['nullable', 'string', 'size:26'], 'agree' => ['accepted'],
            'referral' => ['nullable', 'string', 'max:200'],
        ], ['agree.accepted' => 'Please agree to the terms to join.', 'phone.regex' => 'Enter a 10-digit US phone number.', 'email.email' => 'Enter a full email address, like name@example.com.', 'licence_state.in' => 'Choose the state that issued your licence.']);
        // FX3: a referral code typed on the form (or the agent's link pasted) names the sponsor when the link had none.
        $typed = SelfJoin::codeFromInput($data['referral'] ?? null);
        $sponsor = $join->sponsorFor($typed ?? $code);
        if ($typed !== null && $sponsor === null) {
            throw ValidationException::withMessages(['referral' => 'We could not find an agent with that referral code. Check it with them, or leave it empty to join under '.app(TenantContext::class)->current()->name.'.']);
        }
        unset($data['referral']);
        $next = $join->apply($data, $sponsor, $states[$data['licence_state']], $request->getSchemeAndHttpHost());

        return str_starts_with($next, '/') ? redirect($next) : Inertia::location($next);
    }

    /**
     * FX4: "Change" on Referred by: finds an agent of this brokerage by their mobile phone, email or referral code.
     * Answers only with the name (agents are public on the website) and the code to join under; nothing else.
     */
    public function lookup(Request $request, SelfJoin $join): JsonResponse
    {
        $q = trim((string) $request->validate(['contact' => ['required', 'string', 'max:200']])['contact']);
        $agent = $join->findReferrer($q);

        return response()->json($agent !== null ? ['found' => true, 'name' => $agent->name, 'code' => $join->codeFor($agent)] : ['found' => false])
            ->header('Cache-Control', 'no-store');
    }

    public function done(Request $request): Response
    {
        return Inertia::render('public/join-done', ['brokerage' => app(TenantContext::class)->current()->name]);
    }

    /** CM4b: the owner's join page (words, video, extra states). */
    public function edit(Request $request, SelfJoin $join): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isKind(UserKind::Owner), 403);

        return Inertia::render('recruiting/join-page', ['page' => $join->page(), 'states' => self::states(), 'plans' => array_column($join->plans(), 'name'),
            'licensed' => app(BrokerageLicences::class)->goodStates(), 'link' => $request->getSchemeAndHttpHost().'/join']);
    }

    public function save(Request $request, SelfJoin $join): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isKind(UserKind::Owner), 403);
        $join->savePage($request->only(['headline', 'pitch', 'reasons', 'video', 'extra_states']), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Join page saved.']);

        return back();
    }

    /**
     * @return array<string, string>
     */
    private static function states(): array
    {
        try {
            return collect(app(Coverage::class)->states())->pluck('name', 'code')->map(fn ($n): string => (string) $n)->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
