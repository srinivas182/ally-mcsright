<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;

/**
 * HQ2: what each HQ staff member may use, by area of the HQ console.
 *
 * Every HQ page belongs to an area; looking needs hq.{area}.see and changing anything needs hq.{area}.change
 * (change includes see; named so they never clash with the older HQ gates such as hq.mls.manage). A few permissions can only be held by Super admin. Until HQ's roles are set up, HQ staff
 * keep full access (as before); once they are, someone with no role sees only Today.
 */
final class HqAccess
{
    /** @var array<string, array{0: string, 1: list<string>}> area => [label, page addresses] */
    public const AREAS = [
        'today' => ['Today', ['/hq/today', '/hq/dashboard']],
        'customers' => ['Customers', ['/hq/tenants', '/hq/signups', '/hq/go-live', '/hq/health-checks', '/hq/texting']],
        'support' => ['Support', ['/hq/support', '/hq/cards']],
        'billing' => ['Billing: packages, pricing, credits, referral programme', ['/hq/packages', '/hq/billing', '/hq/credits', '/hq/affiliates']],
        'payments' => ['Payments and payout rails', ['/hq/payments', '/hq/payout-rails']],
        'websites' => ['Websites and content', ['/hq/website', '/hq/content', '/hq/sites']],
        'mls' => ['MLS and IDX', ['/hq/mls', '/hq/idx']],
        'movewise' => ['Movewise', ['/hq/movewise']],
        'products' => ['Products: referral network, Ally Studio', ['/hq/network', '/hq/studio']],
        'ai' => ['AI', ['/hq/ai']],
        'connections' => ['Connections and outside-service keys', ['/hq/services', '/hq/messaging', '/hq/connect', '/hq/apps', '/hq/partners']],
        'system' => ['System', ['/hq/watchtower', '/hq/cells']],
        'settings' => ['Settings: HQ team, roles, security controls, feature switches', ['/users', '/hq/roles', '/hq/controls', '/hq/switches']],
    ];

    /** Signing in as a brokerage's user, and taking a brokerage's data out: given on their own, never by area. */
    public const SUPPORT_ACCESS = 'hq.support_access';

    public const DATA_EXPORT = 'hq.data.export';

    /** Only Super admin holds these (money rails and keys, who can do what, taking a brokerage's data out). */
    public const SUPER_ONLY = ['hq.payments.change', 'hq.connections.change', 'hq.settings.change', self::DATA_EXPORT];

    /**
     * Every HQ permission, for the permission registry: key => [label].
     *
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function permissions(): array
    {
        $out = [];
        foreach (self::AREAS as $area => [$label]) {
            $out["hq.{$area}.see"] = ["See {$label}", false, false];
            $out["hq.{$area}.change"] = ["Change {$label}", false, in_array("hq.{$area}.change", self::SUPER_ONLY, true)];
        }
        $out[self::SUPPORT_ACCESS] = ['Sign in as a brokerage\'s user to help them (always recorded)', false, true];
        $out[self::DATA_EXPORT] = ['Back up, export or restore a brokerage\'s data', false, true];

        return $out;
    }

    /** The permission an HQ request needs, or null when it is not an HQ page. */
    public static function required(string $path, string $method): ?string
    {
        $path = '/'.ltrim($path, '/');
        $change = ! in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
        if (preg_match('#^/hq/tenants/[^/]+/impersonate$#', $path) === 1) {
            return self::SUPPORT_ACCESS;
        }
        if (preg_match('#^/hq/tenants/[^/]+/data(/|$)#', $path) === 1) {
            return self::DATA_EXPORT;
        }
        $best = null;
        $length = 0;
        foreach (self::AREAS as $area => [, $prefixes]) {
            foreach ($prefixes as $prefix) {
                if (($path === $prefix || str_starts_with($path, $prefix.'/')) && strlen($prefix) > $length) {
                    $best = $area;
                    $length = strlen($prefix);
                }
            }
        }
        if ($best === null) {
            return str_starts_with($path, '/hq/') ? 'hq.settings.change' : null; // an HQ page not placed yet: Super admin only
        }

        return "hq.{$best}.".($change ? 'change' : 'see');
    }

    /** Whether HQ has set up its roles (until then HQ staff keep full access). */
    public static function rolesOn(): bool
    {
        return DB::connection(Connections::cell())->table('roles')->where('template_key', HqRoles::SUPER)->whereNull('deleted_at')->exists();
    }

    public static function allows(User $user, string $permission): bool
    {
        if ($user->kind !== UserKind::HqStaff) {
            return false;
        }
        if (! self::rolesOn()) {
            return true;
        }
        if ($permission === 'hq.today.see') {
            return true; // everyone in HQ sees what is waiting
        }
        $grants = app(Permissions::class)->grants($user);
        if (array_key_exists($permission, $grants)) {
            return true;
        }

        return str_ends_with($permission, '.see') && array_key_exists(substr($permission, 0, -4).'.change', $grants);
    }

    /** For the menu: may this person open this HQ page? */
    public static function canSee(User $user, string $href): bool
    {
        $needed = self::required($href, 'GET');

        return $needed === null || self::allows($user, $needed);
    }
}
