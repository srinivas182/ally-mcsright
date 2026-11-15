<?php

declare(strict_types=1);

namespace Modules\Connect\OAuth;

use Illuminate\Validation\ValidationException;

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

    public static function redirectUri(): string
    {
        return 'https://'.config('core.sso_host').'/connect/oauth/callback';
    }
}
