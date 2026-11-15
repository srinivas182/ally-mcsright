<?php

declare(strict_types=1);

namespace Modules\Connect\OAuth;

use Illuminate\Validation\ValidationException;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;

/**
 * The sign-in services behind one-click apps (specs/connect/02). Client IDs and secrets live only in the server's
 * environment (services.google, services.microsoft: the same apps as "Sign in with Google/Microsoft").
 * One redirect address for every brokerage: https://{core.sso_host}/connect/oauth/callback.
 */
final class Providers
{
    /** @var array<string, array{authorize: string, token: string, userinfo: string, revoke: string|null, params: array<string, string>, base_scopes: list<string>}> */
    public const ALL = [
        'google' => ['authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
            'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo', 'revoke' => 'https://oauth2.googleapis.com/revoke',
            'params' => ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'], 'base_scopes' => ['openid', 'email']],
        'microsoft' => ['authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'userinfo' => 'https://graph.microsoft.com/v1.0/me', 'revoke' => null,
            'params' => ['prompt' => 'select_account'], 'base_scopes' => ['openid', 'email', 'offline_access', 'User.Read']],
    ];

    public static function configured(string $provider): bool
    {
        return isset(self::ALL[$provider]) && (string) config("services.{$provider}.client_id") !== '' && (string) config("services.{$provider}.client_secret") !== '';
    }

    /** @return array{authorize: string, token: string, userinfo: string, revoke: string|null, params: array<string, string>, base_scopes: list<string>, client_id: string, client_secret: string} */
    public static function get(string $provider): array
    {
        if (! self::configured($provider)) {
            throw ValidationException::withMessages(['app' => 'This app isn\'t switched on yet. Please ask RightAlly support.']);
        }

        return self::ALL[$provider] + ['client_id' => (string) config("services.{$provider}.client_id"), 'client_secret' => (string) config("services.{$provider}.client_secret")];
    }

    /**
     * Everything a one-click flow needs for one app: the shared Google/Microsoft settings, or the vendor's own
     * (endpoints in the app's definition; client ID, secret and any partner headers from HQ's keys).
     *
     * @return array{authorize: string, token: string, userinfo: string, revoke: string|null, params: array<string, string>, base_scopes: list<string>, client_id: string, client_secret: string, response_type: string, pkce: bool, token_auth: string, token_state: bool, headers: array<string, string>}
     */
    public static function forApp(App $app): array
    {
        $o = $app->oauth ?? throw ValidationException::withMessages(['app' => $app->name.' has no one-click sign-in.']);
        if (isset(self::ALL[$o['provider']])) {
            return self::get($o['provider']) + ['response_type' => 'code', 'pkce' => true, 'token_auth' => 'post', 'token_state' => false, 'headers' => []];
        }
        if (($o['authorize'] ?? '') === '' || ($o['token'] ?? '') === '') {
            throw ValidationException::withMessages(['app' => $app->name.' has no one-click sign-in.']);
        }
        $source = is_string($o['keys_from'] ?? null) ? app(AppCatalog::class)->find($o['keys_from']) : null;
        $keys = app(AppRegistry::class)->secrets($source ?? $app); // e.g. Propertybase uses the Salesforce app's keys
        if (($keys['client_id'] ?? '') === '' || ($keys['client_secret'] ?? '') === '') {
            throw ValidationException::withMessages(['app' => 'This app isn\'t switched on yet. Please ask RightAlly support.']);
        }
        $headers = [];
        foreach ((array) ($o['headers'] ?? []) as $header => $key) {
            $headers[(string) $header] = (string) ($keys[(string) $key] ?? '');
        }

        return ['authorize' => $o['authorize'], 'token' => $o['token'], 'userinfo' => (string) ($o['userinfo'] ?? ''), 'revoke' => null,
            'params' => array_map('strval', (array) ($o['params'] ?? [])), 'base_scopes' => [], 'client_id' => $keys['client_id'], 'client_secret' => $keys['client_secret'],
            'response_type' => (string) ($o['response_type'] ?? 'code'), 'pkce' => (bool) ($o['pkce'] ?? true), 'token_auth' => (string) ($o['token_auth'] ?? 'post'),
            'token_state' => (bool) ($o['token_state'] ?? false), 'headers' => $headers];
    }

    public static function redirectUri(): string
    {
        return 'https://'.config('core.sso_host').'/connect/oauth/callback';
    }
}
