<?php

declare(strict_types=1);

namespace Modules\Crm\Events;

/**
 * Someone texted an opt-out or opt-in keyword (STOP, START…), whether or not they are a contact, so other modules
 * that text people outside the CRM (recruiting) can honour it too.
 */
final readonly class SmsKeywordReceived
{
    public function __construct(public string $from, public string $keyword, public bool $stop) {}
}
