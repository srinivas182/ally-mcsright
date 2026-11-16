<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Video;

/** A stand-in for tests: records what it was given and writes a small file. */
final class FakeVideoMaker implements VideoMaker
{
    /** @var list<array{frames: int, seconds: list<float>, music: string|null, size: string, voice: string|null}> */
    public array $made = [];

    public bool $fail = false;

    public function make(array $frames, array $seconds, ?string $music, string $out, int $width = 1080, int $height = 1920, ?string $voice = null): string
    {
        if ($this->fail) {
            throw new \RuntimeException('ffmpeg failed');
        }
        $this->made[] = ['frames' => count($frames), 'seconds' => $seconds, 'music' => $music, 'size' => $width.'x'.$height, 'voice' => $voice !== null && is_file($voice) ? (string) file_get_contents($voice) : null];
        file_put_contents($out, str_repeat('MP4', 400));

        return $out;
    }
}
