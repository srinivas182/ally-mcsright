<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\SavedSearch;
use Modules\Crm\Searches\SavedSearches;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteVisitor;
use Modules\Sites\Visitors\SignIn;
use Modules\Sites\Visitors\SiteLeads;
use Modules\Sites\Visitors\Visitors;

/**
 * Website visitor endpoints (specs/websites/07): the journey beacon, passwordless sign-in, and the visitor's own
 * saved homes and searches. JSON only; no cookies (the browser keeps a random id and, once signed in, a token).
 */
final class VisitorController
{
    public function __construct(private readonly Visitors $visitors) {}

    /** The journey beacon (navigator.sendBeacon posts text/plain JSON). Always 204. */
    public function track(Request $request): Response
    {
        $site = self::site();
        $body = json_decode(mb_substr($request->getContent(), 0, 20000), true);
        if ($site === null || ! is_array($body) || ! Visitors::trackable($request) || ! Visitors::validVid($body['vid'] ?? null)
            || ! RateLimiter::attempt('site-track:'.$request->ip(), 240, fn (): bool => true)) {
            return response()->noContent();
        }
        $visitor = $this->visitors->resolve($site, (string) $body['vid'], ['referrer' => is_string($body['ref'] ?? null) ? $body['ref'] : null,
            'landing' => is_string($body['landing'] ?? null) ? $body['landing'] : null]);
        $account = $this->visitors->fromToken(is_string($body['token'] ?? null) ? $body['token'] : null);
        if ($account !== null && $account->id !== $visitor->id) {
            $this->visitors->merge($visitor, $account);
            $visitor = $account;
        }
        $this->visitors->record($visitor, array_values(is_array($body['events'] ?? null) ? $body['events'] : []));

        return response()->noContent();
    }

    public function start(Request $request, SignIn $signIn): JsonResponse
    {
        $site = self::site() ?? abort(404);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $signIn->start($site, $data['email'], (string) $request->ip());

        return response()->json(['ok' => true, 'message' => 'Check your email for a 6-digit code.'], 202);
    }

    /** Checks the code; the first sign-in for an address becomes a CRM lead, and every device's journey is joined. */
    public function verify(Request $request, SignIn $signIn, SiteLeads $leads): JsonResponse
    {
        $site = self::site() ?? abort(404);
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'code' => ['required', 'string', 'max:12'], 'vid' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:30'], 'sms_consent' => ['nullable', 'boolean']]);
        if (! Visitors::validVid($data['vid']) || ! $signIn->verify($data['email'], $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right or has expired. Ask for a new one.']);
        }
        $email = mb_strtolower(trim($data['email']));
        $visitor = $this->visitors->resolve($site, $data['vid']);
        $account = SiteVisitor::query()->where('email', $email)->whereNotNull('verified_at')->whereNull('merged_into')->whereKeyNot($visitor->id)->orderBy('id')->first();
        // VS1: someone who already had an account here: the website greets them by name, only now that the code proved it is them.
        $returning = $account !== null || $visitor->verified_at !== null;
        if ($account !== null) {
            $this->visitors->merge($visitor, $account);
            $visitor = $account;
        } else {
            $visitor->forceFill(['email' => $email, 'name' => mb_substr(trim(strip_tags((string) ($data['name'] ?? ''))), 0, 160) ?: null, 'verified_at' => now()])->save();
        }
        if ($visitor->contact_id === null) {
            $name = trim((string) ($data['name'] ?? $visitor->name ?? '')) ?: ucfirst((string) strtok($email, '@'));
            $leads->capture($site, SiteLeads::source('Website sign-up', 'Create an account'),
                ['name' => $name, 'email' => $email, 'phone' => (string) ($data['phone'] ?? ''), 'intent' => 'buy', 'sms_consent' => (bool) ($data['sms_consent'] ?? false),
                    'message' => 'Created an account on the website.'], $request->merge(['vid' => $data['vid']]), 'signup');
            $visitor = $visitor->refresh();
        }

        return response()->json(['token' => $this->visitors->token($visitor), 'returning' => $returning, ...$this->me($visitor)]);
    }

    public function show(Request $request): JsonResponse
    {
        $visitor = $this->account($request);

        return response()->json($this->me($visitor))->header('Cache-Control', 'no-store');
    }

    public function favourite(Request $request): JsonResponse
    {
        $visitor = $this->account($request);
        $data = $request->validate(['listing' => ['required', 'string', 'size:26'], 'on' => ['required', 'boolean']]);
        $this->visitors->record($visitor, [['type' => $data['on'] ? 'favourite' : 'unfavourite', 'listing' => $data['listing'], 'path' => '/homes']]);

        return response()->json(['favourites' => $this->visitors->favourites($visitor)]);
    }

    /** Saves the current search with daily new-listing and price-drop alerts (the CRM's saved searches). */
    public function saveSearch(Request $request, SavedSearches $searches): JsonResponse
    {
        $visitor = $this->account($request);
        $data = $request->validate(['query' => ['required', 'string', 'max:2500'], 'name' => ['nullable', 'string', 'max:100']]);
        $contact = $visitor->contact_id !== null ? Contact::query()->find($visitor->contact_id) : null;
        abort_if($contact === null, 409, 'Your account is still being set up. Try again in a moment.');
        $filters = Visitors::queryFilters($data['query']);
        $agentId = $contact->owner_user_id ?? Lead::query()->whereKey($visitor->lead_id)->value('assigned_user_id');
        $search = $searches->createForVisitor($contact, $filters, (string) ($data['name'] ?? ''), $agentId !== null ? (int) $agentId : null);
        $this->visitors->note($visitor, 'search', null, ['saved' => $search->name]);

        return response()->json(['ok' => true, 'name' => $search->name]);
    }

    /** IDX10: texts too, for a search this visitor saved (their own number and written consent). */
    public function textSearch(Request $request, SavedSearches $searches): JsonResponse
    {
        $visitor = $this->account($request);
        abort_unless(RateLimiter::attempt('site-texts:'.$visitor->id, 5, fn (): bool => true, 600), 429, 'Too many tries. Wait a few minutes.');
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'phone' => ['required', 'string', 'max:20'], 'agree' => ['accepted']],
            ['agree.accepted' => 'Tick the box to agree to texts.']);
        $contact = $visitor->contact_id !== null ? Contact::query()->find($visitor->contact_id) : null;
        abort_if($contact === null, 409, 'Your account is still being set up. Try again in a moment.');
        $search = SavedSearch::query()->where('contact_id', $contact->id)->where('name', $data['name'])->first();
        abort_if($search === null, 404);
        $searches->enableTexts($contact, $search, (string) $data['phone'], $request);
        $this->visitors->note($visitor, 'search', null, ['texts' => $search->name]);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{name: string|null, email: string|null, favourites: list<string>}
     */
    private function me(SiteVisitor $visitor): array
    {
        return ['name' => $visitor->name, 'email' => $visitor->email, 'favourites' => $this->visitors->favourites($visitor)];
    }

    private function account(Request $request): SiteVisitor
    {
        abort_if(self::site() === null, 404);

        return $this->visitors->fromToken($request->bearerToken()) ?? abort(401);
    }

    private static function site(): ?Site
    {
        return Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
    }
}
