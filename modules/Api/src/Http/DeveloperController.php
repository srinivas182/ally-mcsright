<?php

declare(strict_types=1);

namespace Modules\Api\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Api\Keys;
use Modules\Api\OpenApi;
use Modules\Api\PartnerApps;
use Modules\Api\Scopes;
use Modules\Core\Identity\Enums\UserKind;

/** Settings › API and partner apps (API1): personal API keys, and which partner apps the brokerage allows. */
final class DeveloperController
{
    public function show(Request $request, Keys $keys, PartnerApps $apps): Response
    {
        $user = self::user($request);

        return Inertia::render('api/developer', ['keys' => $keys->list($user), 'scopes' => Scopes::ALL, 'new_key' => $request->session()->get('newKey'),
            'apps' => $apps->catalog(), 'owner' => $user->kind === UserKind::Owner, 'leader' => in_array($user->kind, [UserKind::Owner, UserKind::Staff], true),
            'base' => url('/api/v1')]);
    }

    public function create(Request $request, Keys $keys): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['string'], 'expires' => ['nullable', 'date', 'after:today']]);
        $made = $keys->create(self::user($request), $data['name'], array_values($data['scopes']), $data['expires'] ?? null);

        return back()->with('newKey', $made['key']);
    }

    public function revoke(Request $request, string $key, Keys $keys): RedirectResponse
    {
        $keys->revoke($key, self::user($request));

        return back();
    }

    public function allow(Request $request, string $client, PartnerApps $apps): RedirectResponse
    {
        $data = $request->validate(['allowed' => ['required', 'boolean']]);
        $apps->setAllowed($client, (bool) $data['allowed'], self::user($request));

        return back();
    }

    public function openapi(): JsonResponse
    {
        return response()->json(OpenApi::document(url('/api/v1')))->header('Cache-Control', 'public, max-age=3600');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
