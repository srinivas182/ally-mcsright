<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** For tests: remembers the calls placed. */
final class FakeVoiceCaller implements VoiceCaller
{
    /** @var list<array{to: string, message: string}> */
    public array $calls = [];

    public function call(string $to, string $message): string
    {
        $this->calls[] = ['to' => $to, 'message' => $message];

        return 'CA'.str_pad((string) (count($this->calls) + count($this->connects)), 32, '0', STR_PAD_LEFT);
    }

    /** @var list<array{to: string, message: string, on_press: string}> */
    public array $connects = [];

    public function connect(string $to, string $message, string $onPress, string $voice = 'Polly.Joanna'): string
    {
        $this->connects[] = ['to' => $to, 'message' => $message, 'on_press' => $onPress, 'voice' => $voice];

        return 'CA'.str_pad((string) (count($this->calls) + count($this->connects)), 32, '0', STR_PAD_LEFT);
    }
}
