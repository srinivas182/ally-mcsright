<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** Places an automated voice call that reads a short message aloud. */
interface VoiceCaller
{
    public function call(string $to, string $message): void;

    /**
     * Reads the message and asks the person to press 1; when they do, Twilio fetches TwiML from $onPress
     * (which connects them to someone). Voicemail cannot press 1, so a missed call never connects anyone.
     */
    public function connect(string $to, string $message, string $onPress): void;
}
