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

    /** @var list<array{from: string, to: string, message: string}> */
    public array $delivered = [];

    public bool $fail = false;

    public function deliver(string $from, string $to, string $message): string
    {
        if ($this->fail) {
            throw new \RuntimeException('The text message could not be sent (500).');
        }
        $this->delivered[] = ['from' => $from, 'to' => $to, 'message' => $message];

        return 'SM'.str_pad((string) count($this->delivered), 32, '0', STR_PAD_LEFT);
    }
}
