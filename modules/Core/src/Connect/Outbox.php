<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/**
 * Events other apps can receive (outgoing webhooks, Zapier, Make; specs/connect/01). Modules emit them; the
 * Connect module delivers them to every endpoint that asked for that event. Without Connect, nothing is sent.
 */
interface Outbox
{
    public const EVENTS = [
        'lead.created' => 'A new lead arrived (any source)',
        'contact.created' => 'A contact was added',
        'contact.stage_changed' => 'A contact moved to another stage',
        'booking.created' => 'A visitor booked a call, showing or listing appointment',
        'task.completed' => 'A task was completed',
    ];

    /** @param array<string, mixed> $data */
    public function emit(string $event, array $data): void;
}
