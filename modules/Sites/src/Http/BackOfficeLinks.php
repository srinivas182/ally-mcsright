<?php

declare(strict_types=1);

namespace Modules\Sites\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * DM1: a brokerage's website and back office share its name (acme.rightally.app and acme-office.rightally.app), and a
 * broker who types acme.rightally.app/backoffice, /login, /admin or /office, or follows an old link into the back
 * office (/settings/…, /invitations/…), is sent to the back office. The same works on the brokerage's own domain
 * (mcsrealty.com/backoffice). Only addresses the website itself doesn't answer are sent on.
 */
final class BackOfficeLinks
{
    /** What brokers type to reach their back office; they land on its sign-in page. */
    public const ALIASES = ['backoffice', 'back-office', 'admin', 'office', 'login', 'signin', 'sign-in', 'dashboard'];

    /** Sign-in pages that come from the authentication package rather than our own routes. */
    private const AUTH = ['logout', 'forgot-password', 'reset-password', 'two-factor-challenge', 'user', 'email', 'register', 'invitations'];

    /** Never sent on: HQ's own pages, the API, health checks and system files. */
    private const NEVER = ['hq', 'api', 'up', '.well-known', 'manifest.webmanifest', 'watchtower', 'impersonate'];

    /** @var list<string>|null */
    private static ?array $segments = null;

    /** Where to send this request in the back office, or null when it isn't a back-office address. */
    public static function target(Request $request, Tenant $tenant): ?string
    {
        $path = trim($request->path(), '/');
        $first = explode('/', $path)[0];
        if ($first === '') {
            return null;
        }
        $base = 'https://'.self::officeHost($tenant);
        if (in_array($first, self::ALIASES, true) && ! str_contains($path, '/')) {
            return $base.'/login';
        }
        if (! in_array($first, self::segments(), true)) {
            return null;
        }

        return $base.'/'.$path.($request->getQueryString() !== null ? '?'.$request->getQueryString() : '');
    }

    /** The brokerage's back-office address (its primary back-office domain, else its standard one). */
    public static function officeHost(Tenant $tenant): string
    {
        return $tenant->primaryDomain()->host ?? Host::platformHostFor($tenant->slug);
    }

    /**
     * The first part of every back-office address (settings, contacts, invitations …).
     *
     * @return list<string>
     */
    public static function segments(): array
    {
        if (self::$segments === null) {
            $found = self::AUTH;
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if ($route->getDomain() !== null) {
                    continue;
                }
                $first = explode('/', $route->uri())[0];
                if ($first !== '' && ! str_starts_with($first, '{') && preg_match('/^[a-z0-9][a-z0-9._-]*$/', $first) === 1) {
                    $found[] = $first;
                }
            }
            self::$segments = array_values(array_diff(array_unique($found), self::NEVER));
        }

        return self::$segments;
    }
}
