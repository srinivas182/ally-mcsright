<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** Sends a text message. Twilio when configured; otherwise logged (A2P 10DLC registration is a pre-start item). */
interface SmsSender
{
    public function send(string $to, string $message): void;
}
