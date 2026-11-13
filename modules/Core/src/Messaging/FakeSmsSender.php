<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** Test double: keeps every message. */
final class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }
}
