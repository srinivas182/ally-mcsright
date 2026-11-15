<?php

declare(strict_types=1);

namespace Modules\Connect\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Connect\Calendars\CalendarApi;
use Modules\Connect\Health;
use Modules\Connect\Models\Connection;
use Modules\Connect\OAuth\OAuthFlow;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\NeedsReconnect;
use Throwable;

/** Puts a booking into the agent's own calendar (specs/connect/02). Never throws: the booking stands either way. */
final class AddCalendarEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $connectionId, public readonly string $title, public readonly string $start, public readonly string $end,
        public readonly string $details)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $c = Connection::query()->find($this->connectionId);
        $app = $c !== null ? app(AppCatalog::class)->find($c->app_key) : null;
        if ($c === null || $app === null) {
            return;
        }
        try {
            CalendarApi::add($c->app_key, app(OAuthFlow::class)->accessToken($c, $app), $this->title, CarbonImmutable::parse($this->start), CarbonImmutable::parse($this->end), $this->details);
            app(Health::class)->succeeded($c);
        } catch (NeedsReconnect) {
            // health already says "connect again"
        } catch (Throwable $e) {
            app(Health::class)->failed($c, 'Could not add a booking to the calendar: '.mb_substr($e->getMessage(), 0, 200));
        }
    }
}
