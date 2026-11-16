<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Voice;

/** A stand-in for tests: remembers what it was asked and returns a small MP3-like body. */
final class FakeVoice implements VoiceVendor
{
    /** @var list<array{voice: string, text: string}> */
    public array $spoken = [];

    /** @var list<array{label: string, files: int}> */
    public array $cloned = [];

    /** @var list<string> */
    public array $deleted = [];

    public bool $fail = false;

    public function stock(): array
    {
        return [['id' => 'stock-rachel', 'name' => 'Rachel', 'accent' => 'Warm, American'], ['id' => 'stock-adam', 'name' => 'Adam', 'accent' => 'Deep, American']];
    }

    public function cloneVoice(string $label, array $files): string
    {
        $this->cloned[] = ['label' => $label, 'files' => count($files)];

        return 'own-voice-'.count($this->cloned);
    }

    public function speak(string $voiceId, string $text): string
    {
        if ($this->fail) {
            throw new \RuntimeException('Voice service: down');
        }
        $this->spoken[] = ['voice' => $voiceId, 'text' => $text];

        return 'ID3'.str_repeat("\0", 800);
    }

    public function delete(string $voiceId): void
    {
        $this->deleted[] = $voiceId;
    }
}
