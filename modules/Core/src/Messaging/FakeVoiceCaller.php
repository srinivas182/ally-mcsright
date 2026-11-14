<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** For tests: remembers the calls placed. */
final class FakeVoiceCaller implements VoiceCaller
{
    /** @var list<array{to: string, message: string}> */
    public array $calls = [];

    public function call(string $to, string $message): void
    {
        $this->calls[] = ['to' => $to, 'message' => $message];
    }
}
