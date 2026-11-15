<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

use Carbon\CarbonImmutable;

/**
 * A person's own calendar (Google or Microsoft 365, specs/connect/02): when they are busy, and adding a booking to
 * it. Booking (CRM) uses this; the Connect module supplies it. Without a connected calendar there are no busy times
 * and nothing is added, and booking works from RightAlly tasks alone.
 */
interface Calendars
{
    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> busy ranges (UTC) */
    public function busy(int $userId, CarbonImmutable $from, CarbonImmutable $to): array;

    /** Queues the booking into their calendar, when one is connected. */
    public function addEvent(int $userId, string $title, CarbonImmutable $start, CarbonImmutable $end, string $details): void;

    /** @return array{app: string, name: string, account: string|null, status: string}|null their connected calendar */
    public function connected(int $userId): ?array;
}
