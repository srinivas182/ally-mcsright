<?php

declare(strict_types=1);

namespace Modules\Connect\Calendars;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Modules\Connect\AppRegistry;
use Modules\Connect\Health;
use Modules\Connect\Jobs\AddCalendarEvent;
use Modules\Connect\Models\Connection;
use Modules\Connect\OAuth\OAuthFlow;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\Calendars;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * A person's connected calendar (specs/connect/02). Busy times are read for the days asked and kept for 5 minutes.
 * If the calendar can't be reached, booking carries on from RightAlly tasks only (it never blocks a visitor), and
 * the connection's health records the failure.
 */
final class ConnectCalendars implements Calendars
{
    public const APPS = ['google_calendar', 'microsoft_calendar'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function busy(int $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $c = $this->connection($userId);
        if ($c === null) {
            return [];
        }
        $key = 'connect-busy:'.$this->tenants->id().':'.$c->id.':'.$from->utc()->format('YmdH').':'.$to->utc()->format('YmdH');
        /** @var list<array{0: string, 1: string}>|null $cached */
        $cached = Cache::get($key);
        if ($cached === null) {
            try {
                $app = app(AppCatalog::class)->find($c->app_key);
                $token = app(OAuthFlow::class)->accessToken($c, $app ?? abort(500));
                $cached = array_map(fn (array $b): array => [$b[0]->toIso8601String(), $b[1]->toIso8601String()], CalendarApi::busy($c->app_key, $token, $from, $to));
                Cache::put($key, $cached, now()->addMinutes(5));
                app(Health::class)->succeeded($c);
                $c->forceFill(['last_synced_at' => now()])->save();
            } catch (NeedsReconnect) {
                return [];
            } catch (Throwable $e) {
                app(Health::class)->failed($c, 'Could not read the calendar: '.mb_substr($e->getMessage(), 0, 200));

                return [];
            }
        }

        return array_map(fn (array $b): array => [CarbonImmutable::parse($b[0]), CarbonImmutable::parse($b[1])], $cached);
    }

    public function addEvent(int $userId, string $title, CarbonImmutable $start, CarbonImmutable $end, string $details): void
    {
        $c = $this->connection($userId);
        if ($c !== null) {
            AddCalendarEvent::dispatch($c->id, mb_substr($title, 0, 200), $start->utc()->toIso8601String(), $end->utc()->toIso8601String(), mb_substr($details, 0, 2000));
        }
    }

    public function connected(int $userId): ?array
    {
        $c = Connection::query()->whereIn('app_key', self::APPS)->where('owner_user_id', $userId)->latest('id')->first();
        $app = $c !== null ? app(AppCatalog::class)->find($c->app_key) : null;

        return $c !== null && $app !== null ? ['app' => $c->app_key, 'name' => $app->name, 'account' => $c->label, 'status' => $c->status] : null;
    }

    private function connection(int $userId): ?Connection
    {
        $c = Connection::query()->whereIn('app_key', self::APPS)->where('owner_user_id', $userId)->whereIn('status', ['connected', 'error'])->latest('id')->first();
        $app = $c !== null ? app(AppCatalog::class)->find($c->app_key) : null;

        return $app !== null && app(AppRegistry::class)->enabled($app) ? $c : null; // switched off by HQ: paused
    }
}
