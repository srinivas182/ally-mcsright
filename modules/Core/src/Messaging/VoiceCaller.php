<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** Places an automated voice call that reads a short message aloud. */
interface VoiceCaller
{
    public function call(string $to, string $message): void;
}
