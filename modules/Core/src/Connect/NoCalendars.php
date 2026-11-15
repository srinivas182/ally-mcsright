<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

use Carbon\CarbonImmutable;

/** The default: no calendars connected. */
final class NoCalendars implements Calendars
{
    public function busy(int $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function addEvent(int $userId, string $title, CarbonImmutable $start, CarbonImmutable $end, string $details): void {}

    public function connected(int $userId): ?array
    {
        return null;
    }
}
