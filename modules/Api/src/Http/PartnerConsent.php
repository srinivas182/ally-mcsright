<?php

declare(strict_types=1);

namespace Modules\Api\Http;

use App\Models\User;
use Inertia\Inertia;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;
use Modules\Api\PartnerApps;
use Modules\Api\Scopes;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "Allow" screen for a partner app (API1): the app, the company behind it, whether RightAlly verified it, and each
 * thing it asks for in plain words. If the brokerage's owner has not allowed the app, it says so and offers no Allow.
 */
final class PartnerConsent
{
    /** @param array<string, mixed> $p */
    public static function render(array $p): Response
    {
        $client = $p['client'] ?? null;
        $clientId = $client instanceof Client ? (string) $client->getKey() : '';
        $app = PartnerApps::find($clientId);
        $request = request();
        $user = $request->user();
        $scopes = array_values(array_filter((array) ($p['scopes'] ?? []), fn ($s): bool => $s instanceof Scope));
        $uris = $client instanceof Client ? (array) $client->getAttribute('redirect_uris') : [];
        $redirect = is_string($request->query('redirect_uri')) ? (string) $request->query('redirect_uri') : (string) ($uris[0] ?? '');

        return Inertia::render('api/consent', [
            'app' => $app !== null ? ['name' => (string) $app->name, 'company' => (string) $app->company, 'website' => (string) $app->website,
                'description' => (string) $app->description, 'verified' => (bool) $app->verified] : null,
            'scopes' => array_map(fn (Scope $s): string => Scopes::ALL[substr($s->id, 4)] ?? $s->description, $scopes),
            'allowed' => $app !== null && $user instanceof User && app(PartnerApps::class)->allowed($clientId),
            'returns_to' => (string) (parse_url($redirect, PHP_URL_HOST) ?: $redirect),
            'auth_token' => (string) ($p['authToken'] ?? ''),
            'csrf' => csrf_token(),
        ])->toResponse($request);
    }

    /** @param array<string, mixed> $p */
    public static function wants(array $p): bool
    {
        foreach ((array) ($p['scopes'] ?? []) as $s) {
            if ($s instanceof Scope && str_starts_with($s->id, 'api:')) {
                return true;
            }
        }

        return false;
    }
}
