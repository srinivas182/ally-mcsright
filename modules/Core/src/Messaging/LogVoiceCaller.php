<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** Used until HQ sets up calling: the call is written to the log instead. */
final class LogVoiceCaller implements VoiceCaller
{
    public function call(string $to, string $message): void
    {
        logger()->info('Voice call (not placed: no provider configured)', ['to' => substr($to, -4), 'message' => $message]);
    }
}
