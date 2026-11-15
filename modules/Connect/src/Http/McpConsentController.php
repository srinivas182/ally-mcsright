<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;
use Modules\Connect\Mcp\AiAssistants;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plain "Allow" screen an AI assistant sends people to (specs/connect/03), and the owner's and each person's
 * AI assistant controls.
 */
final class McpConsentController
{
    /** @param array<string, mixed> $p Passport's authorisation parameters */
    public static function render(array $p): Response
    {
        $client = $p['client'] ?? null;
        $scopes = array_values(array_filter((array) ($p['scopes'] ?? []), fn ($s): bool => $s instanceof Scope));
        $uris = $client instanceof Client ? (array) $client->getAttribute('redirect_uris') : [];
        $request = request();
        $redirect = is_string($request->query('redirect_uri')) ? (string) $request->query('redirect_uri') : (string) ($uris[0] ?? '');

        return Inertia::render('mcp/consent', [
            'client' => $client instanceof Client ? (string) $client->getAttribute('name') : 'An AI assistant',
            'returns_to' => (string) (parse_url($redirect, PHP_URL_HOST) ?: $redirect),
            'scopes' => array_map(fn (Scope $s): array => ['id' => $s->id, 'description' => $s->description], $scopes),
            'auth_token' => (string) ($p['authToken'] ?? ''),
            'csrf' => csrf_token(),
            'allowed' => app(AiAssistants::class)->allowed(self::user($request)),
        ])->toResponse($request);
    }

    public function settings(Request $request, AiAssistants $assistants): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'allow_writes' => ['required', 'boolean']]);
        $assistants->save((bool) $data['enabled'], (bool) $data['allow_writes'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['enabled'] ? 'Saved.' : 'AI assistant access is off. Every connected assistant was disconnected.']);

        return back();
    }

    public function disconnect(Request $request, AiAssistants $assistants): RedirectResponse
    {
        $data = $request->validate(['user' => ['required', 'string', 'size:26'], 'client' => ['required', 'string', 'max:64']]);
        $assistants->disconnect(self::user($request), $data['user'], $data['client']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Disconnected. It can no longer see or change anything.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
