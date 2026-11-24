<?php

declare(strict_types=1);

namespace Modules\Recruiting\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\Coverage;
use Modules\Recruiting\SelfJoin;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * CM4: the public join page behind every agent's link (/join/{code}); /join on its own joins with no sponsor.
 */
final class JoinController
{
    public function show(Request $request, SelfJoin $join, ?string $code = null): HttpResponse
    {
        $sponsor = $join->sponsorFor($code);
        $response = Inertia::render('public/join', [
            'brokerage' => app(TenantContext::class)->current()->name,
            'sponsor' => $sponsor?->name,
            'code' => $sponsor !== null ? $code : null,
            'unknownCode' => $code !== null && $sponsor === null,
            'packs' => $join->packs(),
            'states' => self::states(),
            'cancelled' => $request->boolean('cancelled'),
        ])->toResponse($request);
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store']);

        return $response;
    }

    public function store(Request $request, SelfJoin $join, ?string $code = null): RedirectResponse|HttpResponse
    {
        $states = self::states();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'], 'last_name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email:rfc', 'max:160'], 'phone' => ['required', 'string', 'min:7', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'licence_state' => ['required', 'string', 'size:2', 'in:'.implode(',', array_keys($states))], 'licence_number' => ['required', 'string', 'max:40'],
            'pack' => ['nullable', 'string', 'size:26'], 'agree' => ['accepted'],
        ], ['agree.accepted' => 'Please agree to the terms to join.', 'licence_state.in' => 'Choose the state that issued your licence.']);
        $next = $join->apply($data, $join->sponsorFor($code), $states[$data['licence_state']], $request->getSchemeAndHttpHost());

        return str_starts_with($next, '/') ? redirect($next) : Inertia::location($next);
    }

    public function done(Request $request): Response
    {
        return Inertia::render('public/join-done', ['brokerage' => app(TenantContext::class)->current()->name]);
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
