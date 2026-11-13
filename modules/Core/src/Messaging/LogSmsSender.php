<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** Default until Twilio is configured: the message is written to the log (never in production). */
final class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        logger()->info('SMS (not sent: Twilio not configured)', ['to' => substr($to, 0, -4).'****']);
    }
}
